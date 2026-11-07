<?php

use App\ItemAvailability;
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
            $table->string('finished_stock_quantity', 40)->nullable();
            $table->json('material_checklist')->nullable();
            $table->string('material_availability', 30)->default(ItemAvailability::NotChecked->value);
            $table->text('material_notes')->nullable();
            $table->foreignId('materials_checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('materials_checked_by_name')->nullable();
            $table->timestamp('materials_checked_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('materials_checked_by');
            $table->dropColumn(['finished_stock_quantity', 'material_checklist', 'material_availability', 'material_notes', 'materials_checked_by_name', 'materials_checked_at']);
        });
    }
};
