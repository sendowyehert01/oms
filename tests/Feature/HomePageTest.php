<?php

test('the home page displays hello lord without application tables', function (): void {
    config([
        'session.driver' => 'file',
        'cache.default' => 'file',
    ]);

    $response = $this->get('/');

    $response->assertOk()
        ->assertSeeInOrder(['<body', 'Hello Lord', '</body>'], false);
});
