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
       Schema::create('transactions', function (Blueprint $table) {
    $table->id();

    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('wallet_id_from')->nullable()->constrained('wallets')->nullOnDelete();
    $table->foreignId('wallet_id_to')->nullable()->constrained('wallets')->nullOnDelete();

    $table->enum('type', [
        'topup',
        'withdraw',
        'transfer',
        'exchange',
        'bill_payment',
    ]);

    $table->decimal('amount', 18, 4);
    $table->decimal('fee', 18, 4)->default(0);
    $table->decimal('total_amount', 18, 4);

    $table->string('currency_code', 3);

    $table->enum('status', ['pending', 'success', 'failed', 'reversed'])
          ->default('pending');

    $table->string('reference')->nullable();
    $table->json('meta')->nullable();

    $table->timestamps();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
