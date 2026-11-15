<?php
namespace Funseco\Pay2Play\Pay;

use Funseco\Core\Models\Player;

/** A click on one of the pay buttons (the action name is the widget name). */
class ClickHandler
{
    public function handle(Player $player, string $action): void
    {
        app(Pay2Play::class)->click($player, $action);
    }
}
