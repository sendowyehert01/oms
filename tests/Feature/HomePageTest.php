<?php

test('the home page offers dashboard and orders entry without application tables', function (): void {
    config([
        'session.driver' => 'file',
        'cache.default' => 'file',
    ]);

    $response = $this->get('/');

    $response->assertOk()
        ->assertSeeInOrder(['<body', 'Order Management System', 'Open dashboard', 'View orders', '</body>'], false)
        ->assertSee('href="'.e(route('dashboard')).'"', false)
        ->assertSee('href="'.e(route('orders.index')).'"', false)
        ->assertDontSee('Hello Lord')
        ->assertDontSee('<form', false);
});
