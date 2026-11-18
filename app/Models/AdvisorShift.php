<?php

declare(strict_types=1);

namespace App\Models;

use Backpack\CRUD\app\Models\Traits\CrudTrait;
use Database\Factories\AdvisorShiftFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One recurring weekly shift, e.g. Monday 08:00-15:00 (branch-local time). */
class AdvisorShift extends Model
{
    /** @use HasFactory<AdvisorShiftFactory> */
    use CrudTrait, HasFactory;

    /** @var list<string> */
    protected $fillable = ['advisor_id', 'weekday', 'starts_at', 'ends_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    /** @return BelongsTo<Advisor, $this> */
    public function advisor(): BelongsTo
    {
        return $this->belongsTo(Advisor::class);
    }
}
