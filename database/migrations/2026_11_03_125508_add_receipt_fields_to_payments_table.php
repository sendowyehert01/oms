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
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('receipt_storage', 20)->nullable();
            $table->string('receipt_bucket')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('receipt_name')->nullable();
            $table->string('receipt_mime_type', 100)->nullable();
            $table->unsignedInteger('receipt_size')->nullable();
            $table->char('receipt_sha256', 64)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['receipt_storage', 'receipt_bucket', 'receipt_path', 'receipt_name', 'receipt_mime_type', 'receipt_size', 'receipt_sha256']);
        });
    }
};
