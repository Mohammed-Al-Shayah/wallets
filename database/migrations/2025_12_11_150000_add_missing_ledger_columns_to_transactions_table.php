<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'fee')) {
                $table->decimal('fee', 18, 4)->default(0)->after('amount');
            }

            if (! Schema::hasColumn('transactions', 'total_amount')) {
                $table->decimal('total_amount', 18, 4)->default(0)->after('fee');
            }

            if (! Schema::hasColumn('transactions', 'balance_before')) {
                $table->decimal('balance_before', 18, 4)->nullable()->after('total_amount');
            }

            if (! Schema::hasColumn('transactions', 'balance_after')) {
                $table->decimal('balance_after', 18, 4)->nullable()->after('balance_before');
            } else {
                // ensure balance_after is after balance_before for readability
                $table->decimal('balance_after', 18, 4)->change();
            }

            if (! Schema::hasColumn('transactions', 'status')) {
                $table->string('status', 20)->default('pending')->after('balance_after');
            } else {
                $table->string('status', 20)->change();
            }

            if (! Schema::hasColumn('transactions', 'description')) {
                $table->string('description')->nullable()->after('status');
            }

            if (! Schema::hasColumn('transactions', 'reference')) {
                $table->string('reference')->nullable()->after('description');
            }

            if (! Schema::hasColumn('transactions', 'meta')) {
                $table->json('meta')->nullable()->after('reference');
            }

            // ensure type is long enough for credit/debit
            if (Schema::hasColumn('transactions', 'type')) {
                $table->string('type', 20)->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            foreach (['fee', 'total_amount', 'balance_before', 'status', 'description', 'reference', 'meta'] as $column) {
                if (Schema::hasColumn('transactions', $column)) {
                    $table->dropColumn($column);
                }
            }

            // keep balance_after/type changes on rollback for safety
        });
    }
};
