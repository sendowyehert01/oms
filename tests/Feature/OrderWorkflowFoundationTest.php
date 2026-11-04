<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;

pest()->use(DatabaseMigrations::class);

test('existing status values remain intact without invented workflow history', function (string $status): void {
    $order = Order::factory()->hasItems(1)->create(['status' => $status]);
    expect($order->fresh()->status)->toBe($status);
    expect($order->fresh()->workflow_version)->toBe(0);
    expect($order->statusHistory)->toHaveCount(0);
})->with(['Pending', 'In production', 'Ready for delivery', 'Completed', 'Legacy custom status']);

test('workflow history retains actor and item snapshots if related records are removed', function (): void {
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create();
    $actor = User::factory()->admin()->create(['name' => 'Production Admin']);
    $history = OrderStatusHistory::factory()->for($order)->for($item, 'item')->for($actor, 'actor')->create([
        'actor_name' => $actor->name, 'item_name' => $item->product_name,
    ]);
    expect($history->order->is($order))->toBeTrue();
    $actor->delete();
    $item->delete();
    $history->refresh();
    expect($history->actor_id)->toBeNull();
    expect($history->order_item_id)->toBeNull();
    expect($history->actor_name)->toBe('Production Admin');
    expect($history->item_name)->toBe($item->product_name);
});

test('history submission keys reject duplicate events', function (): void {
    $history = OrderStatusHistory::factory()->create();
    expect(fn (): OrderStatusHistory => OrderStatusHistory::factory()->create(['submission_key' => $history->submission_key]))->toThrow(QueryException::class);
    $this->assertDatabaseCount('order_status_histories', 1);
});

test('history is append only while deleting an unpaid order cleans up its own history', function (): void {
    $history = OrderStatusHistory::factory()->create();
    $other = OrderStatusHistory::factory()->create();
    expect(fn (): bool => $history->update(['notes' => 'Rewrite']))->toThrow(LogicException::class);
    expect(fn (): ?bool => $history->delete())->toThrow(LogicException::class);
    $history->order->delete();
    $this->assertDatabaseMissing('order_status_histories', ['id' => $history->id]);
    $this->assertDatabaseHas('order_status_histories', ['id' => $other->id]);
});
