<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A cash account without a level is shared by every level (e.g. one foundation bank account).
     * Its transactions still belong to a level, so each level keeps its own balance in it.
     */
    public function up(): void
    {
        Schema::table('cash_accounts', function (Blueprint $table) {
            $table->foreignId('level_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('cash_accounts', function (Blueprint $table) {
            $table->foreignId('level_id')->nullable(false)->change();
        });
    }
};
