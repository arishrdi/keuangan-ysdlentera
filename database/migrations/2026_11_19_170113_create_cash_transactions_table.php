<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('level_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_account_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('direction', 10);
            $table->unsignedBigInteger('amount');
            $table->date('transacted_on');
            $table->string('description');
            $table->string('counterparty')->nullable();
            $table->foreignId('transaction_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('related_transaction_id')->nullable()->constrained('cash_transactions')->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('cash_transactions')->restrictOnDelete();
            $table->string('proof_path')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['level_id', 'transacted_on']);
            $table->index(['cash_account_id', 'transacted_on']);
            $table->index(['type', 'direction']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_transactions');
    }
};
