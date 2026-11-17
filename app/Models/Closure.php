<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ClosureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A day a branch (or every branch, when branch_id is NULL) is closed: public holiday or ad-hoc. */
class Closure extends Model
{
    /** @use HasFactory<ClosureFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['branch_id', 'date', 'reason'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'immutable_date'];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
