<?php

declare(strict_types=1);

namespace App\Actions\Maintenance;

use App\Models\Customer;
use App\Models\OtpCode;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Data retention: customers with no activity for 24 months lose their name, email and phone.
 * Their appointments stay (with the customer row now anonymous), so reports and statistics are
 * unchanged. Customers with an upcoming active appointment are never touched.
 */
final class AnonymiseInactiveCustomers
{
    public const DEFAULT_MONTHS = 24;

    /**
     * @return int how many customers were (or, with $dryRun, would be) anonymised
     */
    public function handle(int $months = self::DEFAULT_MONTHS, bool $dryRun = false, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $cutoff = $now->subMonths($months);
        $count = 0;

        // Last activity, or the sign-up date when there never was any.
        $query = Customer::query()
            ->whereNull('anonymised_at')
            ->whereRaw('COALESCE(last_activity_at, created_at) < ?', [$cutoff])
            ->whereDoesntHave('appointments', fn ($q) => $q->active()->where('ends_at', '>=', $now));

        $query->chunkById(200, function ($customers) use ($dryRun, $now, &$count): void {
            foreach ($customers as $customer) {
                $count++;

                if (! $dryRun) {
                    $this->anonymise($customer, $now);
                }
            }
        });

        if (! $dryRun && $count > 0) {
            Audit::record('customers.anonymised', null, ['count' => $count, 'inactive_months' => $months], null);
        }

        return $count;
    }

    private function anonymise(Customer $customer, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($customer, $now): void {
            // Verification codes are keyed by email: remove them before the email disappears.
            OtpCode::query()->where('email', $customer->email)->delete();

            $customer->forceFill([
                'first_name' => 'Anonymised',
                'last_name' => 'Customer',
                'email' => "anonymised-{$customer->id}@invalid.example",   // .invalid is reserved: it can never receive mail
                'phone' => '+000000000000',
                'email_verified_at' => null,
                'marketing_opt_in' => false,
                'anonymised_at' => $now,
            ])->save();
        });
    }
}
