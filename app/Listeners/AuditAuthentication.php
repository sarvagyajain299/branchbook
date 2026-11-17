<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;

/** Records staff logins and failed attempts. Laravel discovers the handle* methods by their type-hints. */
final class AuditAuthentication
{
    public function handleLogin(Login $event): void
    {
        Audit::record('auth.login', null, [], $event->user instanceof \App\Models\User ? $event->user : null);
    }

    public function handleFailed(Failed $event): void
    {
        // The attempted email is hashed: a typo'd password must not leave a readable address in the log.
        $email = mb_strtolower((string) ($event->credentials['email'] ?? ''));

        Audit::record('auth.failed', null, ['email_hash' => hash('sha256', $email)]);

        Log::channel('security')->warning('Failed staff login', ['email_hash' => hash('sha256', $email)]);
    }
}
