<?php

test('the base URL shows login without application tables or internal navigation', function (): void {
    config([
        'session.driver' => 'file',
        'cache.default' => 'file',
    ]);

    $response = $this->get('/');

    $response->assertOk()
        ->assertSeeInOrder(['<body', 'Order Management System', 'Log in to OMS', '</body>'], false)
        ->assertSee('action="'.e(route('login.store')).'"', false)
        ->assertDontSee('href="'.e(route('orders.index')).'"', false)
        ->assertDontSee('Staff accounts')
        ->assertDontSee('Hello Lord')
        ->assertDontSee('Register');
});
