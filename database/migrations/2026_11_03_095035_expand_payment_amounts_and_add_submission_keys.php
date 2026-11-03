<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * SQLite uses text to preserve cents instead of coercing large decimals to floating point.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->string('amount', 20)->change();
            } else {
                $table->decimal('amount', total: 18, places: 2)->change();
            }

            $table->uuid('submission_key')->nullable()->unique();
        });
    }

    /**
     * Remove form keys while retaining amount capacity to preserve recorded payments.
     */
    public function down(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['submission_key']);
            $table->dropColumn('submission_key');
        });
    }
};
