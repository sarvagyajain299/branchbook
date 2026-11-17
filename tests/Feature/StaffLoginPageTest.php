<?php

declare(strict_types=1);

// The guest layout puts config('app.name') in the <title>, and the login form
// posts to route('login'). Checking both catches a wrong APP_NAME and a
// renamed or missing login route.
it('renders the staff login page with the BranchBook name', function (): void {
    $response = $this->get(route('login'));

    $response->assertOk()
        ->assertSee('<title>BranchBook</title>', escape: false)
        ->assertSee('action="'.route('login').'"', escape: false);
});
