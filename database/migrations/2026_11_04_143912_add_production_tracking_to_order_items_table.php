<?php

use App\ItemAvailability;
use App\ItemProductionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('stock_availability', 30)->default(ItemAvailability::NotChecked->value);
            $table->text('availability_notes')->nullable();
            $table->foreignId('availability_checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('availability_checked_by_name')->nullable();
            $table->timestamp('availability_checked_at')->nullable();
            $table->string('production_status', 30)->default(ItemProductionStatus::Pending->value);
            $table->string('readiness_source', 20)->nullable();
            $table->boolean('ready_check_required')->default(false);
            $table->foreignId('ready_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ready_confirmed_by_name')->nullable();
            $table->timestamp('ready_confirmed_at')->nullable();
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('item_tracking_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('item_tracking_started_at');
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('availability_checked_by');
            $table->dropConstrainedForeignId('ready_confirmed_by');
            $table->dropColumn(['stock_availability', 'availability_notes', 'availability_checked_by_name', 'availability_checked_at', 'production_status', 'readiness_source', 'ready_check_required', 'ready_confirmed_by_name', 'ready_confirmed_at']);
        });
    }
};
