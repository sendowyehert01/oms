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
        Schema::table('orders', function (Blueprint $table) {
            $table->date('delivery_date')->nullable();
            $table->string('delivery_recipient')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->foreignId('delivery_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('delivery_confirmed_by_name')->nullable();
            $table->timestamp('delivery_confirmed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_confirmed_by');
            $table->dropColumn(['delivery_date', 'delivery_recipient', 'delivery_notes', 'delivery_confirmed_by_name', 'delivery_confirmed_at']);
        });
    }
};
