<?php

declare(strict_types=1);

// Laravel ships a health route at /up (configured in bootstrap/app.php).
// Load balancers and uptime monitors hit it; it must boot the whole app
// and return 200 without touching the session or auth.
it('responds 200 on the /up health check', function (): void {
    $this->get('/up')->assertOk();
});
