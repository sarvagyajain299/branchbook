<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only record of who did what. Rows can be created but never changed or deleted. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'changes', 'ip_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Audit log entries are append-only and cannot be updated.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Audit log entries are append-only and cannot be deleted.');
        });
    }
}
