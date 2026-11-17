<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

it('stores the phone number encrypted at rest and reads it back as plain text', function (): void {
    $customer = Customer::factory()->create(['phone' => '+971501234567']);

    $raw = (string) DB::table('customers')->where('id', $customer->id)->value('phone');

    expect($raw)->not->toContain('971501234567')
        ->and($customer->fresh()->phone)->toBe('+971501234567');
});

it('masks customer contact details for staff screens', function (): void {
    $customer = Customer::factory()->create(['email' => 'sarah.jones@example.com', 'phone' => '+971501234567']);

    expect($customer->maskedEmail())->toBe('s***@example.com')
        ->and($customer->maskedPhone())->toBe('+971 ••• ••• 567');
});

it('has no column for Emirates ID, account or card numbers', function (): void {
    $columns = collect(Schema::getColumnListing('customers'))->implode(',');

    expect($columns)->not->toMatch('/emirates|eid|account|card|iban|passport/i');
});

it('appends audit log entries but never edits or deletes them', function (): void {
    $log = AuditLog::query()->create(['action' => 'appointment.cancelled', 'changes' => ['status' => ['confirmed', 'cancelled']]]);

    expect($log->fresh()->changes)->toBe(['status' => ['confirmed', 'cancelled']]);

    expect(fn () => $log->update(['action' => 'tampered']))->toThrow(LogicException::class);
    expect(fn () => $log->delete())->toThrow(LogicException::class);
    expect(AuditLog::query()->count())->toBe(1);
});
