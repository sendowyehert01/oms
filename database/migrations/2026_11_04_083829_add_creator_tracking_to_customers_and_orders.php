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
        foreach (['customers', 'orders'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->index(['created_by', 'created_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['customers', 'orders'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropIndex(['created_by', 'created_at']);
                $table->dropConstrainedForeignId('created_by');
            });
        }
    }
};
