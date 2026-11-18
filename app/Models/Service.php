<?php

declare(strict_types=1);

namespace App\Models;

use Backpack\CRUD\app\Models\Traits\CrudTrait;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property list<string> $required_documents
 * @property int $duration_minutes
 * @property int $buffer_minutes
 * @property int $min_notice_hours
 * @property int $max_days_ahead
 */
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use CrudTrait, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code', 'name', 'category', 'description', 'duration_minutes', 'buffer_minutes',
        'queue_prefix', 'required_documents', 'required_documents_text', 'min_notice_hours', 'max_days_ahead', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'min_notice_hours' => 'integer',
            'max_days_ahead' => 'integer',
            'required_documents' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The checklist as one document per line, for the admin form. The real storage stays a JSON
     * list (the API and emails depend on it); this is only a friendlier way to edit it.
     *
     * @return Attribute<string, string|null>
     */
    protected function requiredDocumentsText(): Attribute
    {
        return Attribute::make(
            get: fn (): string => implode("\n", $this->required_documents),
            set: fn (?string $value): array => ['required_documents' => json_encode(array_values(array_filter(
                array_map('trim', preg_split('/\r\n|\r|\n/', (string) $value) ?: []),
                fn (string $line): bool => $line !== '',
            )))],
        );
    }

    /** Minutes a slot needs on the advisor's calendar: meeting plus wrap-up buffer. */
    public function slotMinutes(): int
    {
        return $this->duration_minutes + $this->buffer_minutes;
    }

    /** @return BelongsToMany<Advisor, $this> */
    public function advisors(): BelongsToMany
    {
        return $this->belongsToMany(Advisor::class);
    }

    /** @return HasMany<Appointment, $this> */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
