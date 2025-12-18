<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            // Add wallet type (main/bonus)
            if (! Schema::hasColumn('wallets', 'type')) {
                $table->string('type', 20)->default('main')->after('user_id');
            }

            // Add currency column to match model/service expectations
            if (! Schema::hasColumn('wallets', 'currency')) {
                $table->string('currency', 3)->default('QAR')->after('type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            if (Schema::hasColumn('wallets', 'currency')) {
                $table->dropColumn('currency');
            }

            if (Schema::hasColumn('wallets', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
