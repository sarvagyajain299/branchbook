<x-mail::message>
# Your appointment is confirmed

**{{ $appointment->service->name }}**
{{ $when }}
{{ $appointment->branch->name }}, {{ $appointment->branch->address }}

Booking reference: **{{ $appointment->reference }}**

## Please bring
@foreach ($appointment->service->required_documents as $document)
- {{ $document }}
@endforeach

<x-mail::button :url="$manageUrl">
View, add to calendar or cancel
</x-mail::button>

Keep this email: the button above is your private link to manage the booking. We have attached a calendar file. Northwind Bank will never ask for your card number, account number or Emirates ID number by email.
</x-mail::message>
