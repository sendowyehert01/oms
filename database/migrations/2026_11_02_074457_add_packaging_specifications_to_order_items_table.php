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
            $table->string('material_color')->nullable();
            $table->string('print_design_colors')->nullable();
            $table->string('sealing', 30)->nullable();
            $table->string('sealing_other')->nullable();
            $table->string('puncher', 30)->nullable();
            $table->string('puncher_other')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['material_color', 'print_design_colors', 'sealing', 'sealing_other', 'puncher', 'puncher_other']);
        });
    }
};
