<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vote_rewards_final', function (Blueprint $table) {
            $table->id();
            $table->string('login', 50)->index();
            $table->string('map_uid', 100)->index();
            $table->dateTime('date_paid')->index();
            $table->integer('amount_paid')->default(15);
            $table->unique(['login', 'map_uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vote_rewards_final');
    }
};
