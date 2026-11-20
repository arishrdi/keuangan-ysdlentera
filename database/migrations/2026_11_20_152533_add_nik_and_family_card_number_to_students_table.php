<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NIK is unique per student; siblings share one family card (KK) number.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('nik', 16)->nullable()->unique()->after('nisn');
            $table->string('family_card_number', 16)->nullable()->index()->after('nik');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique(['nik']);
            $table->dropIndex(['family_card_number']);
            $table->dropColumn(['nik', 'family_card_number']);
        });
    }
};
