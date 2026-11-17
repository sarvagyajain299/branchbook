<x-booking-layout title="Confirm the new time">
    <h1 class="text-2xl font-semibold">Move your appointment?</h1>

    <dl class="mt-4 space-y-3 rounded-lg border border-slate-200 bg-white p-5 text-sm">
        <div><dt class="font-medium text-slate-500">Now</dt><dd>{{ $appointment->localStart()->format('l j F Y, H:i') }}</dd></div>
        <div><dt class="font-medium text-slate-500">New time</dt><dd class="text-base font-semibold">{{ $newLocal->format('l j F Y, H:i') }} (Dubai time)</dd></div>
        <div><dt class="font-medium text-slate-500">Service</dt><dd>{{ $appointment->service->name }}, {{ $appointment->branch->name }}</dd></div>
    </dl>
    <p class="mt-3 text-sm text-slate-600">Your current time is released only after the new one is booked. You will get a new reference and a new confirmation email.</p>

    <form method="POST" action="{{ route('booking.reschedule.perform', $appointment->reference) }}" class="mt-6 flex flex-wrap gap-3">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="starts_at" value="{{ $startsAt->toIso8601ZuluString() }}">
        <button class="rounded-md bg-teal-700 px-5 py-2.5 font-medium text-white hover:bg-teal-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2">Yes, move it</button>
        <a class="rounded-md border border-slate-300 px-5 py-2.5 font-medium hover:bg-slate-100" href="{{ route('booking.reschedule', ['reference' => $appointment->reference, 'token' => $token]) }}">Choose another time</a>
    </form>
</x-booking-layout>
