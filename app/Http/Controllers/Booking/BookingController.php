<?php

declare(strict_types=1);

namespace App\Http\Controllers\Booking;

use App\Actions\Booking\StartBooking;
use App\Data\BookingRequestData;
use App\Data\Slot;
use App\Exceptions\SlotUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\StartBookingRequest;
use App\Models\Branch;
use App\Models\Service;
use App\Services\Availability\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/** Steps 1-4 of the customer journey. Thin: validate, call a service or action, render. */
class BookingController extends Controller
{
    public function __construct(private readonly AvailabilityService $availability) {}

    /** Step 1: choose a service. */
    public function services(): View
    {
        return view('booking.services', [
            'services' => Service::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /** Step 2: choose a branch (optionally filtered by city). */
    public function branches(Request $request, Service $service): View
    {
        abort_unless($service->is_active, 404);

        $offering = $this->branchesOffering($service);
        $city = $request->query('city');

        return view('booking.branches', [
            'service' => $service,
            'city' => $city,
            'cities' => $offering->pluck('city')->unique()->sort()->values(),
            'branches' => is_string($city) && $city !== '' ? $offering->where('city', $city)->values() : $offering,
        ]);
    }

    /** Step 3: the time picker page. The slots themselves load from slots() as JSON. */
    public function times(Service $service, Branch $branch): View
    {
        $this->ensureBookable($service, $branch);

        return view('booking.times', [
            'service' => $service,
            'branch' => $branch,
            'today' => CarbonImmutable::now((string) config('app.display_timezone'))->toDateString(),
        ]);
    }

    /** JSON: a week of slots, loaded by the picker without a page reload. */
    public function slots(Request $request, Service $service, Branch $branch): JsonResponse
    {
        $this->ensureBookable($service, $branch);

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'between:1,14'],
        ]);

        $tz = (string) config('app.display_timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from'], $tz)->startOfDay() : $today;
        $from = $from->lessThan($today) ? $today : $from;

        $week = $this->availability->slotsForRange($branch, $service, $from->toDateString(), (int) ($validated['days'] ?? 7));

        $days = [];
        foreach ($week as $date => $slots) {
            $days[] = [
                'date' => $date,
                'label' => CarbonImmutable::parse($date, $tz)->format('D j M'),
                'slots' => array_map(fn (Slot $slot): array => $slot->toArray() + [
                    'url' => route('book.details', ['service' => $service, 'branch' => $branch, 'starts_at' => $slot->startsAt->toIso8601ZuluString()]),
                ], $slots),
            ];
        }

        return response()->json(['from' => $from->toDateString(), 'days' => $days]);
    }

    /** Step 4: your details. */
    public function details(Request $request, Service $service, Branch $branch): View|RedirectResponse
    {
        $this->ensureBookable($service, $branch);

        $startsAt = CarbonImmutable::parse($request->validate(['starts_at' => ['required', 'date']])['starts_at'])->utc();

        // The slot may have been taken since the customer saw it.
        if ($this->availability->freeAdvisorsAt($branch, $service, $startsAt)->isEmpty()) {
            return redirect()->route('book.times', [$service, $branch])->with('error', (new SlotUnavailable)->getMessage());
        }

        return view('booking.details', [
            'service' => $service,
            'branch' => $branch,
            'startsAt' => $startsAt,
            'localStart' => $startsAt->setTimezone((string) config('app.display_timezone')),
        ]);
    }

    /** Holds the slot and emails the verification code. */
    public function store(StartBookingRequest $request, Service $service, Branch $branch, StartBooking $startBooking): RedirectResponse
    {
        $this->ensureBookable($service, $branch);

        $data = new BookingRequestData(
            service: $service,
            branch: $branch,
            startsAt: CarbonImmutable::parse($request->validated('starts_at'))->utc(),
            firstName: $request->validated('first_name'),
            lastName: $request->validated('last_name'),
            email: $request->validated('email'),
            phone: $request->phoneE164(),
            marketingOptIn: $request->boolean('marketing_opt_in'),
        );

        try {
            $appointment = $startBooking->handle($data);
        } catch (SlotUnavailable $e) {
            return redirect()->route('book.times', [$service, $branch])->with('error', $e->getMessage());
        }

        // Remember which booking this browser is verifying; the verify page checks it.
        $request->session()->put('booking.pending', $appointment->reference);

        return redirect()->route('booking.verify', $appointment->reference);
    }

    /**
     * Active branches with at least one active advisor qualified for the service.
     *
     * @return Collection<int, Branch>
     */
    private function branchesOffering(Service $service): Collection
    {
        return Branch::query()
            ->where('is_active', true)
            ->whereHas('advisors', fn ($q) => $q->where('is_active', true)
                ->whereHas('services', fn ($s) => $s->whereKey($service->id)))
            ->orderBy('city')->orderBy('name')
            ->get();
    }

    private function ensureBookable(Service $service, Branch $branch): void
    {
        abort_unless($service->is_active && $branch->is_active, 404);
    }
}
