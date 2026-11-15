<?php
namespace Funseco\Pay2Play\VotePay;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Funseco\PlayerAccounts;
use Funseco\Core\Services\Funseco\ServerAccount;
use Funseco\Core\SharedKernel\Billing\Billing;
use Funseco\Core\SharedKernel\Billing\BillResult;
use Funseco\Core\SharedKernel\Events\KarmaVoteCast;
use Funseco\Core\SharedKernel\Events\MapEnded;
use Funseco\Core\SharedKernel\Events\MapStarted;
use Funseco\Core\SharedKernel\Events\PlayerConnected;
use Funseco\Core\SharedKernel\Events\PlayerDisconnected;

/**
 * Coppers for karma votes: 15 per map, +5 from a 3-map streak, +10 from 5. A player is paid once
 * per map (table vote_rewards_final). The payout is fire and forget: Billing logs failures.
 */
class VotePay
{
    private const ALREADY = '{#server}> {#error}You have already been paid for this map!';

    /** @var array<string, true> "login_mapUid" paid (or being paid) this map */
    private array $paid = [];

    /** @var array<string, int> login => consecutive voted maps */
    private array $streak = [];

    /** @var string[] last maps, newest first */
    private array $history = [];

    public function onVote(KarmaVoteCast $e): void
    {
        $player = $e->player;
        $uid = $e->mapUid;
        if ($uid === '') {
            return;
        }
        $key = $player->login.'_'.$uid;
        if (isset($this->paid[$key])) {
            $this->tell($player, self::ALREADY);

            return;
        }
        if (! app(PlayerAccounts::class)->isUnited($player)) {
            return;
        }
        if (VoteReward::where('login', $player->login)->where('map_uid', $uid)->exists()) {
            $this->paid[$key] = true;
            $this->tell($player, self::ALREADY);

            return;
        }

        $streak = ($this->streak[$player->login] ?? 0) + 1;
        $bonus = $streak >= 5 ? (int) config('pay2play.votepay.streak5_bonus')
            : ($streak >= 3 ? (int) config('pay2play.votepay.streak3_bonus') : 0);
        $total = (int) config('pay2play.votepay.base') + $bonus;

        $this->paid[$key] = true;   // held while the balance is asked: a second vote waits for the answer
        app(ServerAccount::class)->united(function (bool $united) use ($player, $uid, $key, $streak, $bonus, $total) {
            if (! $united) {
                unset($this->paid[$key]);

                return;
            }
            \Server::query()->GetServerCoppers(function ($result) use ($player, $uid, $key, $streak, $bonus, $total) {
                $coppers = (int) ($result[0] ?? 0);
                if ($coppers <= (int) config('pay2play.votepay.min_server_coppers')) {
                    unset($this->paid[$key]);
                    Log::warning("[VotePay] WARNING: Server coppers low ({$coppers})");

                    return;
                }
                app(Billing::class)->pay($player->login, $total, 'Vote Reward')->then(
                    function (BillResult $result) use ($player, $uid, $key, $streak, $bonus, $total) {
                        if (! $result->paid()) {
                            // no row: the next vote retries
                            unset($this->paid[$key]);
                            Log::warning("[VotePay] payout failed for {$player->login} ({$total} coppers, {$result->state}: {$result->stateName})");

                            return;
                        }
                        try {
                            VoteReward::create(['login' => $player->login, 'map_uid' => $uid, 'date_paid' => now(), 'amount_paid' => $total]);
                        } catch (QueryException) {
                            return;   // paid meanwhile: the key stays set
                        }
                        $this->streak[$player->login] = $streak;
                        $this->tell($player, $bonus > 0
                            ? "{#server}>> {#highlite}Vote Bonus! {#server}You received {$total} coppers {#highlite}(+{$bonus} streak bonus!)"
                            : "{#server}>> {#highlite}Vote Bonus! {#server}You received {$total} coppers.");
                        Log::info("[VotePay] {$player->login} received {$total} coppers (streak: {$streak})");
                    }
                );
            });
        });
    }

    public function mapStarted(MapStarted $e): void
    {
        $uid = (string) $e->map->uid;
        if (($this->history[0] ?? null) !== $uid) {
            array_unshift($this->history, $uid);
            $this->history = array_slice($this->history, 0, (int) config('pay2play.votepay.history', 10));
        }
    }

    /** Map over: the session cache goes, and everybody who skipped this map's vote loses the streak. */
    public function mapEnded(MapEnded $e): void
    {
        if ($e->wasWarmup) {
            return;
        }
        $uid = (string) $e->map->uid;
        $this->paid = [];
        $voted = VoteReward::where('map_uid', $uid)->pluck('login')->all();
        foreach (CurrentPlayers::all() as $player) {
            if (($this->streak[$player->login] ?? 0) > 0 && ! in_array($player->login, $voted, true)) {
                $this->streak[$player->login] = 0;
                Log::info("[VotePay] {$player->login} streak reset (skipped vote)");
            }
        }
    }

    /**
     * Streak of a connecting player: consecutive voted maps, newest first. The running map is
     * skipped while unvoted, so a reconnect mid-map keeps the streak.
     */
    public function connected(PlayerConnected $e): void
    {
        $login = $e->player->login;
        $voted = VoteReward::where('login', $login)->whereIn('map_uid', $this->history)->pluck('map_uid')->all();
        $streak = 0;
        foreach ($this->history as $i => $uid) {
            if (in_array($uid, $voted, true)) {
                $streak++;
            } elseif ($i > 0) {
                break;
            }
        }
        $this->streak[$login] = $streak;
    }

    public function disconnected(PlayerDisconnected $e): void
    {
        unset($this->streak[$e->player->login]);
    }

    public function streakOf(string $login): int
    {
        return $this->streak[$login] ?? 0;
    }

    private function tell(Player $player, string $message): void
    {
        \Server::query()->ChatSendServerMessageToLogin(format_colors($message), $player->login);
    }
}
