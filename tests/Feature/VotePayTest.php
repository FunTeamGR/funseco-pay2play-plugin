<?php
namespace Funseco\Pay2Play\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Funseco\Core\Testing\FakesServer;
use Funseco\Pay2Play\Tests\TestCase;
use Funseco\Core\Models\Map;
use Funseco\Core\Models\Player;
use Funseco\Core\Servers\TmForever\Callbacks\BillUpdated as BillCallback;
use Funseco\Core\Servers\TmForever\Values\ChallengeInfo;
use Funseco\Core\Servers\TmForever\Values\PlayerInfo;
use Funseco\Core\Servers\TmForever\Values\PlayerRankingsCollection;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Chat\Facades\Chat;
use Funseco\Core\SharedKernel\Events\KarmaVoteCast;
use Funseco\Core\SharedKernel\Events\MapEnded;
use Funseco\Core\SharedKernel\Events\MapStarted;
use Funseco\Core\SharedKernel\Events\PlayerConnected;
use Funseco\Pay2Play\VotePay\VotePay;
use Funseco\Pay2Play\VotePay\VoteReward;

class VotePayTest extends TestCase
{
    use FakesServer;
    use RefreshDatabase;

    private Player $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeServer();
        state()->flush();
        state()->forever('funseco:server_united', true);
        state()->forever('funseco:player_united:alice', true);
        $this->responses['GetServerCoppers'] = 100000;
        $this->responses['Pay'] = 90;
        config(['pay2play.votepay.enabled' => true]);

        CurrentPlayers::clear();
        $this->alice = Player::factory()->create(['login' => 'alice', 'nickname' => 'Alice']);
        CurrentPlayers::add($this->alice);
    }

    private function startMap(string $uid): Map
    {
        $map = Map::factory()->create(['uid' => $uid, 'file_name' => "Challenges/$uid.Challenge.Gbx"]);
        CurrentMap::set($map);
        app(VotePay::class)->mapStarted(new MapStarted($map, false));

        return $map;
    }

    private function vote(int $value = 1, ?Player $player = null): void
    {
        event(new KarmaVoteCast($player ?? $this->alice, CurrentMap::get()->uid, $value));
    }

    private function endMap(Map $map): void
    {
        app(VotePay::class)->mapEnded(new MapEnded($map, new ChallengeInfo(array_fill(0, 14, 0)), new PlayerRankingsCollection(), false, false));
    }

    public function test_first_vote_pays_the_base_amount_once_per_map(): void
    {
        $this->startMap('m1');
        $this->vote();

        $this->assertSame([['alice', 15, 'Vote Reward']], $this->sent('Pay'));
        $this->assertSame([], $this->messages(), 'nothing is announced before the payout resolves');
        $this->assertSame(0, VoteReward::count());
        $this->settle(4, 'Payed');
        $this->assertStringContainsString('Vote Bonus!', $this->messages()[0]);
        $this->assertStringContainsString('received 15 coppers.', $this->messages()[0]);
        $this->assertSame(15, VoteReward::where(['login' => 'alice', 'map_uid' => 'm1'])->value('amount_paid'));

        $this->vote(-2);   // any vote line counts, but only once per map
        $this->assertCount(1, $this->sent('Pay'));
        $this->assertStringContainsString('You have already been paid for this map!', $this->messages()[1]);
    }

    private function settle(int $state, string $name): void
    {
        event(new BillCallback(90, $state, $name, 7));
    }

    public function test_failed_payout_writes_no_row_sends_no_message_and_next_vote_retries(): void
    {
        $this->startMap('m1');
        $this->vote();
        $this->settle(6, 'Error');

        $this->assertSame([], $this->messages());
        $this->assertSame(0, VoteReward::count());

        $this->responses['Pay'] = 91;
        $this->vote();
        $this->assertCount(2, $this->sent('Pay'));
        event(new BillCallback(91, 4, 'Payed', 8));
        $this->assertSame(1, VoteReward::where(['login' => 'alice', 'map_uid' => 'm1'])->count());
        $this->assertStringContainsString('received 15 coppers.', $this->messages()[0]);
    }

    public function test_streak_bonuses(): void
    {
        $paid = [];
        foreach (['m1', 'm2', 'm3', 'm4', 'm5', 'm6'] as $uid) {
            $map = $this->startMap($uid);
            $this->vote();
            $this->settle(4, 'Payed');
            $this->endMap($map);
        }
        $this->assertSame([15, 15, 20, 20, 25, 25], array_map(fn ($a) => $a[1], $this->sent('Pay')));
        $this->assertStringContainsString('(+5 streak bonus!)', $this->messages()[2]);
        $this->assertStringContainsString('(+10 streak bonus!)', $this->messages()[4]);
    }

    public function test_skipping_a_map_resets_the_streak(): void
    {
        foreach (['m1', 'm2'] as $uid) {
            $map = $this->startMap($uid);
            $this->vote();
            $this->settle(4, 'Payed');
            $this->endMap($map);
        }
        $skipped = $this->startMap('m3');
        $this->endMap($skipped);   // no vote
        $this->assertSame(0, app(VotePay::class)->streakOf('alice'));

        $this->startMap('m4');
        $this->vote();
            $this->settle(4, 'Payed');
        $this->assertSame(15, $this->sent('Pay')[2][1]);
    }

    public function test_reconnect_mid_map_keeps_the_streak(): void
    {
        foreach (['m1', 'm2'] as $uid) {
            $map = $this->startMap($uid);
            $this->vote();
            $this->settle(4, 'Payed');
            $this->endMap($map);
        }
        $this->startMap('m3');
        app(VotePay::class)->connected(new PlayerConnected($this->alice, new PlayerInfo([])));
        $this->assertSame(2, app(VotePay::class)->streakOf('alice'));
    }

    public function test_only_united_players_and_a_rich_server_are_paid_silently(): void
    {
        $this->startMap('m1');
        state()->forever('funseco:player_united:alice', false);
        $this->vote();
        $this->assertSame([], $this->sent('Pay'));
        $this->assertSame([], $this->messages());

        state()->forever('funseco:player_united:alice', true);
        $this->responses['GetServerCoppers'] = 5000;   // must be strictly more
        $this->vote();
        $this->assertSame([], $this->sent('Pay'));
        $this->assertSame([], $this->messages());
        $this->assertSame(0, VoteReward::count());

        $this->responses['GetServerCoppers'] = 5001;
        $this->vote();
        $this->assertCount(1, $this->sent('Pay'));
    }

    public function test_votes_command(): void
    {
        $this->startMap('m1');
        $this->vote();
        $this->settle(4, 'Payed');
        Chat::resolve('/votes', 'alice');
        $last = $this->messages()[count($this->messages()) - 1];
        $this->assertStringContainsString('Your Vote Stats:', $last);
        $this->assertStringContainsString('1 votes | 15 coppers earned | Streak: 1 | Vote 2 more map(s) for +5 bonus!', $last);
    }
}
