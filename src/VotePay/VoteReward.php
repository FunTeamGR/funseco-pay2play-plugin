<?php
namespace Funseco\Pay2Play\VotePay;

use Illuminate\Database\Eloquent\Model;

/** One paid vote reward: a player is paid once per map. */
class VoteReward extends Model
{
    public $timestamps = false;
    protected $table = 'vote_rewards_final';
    protected $guarded = ['id'];
    protected $casts = ['amount_paid' => 'integer', 'date_paid' => 'datetime'];
}
