<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Branch;
use App\Services\Reports\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('view-reports');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch' => ['nullable', 'string', 'exists:branches,code'],
        ];
    }

    /** Defaults to the last 30 days. Managers are always confined to their own branch. */
    public function filters(): ReportFilters
    {
        $tz = (string) config('app.display_timezone');
        $today = CarbonImmutable::now($tz);
        $from = $this->validated('from') ?? $today->subDays(29)->toDateString();
        $to = $this->validated('to') ?? $today->toDateString();

        abort_if(CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 366, 422, 'Choose a range of at most one year.');

        $user = $this->user();
        $requested = $this->validated('branch') === null ? null : Branch::query()->where('code', $this->validated('branch'))->value('id');

        if (! $user->isHq()) {
            // A manager may not look at another branch, and the default view is their own.
            abort_if($requested !== null && (int) $requested !== $user->branch_id, 403);
            $requested = $user->branch_id;
        }

        return new ReportFilters($from, $to, $requested === null ? null : (int) $requested);
    }
}
