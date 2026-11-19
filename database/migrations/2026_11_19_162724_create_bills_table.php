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
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('level_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_category_id')->constrained()->restrictOnDelete();
            $table->date('period')->nullable();
            $table->string('description');
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->string('discount_reason')->nullable();
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('belum_bayar');
            $table->string('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['level_id', 'status']);
            $table->index(['student_id', 'payment_category_id', 'period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
