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
        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('quantity', 40)->using('quantity::text')->change();
        });
    }

    /**
     * Retain text storage on rollback to avoid losing units or fractional weights.
     */
    public function down(): void {}
};
