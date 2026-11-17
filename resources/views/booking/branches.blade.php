<x-booking-layout :step="2" title="Choose a branch">
    <h1 class="text-2xl font-semibold">Choose a branch for {{ $service->name }}</h1>

    <form method="GET" action="{{ route('book.branches', $service) }}" class="mt-4 flex flex-wrap items-end gap-3">
        <div>
            <label for="city" class="block text-sm font-medium">City</label>
            <select id="city" name="city" class="mt-1 rounded-md border-slate-300 text-sm">
                <option value="">All cities</option>
                @foreach ($cities as $option)
                    <option value="{{ $option }}" @selected($option === $city)>{{ $option }}</option>
                @endforeach
            </select>
        </div>
        <button class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-100">Filter</button>
    </form>

    <ul class="mt-6 grid gap-4 sm:grid-cols-2">
        @forelse ($branches as $branch)
            <li class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="text-lg font-semibold">{{ $branch->name }}</h2>
                <p class="text-sm text-slate-600">{{ $branch->address }}</p>
                <p class="text-sm text-slate-600">{{ $branch->phone }}</p>
                <a href="{{ route('book.times', [$service, $branch]) }}"
                   class="mt-4 inline-block rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2">
                    Choose<span class="sr-only"> {{ $branch->name }}</span>
                </a>
            </li>
        @empty
            <li class="text-slate-600">No branches offer this service in that city.</li>
        @endforelse
    </ul>
</x-booking-layout>
