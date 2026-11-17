<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BranchHourFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchHour extends Model
{
    /** @use HasFactory<BranchHourFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['branch_id', 'weekday', 'opens_at', 'closes_at', 'break_start', 'break_end'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
