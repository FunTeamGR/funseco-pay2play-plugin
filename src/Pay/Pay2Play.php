<?php
namespace Funseco\Pay2Play\Pay;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Funseco\Core\Models\Player;
use Funseco\Core\Services\Billing\BillingFlow;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Cache\ServerInfo;
use Funseco\Core\Services\Replays\ReplayCounter;
use Funseco\Core\SharedKernel\Billing\Billing;
use Funseco\Core\Services\ManiaLink\Facades\ManiaLink;
use Funseco\Core\SharedKernel\Billing\BillResult;
use Funseco\Core\SharedKernel\Events\ExtendRequested;
use Funseco\Core\SharedKernel\Events\MapEnded;
use Funseco\Core\SharedKernel\Events\MapStarted;
use Funseco\Core\SharedKernel\Events\PlayerConnected;
use Funseco\Core\SharedKernel\Events\ReplayRequested;
use Funseco\Core\SharedKernel\TimeLimit;
use Funseco\Core\SharedKernel\UI\Grid\Overflow;
use Funseco\Core\SharedKernel\UI\WidgetRegistry;

/**
 * Pay for a replay/time extend or for a skip (XAseco pay2play). One bill per button at a time
 * (a second click while one is open is ignored), so the replay maximum cannot be overshot.
 * A payment that arrives after its bill timed out never acts (the seam only logs it).
 */
class Pay2Play
{
    private bool $scoreScreen = false;

    private bool $replayMax = false;
    private bool $replaySuccess = false;

    private bool $skipSuccess = false;
    private ?int $skipAt = null;
    /** Unix time the map's time-attack limit ends; null when unknown. */
    private ?int $limitEnds = null;

    /** @var array<string, bool> */
    private array $billing = [self::REPLAY => false, self::SKIP => false];

    /** @var array<int, array{Command: string, Ratio: float}> call vote ratios before we switched them off */
    private array $oldRatios = [];

    private const REPLAY = Pay2PlayWidget::REPLAY;
    private const SKIP = Pay2PlayWidget::SKIP;

    public static function register(WidgetRegistry $registry): void
    {
        $registry->widget(self::REPLAY, 'buttons', 60, width: Pay2PlayWidget::WIDTH, height: Pay2PlayWidget::HEIGHT, overflow: Overflow::Wrap);
        $registry->widget(self::SKIP, 'buttons', 70, width: Pay2PlayWidget::WIDTH, height: Pay2PlayWidget::HEIGHT, overflow: Overflow::Wrap);
    }

    private function enabled(string $kind): bool
    {
        return (bool) config('pay2play.'.($kind === self::REPLAY ? 'replay' : 'skip').'.enabled');
    }

    private function cfg(string $kind, string $key, mixed $default = null): mixed
    {
        return config('pay2play.'.($kind === self::REPLAY ? 'replay' : 'skip').'.'.$key, $default);
    }

    private function now(): int
    {
        return Carbon::now()->getTimestamp();
    }

    // ---- widgets -------------------------------------------------------------------------

    private function state(string $kind): string
    {
        if ($kind === self::REPLAY) {
            return $this->replayMax ? 'max' : ($this->replaySuccess ? 'success' : 'idle');
        }

        return $this->skipSuccess ? 'success' : 'idle';
    }

    private function xml(string $kind): string
    {
        $placement = $this->enabled($kind) && ! $this->scoreScreen ? app(WidgetRegistry::class)->placement($kind) : null;
        if (! $placement) {
            return Pay2PlayWidget::empty($kind);
        }

        return Pay2PlayWidget::xml($kind, $this->state($kind), $placement, (int) $this->cfg($kind, 'cost'), (bool) $this->cfg($kind, 'blink'));
    }

    private function drawAll(string $kind): void
    {
        if ($this->enabled($kind)) {
            ManiaLink::displayAll($this->xml($kind));
        }
    }

    /** After a restart the old buttons are still on screen (maybe at an old position): draw them again. */
    public function startup(): void
    {
        foreach (CurrentPlayers::all() as $player) {
            $this->showTo($player);
        }
    }

    public function showTo(Player $player): void
    {
        foreach ([self::REPLAY, self::SKIP] as $kind) {
            if ($this->enabled($kind)) {
                ManiaLink::displayLogin($player, $this->xml($kind));
            }
        }
    }

    // ---- events --------------------------------------------------------------------------

    public function connected(PlayerConnected $e): void
    {
        if (! $this->scoreScreen) {
            $this->showTo($e->player);
        }
    }

    public function mapStarted(MapStarted $e): void
    {
        $this->scoreScreen = false;
        $counter = app(ReplayCounter::class);
        $counter->mapStarted((string) $e->map->uid);
        $this->replayMax = $counter->paidMaxed();
        $this->replaySuccess = false;
        $this->skipSuccess = false;
        $this->skipAt = null;
        $this->limitEnds = null;

        \Server::query()->GetCurrentGameInfo(1, function ($result) {
            $ms = (int) ($result[0]['TimeAttackLimit'] ?? 0);
            $this->limitEnds = $ms > 0 && (int) ($result[0]['GameMode'] ?? 1) === 1 ? $this->now() + intdiv($ms, 1000) : null;
        });

        $this->drawAll(self::REPLAY);
        $this->drawAll(self::SKIP);
    }

    public function mapEnded(MapEnded $e): void
    {
        $this->scoreScreen = true;
        app(ReplayCounter::class)->mapEnded($e->restart);
        $this->replaySuccess = false;
        $this->skipSuccess = false;
        $this->skipAt = null;   // a skip scheduled for a map that already ended would hit the next one
        foreach ([self::REPLAY, self::SKIP] as $kind) {
            if ($this->enabled($kind)) {
                ManiaLink::displayAll(Pay2PlayWidget::empty($kind));
            }
        }
    }

    public function tick(): void
    {
        if ($this->skipAt !== null && $this->now() >= $this->skipAt) {
            $this->skipAt = null;
            // Cup mode: keep the scores
            ServerInfo::getGameMode() === 5 ? \Server::query()->NextChallenge(true) : \Server::query()->NextChallenge();
        }
    }

    // ---- clicks --------------------------------------------------------------------------

    public function click(Player $player, string $kind): void
    {
        if (! $this->enabled($kind) || $this->billing[$kind]) {
            return;
        }
        if ($kind === self::REPLAY && app(ReplayCounter::class)->paidMaxed()) {
            $this->replayMax = true;
            $this->raw($player, $this->maxMessage());

            return;
        }
        // Already bought for this map (the widget shows no button then; a stale click must not pay again).
        if ($kind === self::REPLAY ? ($this->replayMax || $this->replaySuccess) : ($this->skipSuccess || $this->skipAt !== null)) {
            return;
        }
        // One open bill per button, held from the click: every async step below can still be in flight
        // when a second click arrives. Each path out of here clears it.
        $this->billing[$kind] = true;
        $uid = (string) CurrentMap::get()?->uid;
        $kind === self::REPLAY ? $this->clickReplay($player, $uid) : $this->clickSkip($player, $uid);
    }

    private function release(string $kind): void
    {
        $this->billing[$kind] = false;
    }

    private function raw(Player $player, string $message): void
    {
        \Server::query()->ChatSendServerMessageToLogin($message, $player->login);
    }

    private function clickReplay(Player $player, string $uid): void
    {
        \Server::query()->GetNextChallengeInfo(function ($result) use ($player, $uid) {
            $next = (string) ($result[0]['FileName'] ?? '');
            if ($next !== '' && $next === (string) CurrentMap::get()?->file_name) {
                $this->release(self::REPLAY);
                $this->raw($player, '>$f00 This track is already being replayed');

                return;
            }
            $cost = (int) $this->cfg(self::REPLAY, 'cost');
            $this->bill($player, self::REPLAY, $cost, "You need to pay {$cost} coppers to extend this track", fn (BillResult $r) => $this->replayPaid($player, $cost, $uid, $r));
        });
    }

    private function clickSkip(Player $player, string $uid): void
    {
        $delay = (int) $this->cfg(self::SKIP, 'delay');
        $left = app(TimeLimit::class)->secondsLeft();
        $ends = max($this->limitEnds ?? 0, $left !== null ? $this->now() + $left : 0);
        // Unknown time (no limit known): allowed. Else the map must still run when the delay is over.
        if (($this->limitEnds !== null || $left !== null) && $ends < $this->now() + $delay + 10) {
            $this->release(self::SKIP);
            $this->raw($player, '>$f00 This track will end before your action can be completed, please be patient.');

            return;
        }
        $cost = (int) $this->cfg(self::SKIP, 'cost');
        $this->bill($player, self::SKIP, $cost, "You need to pay {$cost} coppers to skip this track", fn (BillResult $r) => $this->skipPaid($player, $cost, $uid, $r));
    }

    private function bill(Player $player, string $kind, int $cost, string $label, \Closure $paid): void
    {
        app(BillingFlow::class)->charge(
            $player, $cost, $label,
            $paid,
            null,
            null,
            fn () => $this->release($kind),
        );
    }

    /** The map changed between click and payment: nothing is done, the payer is told and the owner can refund. */
    private function stale(Player $player, string $what, int $cost, string $uid, BillResult $r): bool
    {
        if ($uid === (string) CurrentMap::get()?->uid) {
            return false;
        }
        $this->orphan($player, $what, $cost, $r, 'the map changed');

        return true;
    }

    private function orphan(Player $player, string $what, int $cost, BillResult $r, string $why): void
    {
        // The bill resolved Paid exactly once, so this runs once per payment: pay it back in full.
        Log::warning("Pay2Play {$what} paid but not done ({$why}): {$player->login} paid {$cost} coppers (BillId {$r->billId}, TxId {$r->transactionId}); refunding");
        $this->raw($player, format_colors('{#server}> {#error}Your payment of '.$cost.' coppers could not be used ('.$why.'). It is being refunded.'));
        app(Billing::class)->refund($player->login, $cost, "Refund {$cost} coppers: {$what} could not be done ({$why})")
            ->then(function (BillResult $refund) use ($player, $cost, $r, $what) {
                if (! $refund->paid()) {
                    Log::error("Pay2Play REFUND FAILED: \"{$player->login}\" is owed {$cost} coppers for {$what} (BillId {$r->billId}; {$refund->state}: {$refund->stateName}); pay by hand with /admin pay");
                    $this->raw($player, format_colors('{#server}> {#error}Your refund of '.$cost.' coppers failed. Please contact an admin!'));
                }
            });
    }

    private function maxMessage(): string
    {
        return format_colors('{#server}> {#error}The maximum of paid replays/extensions for this track is reached.');
    }

    /** Pay the coppers back to the payer. The only place that knows how (swap for Billing::refund when it exists). */
    private function refund(Player $player, int $cost, string $what, BillResult $r): void
    {
        $failed = function (string $why) use ($player, $cost, $what, $r) {
            Log::error("Pay2Play {$what} refund of {$cost} coppers to {$player->login} failed ({$why}; BillId {$r->billId}); refund by hand with /admin pay");
            $this->raw($player, format_colors('{#server}> {#error}Your refund of '.$cost.' coppers failed. Please ask an admin.'));
        };
        try {
            app(Billing::class)->refund($player->login, $cost, 'Refund: paid '.$what.' limit reached')->then(
                function (BillResult $payout) use ($player, $cost, $what, $r, $failed) {
                    $payout->paid()
                        ? Log::info("Pay2Play {$what} refunded {$cost} coppers to {$player->login} (BillId {$r->billId})")
                        : $failed($payout->stateName);
                },
                fn ($e) => $failed($e instanceof \Throwable ? $e->getMessage() : (string) $e),
            );
        } catch (\Throwable $e) {
            $failed($e->getMessage());
        }
    }

    private function replayPaid(Player $player, int $cost, string $uid, BillResult $r): void
    {
        if ($this->stale($player, 'replay', $cost, $uid, $r)) {
            return;
        }
        if (app(ReplayCounter::class)->paidMaxed()) {
            // The cap was filled while this payment was on its way: give the coppers back.
            $this->replayMax = true;
            $this->refund($player, $cost, 'replay', $r);
            $this->raw($player, $this->maxMessage().' Your '.$cost.' coppers are being refunded.');

            return;
        }
        $extended = false;
        if (! $this->scoreScreen) {
            if ($this->cfg(self::REPLAY, 'extend', true)) {
                event($extend = new ExtendRequested($player));
                $extended = $extend->addedSeconds !== null;
            }
            if (! $extended) {
                event($replay = new ReplayRequested($player, 'Pay2Play'));
                if ($replay->queued === false) {
                    $this->orphan($player, 'replay', $cost, $r, 'the track is already queued for a replay');

                    return;
                }
                if ($replay->queued === null && ($map = CurrentMap::get())) {
                    \Server::query()->ChooseNextChallenge($map->file_name);   // no jukebox: the server queues it
                }
            }
        } else {
            \Server::query()->ChallengeRestart();
        }
        \Server::query()->ChatSendServerMessage(format_colors(
            '$s$f90Player $z$s'.$player->nickname.'$z$s$f90  pays '.$cost.' coppers and extends track time!'));
        Log::info("Player {$player->login} paid {$cost} coppers to replay the current track");

        $counter = app(ReplayCounter::class);
        $counter->addPaid();
        if (! $extended) {
            $counter->keepForReplay((string) CurrentMap::get()?->uid);
        }
        $this->replayMax = $counter->paidMaxed();
        $this->replaySuccess = true;
        if (! $this->scoreScreen) {
            $this->drawAll(self::REPLAY);
        }
    }

    private function skipPaid(Player $player, int $cost, string $uid, BillResult $r): void
    {
        if ($this->stale($player, 'skip', $cost, $uid, $r)) {
            return;
        }
        if ($this->scoreScreen) {
            $this->orphan($player, 'skip', $cost, $r, 'the map already ended');

            return;
        }
        $this->skipAt = $this->now() + (int) $this->cfg(self::SKIP, 'delay');
        \Server::query()->ChatSendServerMessage(format_colors(
            '$s$f90Player $z$s'.$player->nickname.'$z$s$f90  pays '.$cost.' coppers. Track will be skipped shortly!'));
        Log::info("Player {$player->login} paid {$cost} coppers to skip the current track");
        $this->skipSuccess = true;
        $this->drawAll(self::SKIP);
    }

    // ---- call votes ----------------------------------------------------------------------

    /** Switch the server's own replay/skip call votes off for the enabled features. */
    public function disableCallVotes(): void
    {
        $commands = [];
        if ($this->enabled(self::REPLAY) && $this->cfg(self::REPLAY, 'disable_callvote')) {
            $commands[] = 'ChallengeRestart';
        }
        if ($this->enabled(self::SKIP) && $this->cfg(self::SKIP, 'disable_callvote')) {
            $commands[] = 'NextChallenge';
        }
        if (! $commands || $this->oldRatios) {
            return;   // nothing to do, or already switched off (a reconnect would save the -1)
        }
        \Server::query()->GetCallVoteRatios(function ($result) use ($commands) {
            foreach ((array) ($result[0] ?? []) as $ratio) {
                if (in_array($ratio['Command'] ?? '', $commands, true)) {
                    $this->oldRatios[] = ['Command' => $ratio['Command'], 'Ratio' => (float) $ratio['Ratio']];
                }
            }
            \Server::query()->SetCallVoteRatios(array_map(fn ($c) => ['Command' => $c, 'Ratio' => -1.0], $commands));
        });
    }

    public function restoreCallVotes(): void
    {
        if ($this->oldRatios) {
            \Server::query()->SetCallVoteRatios($this->oldRatios);
            $this->oldRatios = [];
        }
    }
}
