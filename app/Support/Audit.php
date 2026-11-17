<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Appends one row to the audit log. Call it from the ACTION that did the work, inside the same
 * transaction, so the log entry and the change succeed or fail together.
 *
 * Rules for what goes in $changes: ids, codes, statuses and reasons only. NEVER names, emails,
 * phone numbers or tokens. IP addresses are stored as a keyed hash so they can be matched but
 * not read back.
 */
final class Audit
{
    /** @param  array<string, mixed>  $changes */
    public static function record(string $action, ?Model $subject = null, array $changes = [], ?User $actor = null): void
    {
        $actor ??= auth()->user();
        $ip = request()->ip();

        AuditLog::query()->create([
            'actor_type' => $actor === null ? null : 'user',
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject === null ? null : class_basename($subject),
            'subject_id' => $subject?->getKey(),
            'changes' => $changes === [] ? null : $changes,
            'ip_hash' => $ip === null ? null : hash_hmac('sha256', $ip, (string) config('app.key')),
        ]);
    }
}
