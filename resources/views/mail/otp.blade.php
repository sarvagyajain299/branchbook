<x-mail::message>
# Your verification code

Use this code to confirm your Northwind Bank appointment **{{ $reference }}**:

<x-mail::panel>
{{ $code }}
</x-mail::panel>

The code expires in {{ $minutes }} minutes. Never share it with anyone. Northwind Bank will never ask for this code, or for your card number, account number or Emirates ID number.
</x-mail::message>
