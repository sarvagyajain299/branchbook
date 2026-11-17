@php
    $local = $appointment->localStart();
    $badge = match ($appointment->status) {
        App\Enums\AppointmentStatus::Confirmed => 'bg-teal-100 text-teal-900',
        App\Enums\AppointmentStatus::Cancelled, App\Enums\AppointmentStatus::NoShow, App\Enums\AppointmentStatus::Expired => 'bg-red-100 text-red-900',
        default => 'bg-slate-200 text-slate-800',
    };
@endphp
<x-booking-layout :step="$appointment->status === App\Enums\AppointmentStatus::Confirmed ? 6 : null" title="Your appointment">
    <h1 class="text-2xl font-semibold">Your appointment</h1>

    <div class="mt-4 rounded-lg border border-slate-200 bg-white p-5">
        <p class="text-sm text-slate-500">Booking reference</p>
        <p class="text-xl font-semibold tracking-wide">{{ $appointment->reference }}</p>
        <span class="mt-2 inline-block rounded-full px-3 py-1 text-xs font-medium {{ $badge }}">{{ $appointment->status->label() }}</span>

        <dl class="mt-4 space-y-2 text-sm">
            <div><dt class="inline font-medium">Service:</dt> <dd class="inline">{{ $appointment->service->name }}</dd></div>
            <div><dt class="inline font-medium">When:</dt> <dd class="inline">{{ $local->format('l j F Y') }} at {{ $local->format('H:i') }} (Dubai time)</dd></div>
            <div><dt class="inline font-medium">Where:</dt> <dd class="inline">{{ $appointment->branch->name }}, {{ $appointment->branch->address }} ({{ $appointment->branch->phone }})</dd></div>
            <div><dt class="inline font-medium">Name:</dt> <dd class="inline">{{ $appointment->customer->fullName() }}</dd></div>
        </dl>
    </div>

    @if ($appointment->status === App\Enums\AppointmentStatus::Confirmed)
        <section class="mt-6" aria-labelledby="bring">
            <h2 id="bring" class="text-lg font-semibold">Please bring</h2>
            <ul class="mt-2 list-disc space-y-1 ps-6 text-sm">
                @foreach ($appointment->service->required_documents as $document)
                    <li>{{ $document }}</li>
                @endforeach
            </ul>
        </section>

        <p class="mt-6">
            <a href="{{ route('booking.calendar', ['reference' => $appointment->reference, 'token' => $token]) }}"
               class="inline-block rounded-md border border-teal-700 px-4 py-2 text-sm font-medium text-teal-800 hover:bg-teal-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2">Add to calendar (.ics)</a>
        </p>

        @if ($canCancel)
            <p class="mt-4">
                <a href="{{ route('booking.reschedule', ['reference' => $appointment->reference, 'token' => $token]) }}"
                   class="inline-block rounded-md border border-teal-700 px-4 py-2 text-sm font-medium text-teal-800 hover:bg-teal-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2">Reschedule</a>
            </p>
        @endif

        <section class="mt-8" aria-labelledby="cancel">
            <h2 id="cancel" class="text-lg font-semibold">Cancel this appointment</h2>
            @if ($canCancel)
                <form method="POST" action="{{ route('booking.cancel', $appointment->reference) }}" class="mt-3 max-w-md space-y-3">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <div>
                        <label for="reason" class="block text-sm font-medium">Reason (optional)</label>
                        <input id="reason" name="reason" maxlength="255" class="mt-1 w-full rounded-md border-slate-300">
                    </div>
                    <button class="rounded-md border border-red-600 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2">Cancel appointment</button>
                </form>
            @else
                <p class="mt-2 text-sm text-slate-600">Online cancellation closes {{ $cutoffHours }} hours before the appointment. Please call the branch on {{ $appointment->branch->phone }}.</p>
            @endif
        </section>
    @endif
</x-booking-layout>
