<?php

use App\Models\User;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->in('Feature');

pest()->beforeEach(function (): void {
    $this->actingAs(User::factory()->make(['id' => 1]));
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
