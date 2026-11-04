<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->in('Feature');

pest()->beforeEach(function (): void {
    $user = Schema::hasTable('users') ? User::factory()->admin()->create() : User::factory()->admin()->make(['id' => 1]);
    $this->actingAs($user);
})->in(
    'Feature/CreateOrderTest.php',
    'Feature/CustomerOrderFlowTest.php',
    'Feature/DeleteOrderTest.php',
    'Feature/EloquentOrderTest.php',
    'Feature/OrderItemMeasurementTest.php',
    'Feature/OrderItemSpecificationsTest.php',
    'Feature/OrderListTest.php',
    'Feature/OrderPaymentTermsTest.php',
    'Feature/OrderQuantityLimitTest.php',
    'Feature/PaymentReceiptUploadTest.php',
    'Feature/RecordOrderPaymentTest.php',
    'Feature/SupabaseReceiptStorageTest.php',
    'Feature/ViewOrderTest.php',
);
