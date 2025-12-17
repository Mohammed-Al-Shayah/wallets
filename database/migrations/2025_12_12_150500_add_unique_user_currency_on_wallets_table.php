<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            // Prevent duplicate wallets for same user/currency/type
            $table->unique(['user_id', 'currency', 'type'], 'wallets_user_currency_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropUnique('wallets_user_currency_type_unique');
        });
    }
};
