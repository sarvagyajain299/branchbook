<?php

declare(strict_types=1);

// Staff accounts are created by HQ administrators. Nobody can self-register.
it('has no public registration page or endpoint', function (): void {
    $this->get('/register')->assertNotFound();
    $this->post('/register', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'password', 'password_confirmation' => 'password'])
        ->assertNotFound();
});
