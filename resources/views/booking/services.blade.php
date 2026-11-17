<x-booking-layout :step="1" title="Choose a service">
    <h1 class="text-2xl font-semibold">What would you like to talk about?</h1>
    <p class="mt-1 text-slate-600">Choose a service to see branches and times.</p>

    <ul class="mt-6 grid gap-4 sm:grid-cols-2">
        @foreach ($services as $service)
            <li class="flex flex-col rounded-lg border border-slate-200 bg-white p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-teal-700">{{ $service->category }}</p>
                <h2 class="mt-1 text-lg font-semibold">{{ $service->name }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ $service->description }}</p>
                <p class="mt-3 text-sm"><span class="font-medium">{{ $service->duration_minutes }} minutes</span></p>

                <details class="mt-3 text-sm">
                    <summary class="cursor-pointer text-teal-800">What to bring</summary>
                    <ul class="ms-5 mt-2 list-disc text-slate-700">
                        @foreach ($service->required_documents as $document)
                            <li>{{ $document }}</li>
                        @endforeach
                    </ul>
                </details>

                <a href="{{ route('book.branches', $service) }}"
                   class="mt-5 inline-block self-start rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2">
                    Choose<span class="sr-only"> {{ $service->name }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</x-booking-layout>
