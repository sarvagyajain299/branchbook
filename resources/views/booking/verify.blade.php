<x-booking-layout :step="5" title="Verify your email">
    <h1 class="text-2xl font-semibold">Check your email</h1>
    <p class="mt-2 text-slate-700">
        We sent a 6-digit code to <strong>{{ $maskedEmail }}</strong>. Your time is held until
        <strong>{{ $appointment->hold_expires_at?->setTimezone(config('app.display_timezone'))->format('H:i') }}</strong> (Dubai time).
    </p>

    <form method="POST" action="{{ route('booking.verify.submit', $appointment->reference) }}" class="mt-6 max-w-sm space-y-4" novalidate>
        @csrf
        <div>
            <label for="code" class="block text-sm font-medium">Verification code</label>
            <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required autofocus
                   @error('code') aria-invalid="true" aria-describedby="code_error" @enderror
                   class="mt-1 w-full rounded-md border-slate-300 text-center text-2xl tracking-[0.5em]">
            @error('code') <p id="code_error" role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <button class="rounded-md bg-teal-700 px-5 py-2.5 font-medium text-white hover:bg-teal-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2">Confirm my appointment</button>
    </form>

    <form method="POST" action="{{ route('booking.verify.resend', $appointment->reference) }}" class="mt-6">
        @csrf
        <button class="text-sm text-teal-800 underline">Send me a new code</button>
    </form>
</x-booking-layout>
