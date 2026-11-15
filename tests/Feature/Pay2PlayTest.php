<?php
namespace Funseco\Pay2Play\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Funseco\Core\Testing\FakesServer;
use Funseco\Pay2Play\Tests\TestCase;
use Funseco\Core\Models\Map;
use Funseco\Core\Models\Player;
use Funseco\Core\Servers\TmForever\Callbacks\BillUpdated as BillCallback;
use Funseco\Core\Servers\TmForever\Values\ChallengeInfo;
use Funseco\Core\Servers\TmForever\Values\PlayerRankingsCollection;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\ManiaLink\ManiaLinkService;
use Funseco\Core\SharedKernel\Events\EverySecondEvent;
use Funseco\Core\SharedKernel\Events\ExtendRequested;
use Funseco\Core\SharedKernel\Events\MapEnded;
use Funseco\Core\SharedKernel\Events\MapStarted;
use Funseco\Core\SharedKernel\Events\ReplayRequested;
use Funseco\Pay2Play\Pay\Pay2Play;

class Pay2PlayTest extends TestCase
{
    use FakesServer;
    use RefreshDatabase;

    private Player $alice;
    private Map $map;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeServer();
        state()->flush();
        state()->forever('funseco:server_united', true);
        state()->forever('funseco:player_united:alice', true);
        Carbon::setTestNow('2026-10-28 12:00:00');
        $this->responses['SendBill'] = 55;
        $this->responses['GetNextChallengeInfo'] = ['FileName' => 'Challenges/Other.Challenge.Gbx'];
        $this->responses['GetCurrentGameInfo'] = ['GameMode' => 1, 'TimeAttackLimit' => 300000];
        config([
            'pay2play.replay.enabled' => true, 'pay2play.replay.cost' => 120,
            'pay2play.skip.enabled' => true, 'pay2play.skip.cost' => 80, 'pay2play.skip.delay' => 10,
        ]);

        CurrentPlayers::clear();
        $this->alice = Player::factory()->create(['login' => 'alice', 'nickname' => 'Alice']);
        CurrentPlayers::add($this->alice);
        $this->map = Map::factory()->create(['uid' => 'm1', 'file_name' => 'Challenges/Speed.Challenge.Gbx']);
        CurrentMap::set($this->map);
        $this->p2p()->mapStarted(new MapStarted($this->map, false));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function p2p(): Pay2Play
    {
        return app(Pay2Play::class);
    }

    private function click(string $name): void
    {
        $links = app(ManiaLinkService::class);
        $links->dispatchAnswer($this->alice, $links->getAction($name));
    }

    private function widgets(): string
    {
        return implode("\n", array_map(fn ($a) => $a[0], $this->sent('SendDisplayManialinkPage')));
    }

    private function bill(int $state, string $name = ''): void
    {
        event(new BillCallback(55, $state, $name, 3));
    }

    private function tick(int $seconds): void
    {
        for ($i = 0; $i < $seconds; $i++) {
            Carbon::setTestNow(Carbon::now()->addSecond());
            $this->p2p()->tick();
            event(new EverySecondEvent);
        }
    }

    public function test_idle_widgets_show_the_cost_and_a_click_action(): void
    {
        $xml = $this->widgets();
        $this->assertStringContainsString('PAY 120', $xml);
        $this->assertStringContainsString('TIME EXTEND', $xml);
        $this->assertStringContainsString('PAY 80', $xml);
        $links = app(ManiaLinkService::class);
        $this->assertStringContainsString('action="'.$links->getAction('pay2play_replay').'"', $xml);
        $this->assertStringContainsString('action="'.$links->getAction('pay2play_skip').'"', $xml);
        $this->assertStringNotContainsString('<manialink id="'.$links->getAction('pay2play_skip').'"></manialink>', $xml);
    }

    public function test_replay_payment_extends_and_shows_success(): void
    {
        Event::listen(ExtendRequested::class, fn (ExtendRequested $e) => $e->addedSeconds = 300);
        $replays = [];
        Event::listen(ReplayRequested::class, function ($e) use (&$replays) { $replays[] = $e; });

        $this->click('pay2play_replay');
        $this->assertSame([['alice', 120, 'You need to pay 120 coppers to extend this track', '']], $this->sent('SendBill'));
        $this->assertSame([], $this->messages('ChatSendServerMessage'));

        $this->bill(4, 'Payed');
        $this->assertSame([], $replays, 'extended instead of replayed');
        $this->assertSame('$s$f90Player $z$sAlice$z$s$f90  pays 120 coppers and extends track time!', $this->messages('ChatSendServerMessage')[0]);
        $this->assertStringContainsString('EXTENDED!', $this->widgets());
    }

    public function test_replay_falls_back_to_the_jukebox_and_then_to_the_server(): void
    {
        $queued = null;
        Event::listen(ReplayRequested::class, function (ReplayRequested $e) use (&$queued) {
            $e->queued = $queued;
        });
        $queued = true;
        $this->click('pay2play_replay');
        $this->bill(4, 'Payed');
        $this->assertSame([], $this->sent('ChooseNextChallenge'));

        $queued = null;
        $this->p2p()->mapStarted(new MapStarted($this->map, false));   // success is per map
        $this->responses['SendBill'] = 56;
        $this->click('pay2play_replay');
        event(new BillCallback(56, 4, 'Payed', 4));
        $this->assertSame([['Challenges/Speed.Challenge.Gbx']], $this->sent('ChooseNextChallenge'));
    }

    public function test_already_replaying_is_refused_without_a_bill(): void
    {
        $this->responses['GetNextChallengeInfo'] = ['FileName' => 'Challenges/Speed.Challenge.Gbx'];
        $this->click('pay2play_replay');
        $this->assertSame([], $this->sent('SendBill'));
        $this->assertSame('>$f00 This track is already being replayed', $this->messages()[0]);
    }

    public function test_max_replays_are_enforced_right_after_the_payment(): void
    {
        config(['pay2play.replay.extra_max' => 1]);
        $this->click('pay2play_replay');
        $this->bill(4, 'Payed');
        $this->assertStringContainsString('MAXIMUM', $this->widgets());

        $this->responses['SendBill'] = 56;
        $this->click('pay2play_replay');
        $this->assertCount(1, $this->sent('SendBill'), 'no second bill past the maximum');
    }

    public function test_a_second_click_while_a_bill_is_open_is_ignored(): void
    {
        $this->click('pay2play_replay');
        $this->click('pay2play_replay');
        $this->assertCount(1, $this->sent('SendBill'));
        $this->bill(5, 'Refused');
        $this->assertStringContainsString('Transaction refused!', $this->messages()[0]);
        $this->click('pay2play_replay');
        $this->assertCount(2, $this->sent('SendBill'));
    }

    public function test_late_payment_does_nothing(): void
    {
        $this->click('pay2play_replay');
        $this->tick(61);
        $this->assertStringContainsString('timed out', $this->messages()[0]);
        $this->bill(4, 'Payed');
        $this->assertSame([], $this->messages('ChatSendServerMessage'));
    }

    public function test_non_united_player_is_told(): void
    {
        state()->forever('funseco:player_united:alice', false);
        $this->click('pay2play_skip');
        $this->assertSame([], $this->sent('SendBill'));
        $this->assertStringContainsString('requires a TM United Forever account', $this->messages()[0]);
    }

    public function test_skip_pays_waits_the_delay_then_skips(): void
    {
        $this->click('pay2play_skip');
        $this->assertSame([['alice', 80, 'You need to pay 80 coppers to skip this track', '']], $this->sent('SendBill'));
        $this->bill(4, 'Payed');
        $this->assertSame('$s$f90Player $z$sAlice$z$s$f90  pays 80 coppers. Track will be skipped shortly!', $this->messages('ChatSendServerMessage')[0]);
        $this->assertStringContainsString('SKIPPED', $this->widgets());

        $this->tick(9);
        $this->assertSame([], $this->sent('NextChallenge'));
        $this->tick(1);
        $this->assertCount(1, $this->sent('NextChallenge'));
        $this->tick(5);
        $this->assertCount(1, $this->sent('NextChallenge'));
    }

    public function test_skip_is_refused_when_the_map_ends_before_the_delay(): void
    {
        $this->tick(295);   // 5 s of the 300 s limit left: less than delay + 10
        $this->click('pay2play_skip');
        $this->assertSame([], $this->sent('SendBill'));
        $this->assertSame('>$f00 This track will end before your action can be completed, please be patient.', $this->messages()[0]);
    }

    public function test_map_end_hides_the_widgets_and_cancels_a_scheduled_skip(): void
    {
        $this->click('pay2play_skip');
        $this->bill(4, 'Payed');
        $this->p2p()->mapEnded(new MapEnded($this->map, new ChallengeInfo(array_fill(0, 14, 0)), new PlayerRankingsCollection(), false, false));
        $shown = $this->sent('SendDisplayManialinkPage');
        $this->assertStringContainsString('></manialink>', $shown[count($shown) - 1][0]);
        $this->tick(15);
        $this->assertSame([], $this->sent('NextChallenge'));
    }

    private function bills(): int
    {
        return count($this->sent('SendBill'));
    }

    public function test_two_clicks_inside_the_next_map_round_trip_make_one_bill(): void
    {
        $this->deferred = ['GetNextChallengeInfo'];
        $this->click('pay2play_replay');
        $this->click('pay2play_replay');
        $this->assertCount(1, $this->sent('GetNextChallengeInfo'));
        $this->answer('GetNextChallengeInfo');
        $this->assertSame(1, $this->bills());
    }

    public function test_two_clicks_inside_the_united_check_make_one_bill(): void
    {
        state()->forget('funseco:server_united');   // asked again, slowly
        $this->deferred = ['GetMainServerPlayerInfo'];
        $this->responses['GetMainServerPlayerInfo'] = ['Login' => 'srv'];
        $this->click('pay2play_skip');
        $this->click('pay2play_skip');
        $this->assertCount(1, $this->sent('GetMainServerPlayerInfo'));
        $this->responses['GetDetailedPlayerInfo'] = ['OnlineRights' => 3];
        $this->answer('GetMainServerPlayerInfo');
        $this->assertSame(1, $this->bills());
    }

    public function test_failed_precondition_releases_the_button(): void
    {
        state()->forever('funseco:player_united:alice', false);
        $this->click('pay2play_skip');
        state()->forever('funseco:player_united:alice', true);
        $this->click('pay2play_skip');
        $this->assertSame(1, $this->bills());
    }

    public function test_already_replaying_releases_the_button(): void
    {
        $this->responses['GetNextChallengeInfo'] = ['FileName' => 'Challenges/Speed.Challenge.Gbx'];
        $this->click('pay2play_replay');
        $this->responses['GetNextChallengeInfo'] = ['FileName' => 'Challenges/Other.Challenge.Gbx'];
        $this->click('pay2play_replay');
        $this->assertSame(1, $this->bills());
    }

    public function test_no_second_purchase_after_success(): void
    {
        $this->click('pay2play_skip');
        $this->bill(4, 'Payed');
        $this->responses['SendBill'] = 56;
        $this->click('pay2play_skip');
        $this->assertSame(1, $this->bills());
    }

    public function test_skip_paid_after_the_map_ended_is_not_silent(): void
    {
        $this->click('pay2play_skip');
        $this->p2p()->mapEnded(new MapEnded($this->map, new ChallengeInfo(array_fill(0, 14, 0)), new PlayerRankingsCollection(), false, false));
        $this->bill(4, 'Payed');
        $this->assertSame([], $this->sent('NextChallenge'));
        $this->assertStringContainsString('refund', $this->messages()[0]);
        $this->assertSame([['alice', 80]], $this->refunds());
    }

    public function test_payment_for_another_map_does_nothing_but_tells_the_payer(): void
    {
        $this->click('pay2play_replay');
        $other = Map::factory()->create(['uid' => 'm2', 'file_name' => 'Challenges/Other.Challenge.Gbx']);
        CurrentMap::set($other);
        $this->bill(4, 'Payed');
        $this->assertSame([], $this->messages('ChatSendServerMessage'));
        $this->assertSame([], $this->sent('ChooseNextChallenge'));
        $this->assertStringContainsString('refund', $this->messages()[0]);
        $this->assertSame([['alice', 120]], $this->refunds());
    }

    public function test_replay_already_queued_is_not_announced(): void
    {
        Event::listen(ReplayRequested::class, fn (ReplayRequested $e) => $e->queued = false);
        $this->click('pay2play_replay');
        $this->bill(4, 'Payed');
        $this->assertSame([], $this->messages('ChatSendServerMessage'));
        $this->assertStringContainsString('refund', $this->messages()[0]);
        $this->assertSame([['alice', 120]], $this->refunds());
    }

    public function test_orphan_refund_is_sent_once_even_with_a_repeated_callback(): void
    {
        $this->click('pay2play_skip');
        $this->p2p()->mapEnded(new MapEnded($this->map, new ChallengeInfo(array_fill(0, 14, 0)), new PlayerRankingsCollection(), false, false));
        $this->bill(4, 'Payed');
        $this->bill(4, 'Payed');
        $this->assertSame([['alice', 80]], $this->refunds());
    }

    public function test_failed_orphan_refund_is_logged_and_the_payer_told(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $this->faults['Pay'] = 'denied';
        $this->click('pay2play_skip');
        $this->p2p()->mapEnded(new MapEnded($this->map, new ChallengeInfo(array_fill(0, 14, 0)), new PlayerRankingsCollection(), false, false));
        $this->bill(4, 'Payed');
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, '"alice"') && str_contains($m, '80 coppers'));
        $messages = $this->messages();
        $this->assertStringContainsString('failed', end($messages));
    }

    /** @return array<int, array{0: string, 1: int}> logins and coppers of every Pay sent */
    private function refunds(): array
    {
        return array_map(fn ($a) => [$a[0], $a[1]], $this->sent('Pay'));
    }

    public function test_startup_redraws_the_buttons_for_everybody_online(): void
    {
        $this->calls = [];

        $this->p2p()->startup();

        $sent = implode('', array_column($this->sent('SendDisplayManialinkPageToLogin'), 1));
        $this->assertStringContainsString('action="'.\Funseco\Core\Services\ManiaLink\Facades\ManiaLink::getAction('pay2play_replay').'"', $sent);
    }
}
