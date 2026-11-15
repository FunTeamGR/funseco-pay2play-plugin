<?php

/*
 * Features that spend or take real coppers: everything is ON by default (owner decision
 * 2026-10-29). They need a United server account; on any other account the plugins tell the
 * player and do nothing.
 * replay/skip cost, delay, blink and callvote flags come from the owner's pay2play.xml (skip stays
 * enabled here by owner decision; the xml had it off). Still placeholders: the votepay amounts (base,
 * streaks, min_server_coppers).
 */
return [
    // Coppers for karma votes (XAseco plugin.votepay.php).
    'votepay' => [
        'enabled' => env('VOTEPAY_ENABLED', true),
        'base' => 15,
        'streak3_bonus' => 5,
        'streak5_bonus' => 10,
        // The server account must own MORE than this, else nothing is paid (and a warning is logged).
        'min_server_coppers' => 5000,
        'history' => 10,
    ],

    // PAY n COPPERS FOR TIME EXTEND ("replay charge").
    'replay' => [
        'enabled' => env('PAY2PLAY_REPLAY_ENABLED', true),
        'cost' => 111,                // owner pay2play.xml
        'extra_max' => 2,             // paid replays/extends per track on top of the voted max (votes.replays_in_a_row); 0 = no paid replays
        'blink' => true,
        'disable_callvote' => true,   // switch off the server's /replay call vote
        'extend' => true,             // add time (timelimit plugin) instead of replaying when a countdown runs
    ],

    // PAY n COPPERS FOR SKIP.
    'skip' => [
        'enabled' => env('PAY2PLAY_SKIP_ENABLED', true),
        'cost' => 666,                // owner pay2play.xml
        'delay' => 1,                 // seconds between payment and skip (owner pay2play.xml)
        'blink' => true,
        'disable_callvote' => true,  // switch off the server's /skip call vote
    ],
];
