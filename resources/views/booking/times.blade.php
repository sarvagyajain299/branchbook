<x-booking-layout :step="3" title="Choose a time">
    <h1 class="text-2xl font-semibold">{{ $service->name }} at {{ $branch->name }}</h1>
    <p class="mt-1 text-slate-600">{{ $service->duration_minutes }} minutes. Times are shown in Dubai time.</p>

    <div id="slot-picker" class="mt-6" data-url="{{ route('book.slots', [$service, $branch]) }}" data-from="{{ $today }}">
        <div class="flex items-center justify-between">
            <button type="button" data-prev class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-100 disabled:opacity-40">&larr; Earlier</button>
            <button type="button" data-next class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-100">Later &rarr;</button>
        </div>

        <p data-status aria-live="polite" class="mt-4 text-sm text-slate-600">Loading available times…</p>
        <div data-days class="mt-3 grid gap-3 sm:grid-cols-2"></div>

        <noscript><p class="mt-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm">Choosing a time needs JavaScript. Please enable it or call the branch on {{ $branch->phone }}.</p></noscript>
    </div>

    <p class="mt-8 text-sm"><a class="text-teal-800 underline" href="{{ route('book.branches', $service) }}">Choose a different branch</a></p>
</x-booking-layout>
