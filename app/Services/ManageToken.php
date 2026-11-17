<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Appointment;
use Illuminate\Support\Str;

/**
 * The secret in a "manage my booking" link. Customers have no account, so possession of the
 * token IS the credential. Only its SHA-256 hash is stored; a database leak can't be turned
 * back into working links. A 40-character random token has ~238 bits of entropy, so a fast
 * hash is fine here (unlike low-entropy passwords or 6-digit codes).
 */
final class ManageToken
{
    public static function generate(): string
    {
        return Str::random(40);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Finds the booking for a reference + token, or aborts with 404 (never revealing which part was wrong). */
    public static function resolve(string $reference, ?string $token): Appointment
    {
        $appointment = Appointment::query()
            ->with(['customer', 'branch', 'service'])
            ->where('reference', $reference)
            ->first();

        $valid = $appointment !== null
            && $token !== null && $token !== ''
            && $appointment->manage_token_hash !== null
            && hash_equals($appointment->manage_token_hash, self::hash($token)); // constant-time

        abort_unless($valid, 404);

        /** @var Appointment $appointment */
        return $appointment;
    }
}
