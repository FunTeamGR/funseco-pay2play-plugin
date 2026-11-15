<?php
namespace Funseco\Pay2Play\Pay;

use Funseco\Core\Services\ManiaLink\Facades\ManiaLink;
use Funseco\Core\SharedKernel\UI\WidgetPlacement;

/** The 4.6 x 6.5 "PAY n COPPERS FOR ..." button (XAseco pay2play). Four text lines in a card. */
class Pay2PlayWidget
{
    public const REPLAY = 'pay2play_replay';
    public const SKIP = 'pay2play_skip';

    private const YS = [-0.75, -2.3, -3.55, -5];

    /** Text per kind and state; {cost} is filled in. */
    private const TEXTS = [
        self::REPLAY => [
            'idle' => ['PAY {cost}', 'COPPERS', 'FOR', 'TIME EXTEND'],
            'success' => ['TRACK', 'HAS', 'BEEN', 'EXTENDED!'],
            'max' => ['MAXIMUM', 'TIME EXTEND', 'LIMIT', 'REACHED!'],
        ],
        self::SKIP => [
            'idle' => ['PAY {cost}', 'COPPERS', 'FOR', 'SKIP'],
            'success' => ['TRACK', 'WILL BE', 'SKIPPED', 'SHORTLY!'],
        ],
    ];

    /** Frame size of the widget, for WidgetRegistry. */
    public const WIDTH = 4.6;
    public const HEIGHT = 6.5;

    /** Widest text that fits the cell, and a capital's width at scale 1 (estimated from the rendered widget). */
    private const TEXT_FIT = 4.2;
    private const CHAR_WIDTH = 0.9;

    public static function empty(string $kind): string
    {
        return '<manialink id="'.ManiaLink::getAction($kind).'"></manialink>';
    }

    public static function xml(string $kind, string $state, WidgetPlacement $at, int $cost, bool $blink): string
    {
        $texts = self::TEXTS[$kind][$state] ?? self::TEXTS[$kind]['idle'];
        $id = ManiaLink::getAction($kind);
        $action = $state === 'idle' ? ' action="'.$id.'"' : '';
        $success = $state !== 'idle';

        $xml = '<manialink id="'.$id.'"><frame posn="'.$at->x.' '.$at->y.' 1">'
            .'<quad sizen="'.self::WIDTH.' '.self::HEIGHT.'" style="BgsPlayerCard" substyle="BgCardSystem"'.$action.'/>';

        foreach ($texts as $i => $text) {
            $text = str_replace('{cost}', (string) $cost, $text);
            $upper = $i % 2 === 0;   // lines 1 and 3: white, 2 and 4: orange
            if ($success && $blink) {
                $style = ' style="TextTitle2Blink"';
                $scale = $upper ? 0.5 : 0.35;
                $width = 7;
                $text = ($upper ? '$ccc' : '$c90').$text;
            } else {
                $style = '';
                $scale = $upper ? 0.9 : ($kind === self::SKIP && $success ? 0.6 : ($success ? 0.55 : 0.6));
                $width = $success ? ($kind === self::SKIP ? 5 : 4.5) : ($upper ? 5 : 8.4);
            }
            // a long line overflows the cell on both sides (centred): at the screen edge that cuts it off
            $scale = min($scale, self::TEXT_FIT / (self::CHAR_WIDTH * max(1, mb_strlen($text))));
            $xml .= '<label posn="2.25 '.self::YS[$i].' 0.1" sizen="'.$width.' 2" halign="center" textsize="1"'.$style
                .' scale="'.$scale.'" textcolor="'.($upper ? 'FFFF' : 'FC0F').'" text="'.htmlspecialchars($text).'"/>';
        }

        return $xml.'</frame></manialink>';
    }
}
