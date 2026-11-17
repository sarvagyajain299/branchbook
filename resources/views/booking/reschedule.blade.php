<x-booking-layout title="Choose a new time">
    <h1 class="text-2xl font-semibold">Choose a new time</h1>
    <p class="mt-1 text-slate-600">
        {{ $appointment->service->name }} at {{ $appointment->branch->name }}. Your current appointment is
        {{ $appointment->localStart()->format('l j F, H:i') }} and stays booked until you confirm a new time. Times are shown in Dubai time.
    </p>

    <div id="slot-picker" class="mt-6" data-url="{{ route('booking.reschedule.slots', ['reference' => $appointment->reference, 'token' => $token]) }}" data-from="{{ $today }}">
        <div class="flex items-center justify-between">
            <button type="button" data-prev class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-100 disabled:opacity-40">&larr; Earlier</button>
            <button type="button" data-next class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-100">Later &rarr;</button>
        </div>
        <p data-status aria-live="polite" class="mt-4 text-sm text-slate-600">Loading available times…</p>
        <div data-days class="mt-3 grid gap-3 sm:grid-cols-2"></div>
        <noscript><p class="mt-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm">Choosing a time needs JavaScript. Please enable it or call the branch on {{ $appointment->branch->phone }}.</p></noscript>
    </div>

    <p class="mt-8 text-sm"><a class="text-teal-800 underline" href="{{ route('booking.show', ['reference' => $appointment->reference, 'token' => $token]) }}">Keep my current appointment</a></p>
</x-booking-layout>
