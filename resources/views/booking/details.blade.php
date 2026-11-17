<x-booking-layout :step="4" title="Your details">
    <h1 class="text-2xl font-semibold">Your details</h1>

    <div class="mt-4 rounded-lg border border-slate-200 bg-white p-4 text-sm">
        <p class="font-semibold">{{ $service->name }}</p>
        <p>{{ $localStart->format('l j F Y') }} at {{ $localStart->format('H:i') }} (Dubai time), {{ $service->duration_minutes }} minutes</p>
        <p class="text-slate-600">{{ $branch->name }}, {{ $branch->address }}</p>
    </div>

    <div class="mt-4 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="note">
        <strong>Please do not enter</strong> your card number, account number, IBAN or Emirates ID number anywhere on this form.
        Northwind Bank will never ask for them here, by email or by phone before your visit.
    </div>

    <form method="POST" action="{{ route('book.store', [$service, $branch]) }}" class="mt-6 space-y-5" novalidate>
        @csrf
        <input type="hidden" name="starts_at" value="{{ $startsAt->toIso8601ZuluString() }}">
        @error('starts_at') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="first_name" class="block text-sm font-medium">First name</label>
                <input id="first_name" name="first_name" value="{{ old('first_name') }}" required autocomplete="given-name"
                       @error('first_name') aria-invalid="true" aria-describedby="first_name_error" @enderror
                       class="mt-1 w-full rounded-md border-slate-300">
                @error('first_name') <p id="first_name_error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="last_name" class="block text-sm font-medium">Last name</label>
                <input id="last_name" name="last_name" value="{{ old('last_name') }}" required autocomplete="family-name"
                       @error('last_name') aria-invalid="true" aria-describedby="last_name_error" @enderror
                       class="mt-1 w-full rounded-md border-slate-300">
                @error('last_name') <p id="last_name_error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label for="email" class="block text-sm font-medium">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email"
                   aria-describedby="email_hint @error('email') email_error @enderror" @error('email') aria-invalid="true" @enderror
                   class="mt-1 w-full rounded-md border-slate-300">
            <p id="email_hint" class="mt-1 text-sm text-slate-500">We send a 6-digit code here to confirm your booking.</p>
            @error('email') <p id="email_error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="phone" class="block text-sm font-medium">UAE mobile number</label>
            <input id="phone" type="tel" name="phone" value="{{ old('phone') }}" required autocomplete="tel" placeholder="050 123 4567"
                   @error('phone') aria-invalid="true" aria-describedby="phone_error" @enderror
                   class="mt-1 w-full rounded-md border-slate-300">
            @error('phone') <p id="phone_error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="flex items-start gap-3 text-sm">
                <input type="checkbox" name="consent" value="1" required class="mt-1 rounded border-slate-300" @checked(old('consent'))
                       @error('consent') aria-invalid="true" aria-describedby="consent_error" @enderror>
                <span>I agree that Northwind Bank may use my name, email and phone number to manage this appointment.</span>
            </label>
            @error('consent') <p id="consent_error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-start gap-3 text-sm">
            <input type="checkbox" name="marketing_opt_in" value="1" class="mt-1 rounded border-slate-300" @checked(old('marketing_opt_in'))>
            <span>Optional: send me news about Northwind Bank services.</span>
        </label>

        <button class="rounded-md bg-teal-700 px-5 py-2.5 font-medium text-white hover:bg-teal-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2">
            Hold this time and send my code
        </button>
        <p class="text-sm text-slate-500">We hold the time for 10 minutes while you confirm.</p>
    </form>
</x-booking-layout>
