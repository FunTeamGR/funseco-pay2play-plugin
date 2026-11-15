<?php
namespace Funseco\Pay2Play\VotePay;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;

/** /votes: the player's vote reward statistics. */
class VotesCommand implements Command, Described
{
    use DeclaresCommand;

    protected string $name = 'votes';
    protected string $description = 'Shows your vote stats and rewards';

    public function handle(Player $player, array $args): void
    {
        $rows = VoteReward::where('login', $player->login);
        $votes = (clone $rows)->count();
        $coppers = (int) (clone $rows)->sum('amount_paid');
        $streak = app(VotePay::class)->streakOf($player->login);

        $next = match (true) {
            $streak < 3 => 'Vote '.(3 - $streak).' more map(s) for +5 bonus!',
            $streak < 5 => 'Vote '.(5 - $streak).' more map(s) for +10 bonus!',
            default => 'Max streak! Keep voting for +10 per vote!',
        };

        \Server::query()->ChatSendServerMessageToLogin(
            format_colors("{#server}» {#highlite}Your Vote Stats:{#server} {$votes} votes | {$coppers} coppers earned | Streak: {$streak} | {$next}"),
            $player->login,
        );
    }
}
