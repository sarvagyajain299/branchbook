<?php

declare(strict_types=1);

use App\Support\BookingReference;
use App\Support\Mask;

it('masks emails', function (): void {
    expect(Mask::email('sarah.jones@example.com'))->toBe('s***@example.com')
        ->and(Mask::email('not-an-email'))->toBe('***');
});

it('masks phone numbers, keeping only country code and last 3 digits', function (): void {
    expect(Mask::phone('+971501234567'))->toBe('+971 ••• ••• 567')
        ->and(Mask::phone('12'))->toBe('••••');
});

it('generates booking references in the NWB-XXXX-XXXX format without look-alike characters', function (): void {
    for ($i = 0; $i < 200; $i++) {
        expect(BookingReference::generate())->toMatch('/^NWB-[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}$/');
    }
});
