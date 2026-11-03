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
        Schema::table('orders', function (Blueprint $table): void {
            $table->date('due_date')->nullable()->change();
            $table->string('terms_of_payment')->nullable();
        });
    }

    /**
     * Remove payment terms, keeping legacy dates nullable for orders without a due date.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('terms_of_payment');
        });
    }
};
