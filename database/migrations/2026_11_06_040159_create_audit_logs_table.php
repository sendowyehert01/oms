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
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_name');
            $table->string('actor_role', 30);
            $table->string('action', 40);
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_name');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('order_number', 40)->nullable();
            $table->text('statement');
            $table->timestamp('created_at');
            $table->index(['created_at', 'id']);
            $table->index(['actor_id', 'created_at', 'id']);
            $table->index(['action', 'created_at', 'id']);
            $table->index('order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
