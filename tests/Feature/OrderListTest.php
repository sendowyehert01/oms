<?php

use Illuminate\Pagination\LengthAwarePaginator;

test('the home navigation links to the orders page', function (): void {
    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSee('Main navigation')
        ->assertSee('Order Management System')
        ->assertSee('href="'.route('orders.index').'"', false);
});

test('orders display their number customer date status and formatted total without application tables', function (): void {
    config(['session.driver' => 'file', 'cache.default' => 'file']);

    $response = $this->get(route('orders.index'));

    $response->assertOk()
        ->assertSeeInOrder(['Order number', 'Customer name', 'Order date', 'Order status', 'Total amount'])
        ->assertSeeInOrder(['ORD-2026-1024', 'Northstar Packaging', 'Oct 31, 2026', 'In production', '18,500.00'])
        ->assertSee('4,250.50')
        ->assertSee('Sample orders')
        ->assertSee('aria-current="page"', false);
});

test('each order page shows its own records and the correct range', function (int $page, string $firstOrder, string $lastOrder, int $count, string $range): void {
    $response = $this->get(route('orders.index', ['page' => $page]));

    $response->assertOk()
        ->assertSeeInOrder([$firstOrder, $lastOrder])
        ->assertSee($range)
        ->assertSee('aria-label="Page '.$page.'"', false);

    expect($response['orders'])->toHaveCount($count);
})->with([
    'first page' => [1, 'ORD-2026-1024', 'ORD-2026-1015', 10, 'Showing 1 to 10 of 24 orders'],
    'middle page' => [2, 'ORD-2026-1014', 'ORD-2026-1005', 10, 'Showing 11 to 20 of 24 orders'],
    'last page' => [3, 'ORD-2026-1004', 'ORD-2026-1001', 4, 'Showing 21 to 24 of 24 orders'],
]);

test('the first page links forward and disables previous navigation', function (): void {
    $response = $this->get(route('orders.index'));

    $response->assertOk()
        ->assertSee('href="'.e(route('orders.index', ['search' => '', 'page' => 2])).'"', false)
        ->assertSee('rel="next"', false)
        ->assertDontSee('rel="prev"', false)
        ->assertSee('Go to page 3');
});

test('the middle page links to the previous and next pages', function (): void {
    $response = $this->get(route('orders.index', ['page' => 2]));

    $response->assertOk()
        ->assertSee('href="'.e(route('orders.index', ['search' => '', 'page' => 1])).'"', false)
        ->assertSee('href="'.e(route('orders.index', ['search' => '', 'page' => 3])).'"', false)
        ->assertSee('rel="prev"', false)
        ->assertSee('rel="next"', false)
        ->assertDontSee('ORD-2026-1024')
        ->assertDontSee('ORD-2026-1001');
});

test('the last page links back and disables next navigation', function (): void {
    $response = $this->get(route('orders.index', ['page' => 3]));

    $response->assertOk()
        ->assertSee('rel="prev"', false)
        ->assertDontSee('rel="next"', false);
});

test('orders can be searched by number or customer name regardless of case and surrounding spaces', function (string $search, string $expectedOrder, string $excludedOrder, int $total): void {
    $response = $this->get(route('orders.index', ['search' => $search]));

    $response->assertOk()
        ->assertSee($expectedOrder)
        ->assertDontSee($excludedOrder)
        ->assertSee('Clear search');

    expect($response['orders']->total())->toBe($total);
})->with([
    'full order number' => ['ORD-2026-1024', 'ORD-2026-1024', 'ORD-2026-1023', 1],
    'numeric order ID' => ['1023', 'ORD-2026-1023', 'ORD-2026-1024', 1],
    'partial order number' => ['2026-102', 'ORD-2026-1024', 'ORD-2026-1019', 5],
    'partial customer name' => ['north', 'ORD-2026-1024', 'ORD-2026-1023', 6],
    'mixed case and whitespace' => ['  nOrThStAr  ', 'ORD-2026-1024', 'ORD-2026-1023', 6],
]);

test('search is retained in pagination links and only matching orders appear on later pages', function (): void {
    $response = $this->get(route('orders.index', ['search' => 'packaging', 'page' => 2]));

    $response->assertOk()
        ->assertSee('value="packaging"', false)
        ->assertSee('href="'.e(route('orders.index', ['search' => 'packaging', 'page' => 1])).'"', false)
        ->assertSee('Showing 11 to 18 of 18 orders')
        ->assertSee('ORD-2026-1010')
        ->assertDontSee('Harbor Foods')
        ->assertDontSee('Bloom Retail')
        ->assertDontSee('ORD-2026-1024');
});

test('a search with no matches shows an empty state and a zero record range', function (): void {
    $response = $this->get(route('orders.index', ['search' => 'missing customer']));

    $response->assertOk()
        ->assertSee('No matching orders')
        ->assertSee('missing customer')
        ->assertSee('View all orders')
        ->assertSee('Showing 0 to 0 of 0 orders')
        ->assertDontSee('ORD-2026-1024')
        ->assertDontSee('rel="next"', false)
        ->assertDontSee('rel="prev"', false);
});

test('search text is escaped in the input and empty state', function (): void {
    $search = '<script>alert("test")</script>';

    $response = $this->get(route('orders.index', ['search' => $search]));

    $response->assertOk()
        ->assertSee($search)
        ->assertDontSee($search, false)
        ->assertSee('No matching orders');
});

test('a requested page beyond the search results shows the last available page', function (): void {
    $response = $this->get(route('orders.index', ['search' => 'Northstar', 'page' => 99]));

    $response->assertOk()
        ->assertSee('ORD-2026-1024')
        ->assertSee('Showing 1 to 6 of 6 orders')
        ->assertDontSee('No matching orders');
});

test('non string search input is treated as an empty search', function (): void {
    $response = $this->get(route('orders.index', ['search' => ['Northstar']]));

    $response->assertOk()
        ->assertSee('Showing 1 to 10 of 24 orders')
        ->assertDontSee('Clear search');
});

test('the reusable table shows a neutral empty state when there are no orders', function (): void {
    $orders = new LengthAwarePaginator([], 0, 10);

    $response = $this->blade('<x-orders-table :orders="$orders" />', compact('orders'));

    $response->assertSee('No orders yet')
        ->assertDontSee('No matching orders');
});
