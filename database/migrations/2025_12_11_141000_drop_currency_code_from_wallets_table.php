<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            if (Schema::hasColumn('wallets', 'currency_code')) {
                $table->dropColumn('currency_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            if (! Schema::hasColumn('wallets', 'currency_code')) {
                $table->string('currency_code', 3)->default('QAR')->after('user_id');
            }
        });
    }
};
