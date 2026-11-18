<?php

declare(strict_types=1);

namespace App\Models;

use Backpack\CRUD\app\Models\Traits\CrudTrait;
use Carbon\CarbonImmutable;
use Database\Factories\AdvisorTimeOffFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 */
class AdvisorTimeOff extends Model
{
    /** @use HasFactory<AdvisorTimeOffFactory> */
    use CrudTrait, HasFactory;

    protected $table = 'advisor_time_off';

    /** @var list<string> */
    protected $fillable = ['advisor_id', 'starts_at', 'ends_at', 'reason'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Advisor, $this> */
    public function advisor(): BelongsTo
    {
        return $this->belongsTo(Advisor::class);
    }
}
