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
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->index()->constrained()->cascadeOnDelete();
            $table->string('product_name');
            $table->string('size', 100)->nullable();
            $table->text('customization_notes')->nullable();
            $table->unsignedInteger('quantity');
            $table->string('unit', 20)->default('pcs');
            $table->decimal('unit_price', total: 12, places: 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
