<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            if (Schema::hasColumn('wallets', 'currency_code')) {
                $table->dropUnique('wallets_user_id_currency_code_unique');
                $table->dropColumn('currency_code');
            } else {
                // احتياطي: لو تم حذف العمود وبقي الـ index
                $hasLegacyIndex = DB::table('information_schema.statistics')
                    ->where('table_schema', DB::getDatabaseName())
                    ->where('table_name', 'wallets')
                    ->where('index_name', 'wallets_user_id_currency_code_unique')
                    ->exists();

                if ($hasLegacyIndex) {
                    DB::statement('ALTER TABLE wallets DROP INDEX wallets_user_id_currency_code_unique');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            if (! Schema::hasColumn('wallets', 'currency_code')) {
                $table->string('currency_code', 3)->default('QAR')->after('user_id');
                $table->unique(['user_id', 'currency_code'], 'wallets_user_id_currency_code_unique');
            }
        });
    }
};
