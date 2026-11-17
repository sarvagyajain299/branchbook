<?php

declare(strict_types=1);

// TODO(sarvagya): Write this test, then remove ->todo().
// Goal: prove the staff login page renders and is branded BranchBook.
// Hint:
//   1. $response = $this->get(route('login'));
//   2. assert it is 200 (assertOk) and that the page contains the app name.
//      The app name comes from config('app.name'), set by APP_NAME in .env.
//      Look at resources/views/layouts/guest.blade.php: where is the name used?
//   3. Bonus: assert the form posts to the login route (assertSee with the URL,
//      escaped HTML is fine) so a broken route name is caught.
// Run it with: sail pest --filter=StaffLoginPageTest
it('renders the staff login page with the BranchBook name')->todo();
