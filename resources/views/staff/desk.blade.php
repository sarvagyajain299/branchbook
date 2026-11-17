<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Front desk · {{ $branch->name }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <div role="status" class="rounded-md border border-teal-300 bg-teal-50 px-4 py-3 text-teal-900">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div role="alert" class="rounded-md border border-red-300 bg-red-50 px-4 py-3 text-red-900">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div role="alert" class="rounded-md border border-red-300 bg-red-50 px-4 py-3 text-red-900">{{ $errors->first() }}</div>
            @endif

            <section class="rounded-lg bg-white p-5 shadow-sm" aria-labelledby="walkin">
                <h3 id="walkin" class="text-lg font-semibold">Walk-in ticket</h3>
                <form method="POST" action="{{ route('desk.walk-in', $branch) }}" class="mt-3 flex flex-wrap items-end gap-3">
                    @csrf
                    <div>
                        <label for="service_id" class="block text-sm font-medium">Service</label>
                        <select id="service_id" name="service_id" required class="mt-1 rounded-md border-gray-300 text-sm">
                            @foreach ($services as $service)
                                <option value="{{ $service->id }}">{{ $service->name }} ({{ $service->queue_prefix }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="name" class="block text-sm font-medium">Name (optional)</label>
                        <input id="name" name="name" maxlength="60" class="mt-1 rounded-md border-gray-300 text-sm">
                    </div>
                    <button class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800">Issue ticket</button>
                </form>
            </section>

            <section class="rounded-lg bg-white p-5 shadow-sm" aria-labelledby="today">
                <h3 id="today" class="text-lg font-semibold">Today's appointments</h3>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-start text-sm">
                        <thead class="border-b text-gray-500">
                            <tr>
                                <th scope="col" class="py-2 pe-4 text-start">Time</th>
                                <th scope="col" class="py-2 pe-4 text-start">Customer</th>
                                <th scope="col" class="py-2 pe-4 text-start">Service</th>
                                <th scope="col" class="py-2 pe-4 text-start">Advisor</th>
                                <th scope="col" class="py-2 pe-4 text-start">Status</th>
                                <th scope="col" class="py-2 text-start">Ticket</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @forelse ($appointments as $appointment)
                                <tr>
                                    <td class="py-2 pe-4 whitespace-nowrap">{{ $appointment->starts_at->setTimezone($tz)->format('H:i') }}</td>
                                    <td class="py-2 pe-4">
                                        {{ $appointment->customer->first_name }} {{ mb_substr($appointment->customer->last_name, 0, 1) }}.
                                        {{-- Front desk sees masked contact details only. --}}
                                        <span class="block text-xs text-gray-500">{{ $appointment->customer->maskedEmail() }} · {{ $appointment->customer->maskedPhone() }}</span>
                                    </td>
                                    <td class="py-2 pe-4">{{ $appointment->service->name }}</td>
                                    <td class="py-2 pe-4">{{ $appointment->advisor->user->name }}</td>
                                    <td class="py-2 pe-4">{{ $appointment->status->label() }}</td>
                                    <td class="py-2">
                                        @if ($appointment->queueTicket)
                                            <strong>{{ $appointment->queueTicket->display_code }}</strong>
                                        @elseif ($appointment->status === App\Enums\AppointmentStatus::Confirmed)
                                            <form method="POST" action="{{ route('desk.check-in', [$branch, $appointment]) }}">
                                                @csrf
                                                <button class="rounded-md bg-teal-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-teal-800">Check in<span class="sr-only"> {{ $appointment->customer->first_name }}</span></button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="py-4 text-gray-500">No appointments today.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-lg bg-white p-5 shadow-sm" aria-labelledby="waiting">
                <h3 id="waiting" class="text-lg font-semibold">Waiting now ({{ $waiting->count() }})</h3>
                <ul class="mt-2 flex flex-wrap gap-2">
                    @forelse ($waiting as $ticket)
                        <li class="rounded-md border px-3 py-1 text-sm"><strong>{{ $ticket->display_code }}</strong> {{ $ticket->service->name }}</li>
                    @empty
                        <li class="text-sm text-gray-500">Nobody waiting.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
</x-app-layout>
