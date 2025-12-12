<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Single wallet reference used by the service
            if (! Schema::hasColumn('transactions', 'wallet_id')) {
                $table->foreignId('wallet_id')
                    ->nullable()
                    ->constrained('wallets')
                    ->nullOnDelete()
                    ->after('user_id');
            }

            // Basic ledger fields expected by the code
            if (! Schema::hasColumn('transactions', 'balance_before')) {
                $table->decimal('balance_before', 18, 4)->nullable()->after('amount');
            }

            if (! Schema::hasColumn('transactions', 'balance_after')) {
                $table->decimal('balance_after', 18, 4)->nullable()->after('balance_before');
            }

            if (! Schema::hasColumn('transactions', 'description')) {
                $table->string('description')->nullable()->after('status');
            }

            // Loosen enums to simple strings to align with credit/debit + completed/pending/failed
            $table->string('type', 20)->change();
            $table->string('status', 20)->change();

            // Optional: currency_code not used by the current logic
            if (Schema::hasColumn('transactions', 'currency_code')) {
                $table->dropColumn('currency_code');
            }

            // Optional legacy columns not used by current service
            if (Schema::hasColumn('transactions', 'wallet_id_from')) {
                $table->dropForeign(['wallet_id_from']);
                $table->dropColumn('wallet_id_from');
            }

            if (Schema::hasColumn('transactions', 'wallet_id_to')) {
                $table->dropForeign(['wallet_id_to']);
                $table->dropColumn('wallet_id_to');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'wallet_id')) {
                $table->dropForeign(['wallet_id']);
                $table->dropColumn('wallet_id');
            }

            if (Schema::hasColumn('transactions', 'balance_before')) {
                $table->dropColumn('balance_before');
            }

            if (Schema::hasColumn('transactions', 'balance_after')) {
                $table->dropColumn('balance_after');
            }

            if (Schema::hasColumn('transactions', 'description')) {
                $table->dropColumn('description');
            }

            // Recreate previous columns to allow rollback
            if (! Schema::hasColumn('transactions', 'currency_code')) {
                $table->string('currency_code', 3)->after('total_amount');
            }

            if (! Schema::hasColumn('transactions', 'wallet_id_from')) {
                $table->foreignId('wallet_id_from')->nullable()->constrained('wallets')->nullOnDelete()->after('user_id');
            }

            if (! Schema::hasColumn('transactions', 'wallet_id_to')) {
                $table->foreignId('wallet_id_to')->nullable()->constrained('wallets')->nullOnDelete()->after('wallet_id_from');
            }

            // Revert enums
            $table->enum('type', [
                'topup',
                'withdraw',
                'transfer',
                'exchange',
                'bill_payment',
            ])->change();

            $table->enum('status', ['pending', 'success', 'failed', 'reversed'])->change();
        });
    }
};
