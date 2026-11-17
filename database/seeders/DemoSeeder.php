<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Advisor;
use App\Models\AdvisorShift;
use App\Models\AdvisorTimeOff;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\BranchHour;
use App\Models\Closure;
use App\Models\Customer;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fictional Northwind Bank demo data: 3 branches, 6 services, 12 advisors, staff for every role,
 * ~150 past and future appointments and a week of queue history (so reports have data).
 * Every login uses the password "password". Development only.
 */
class DemoSeeder extends Seeder
{
    /** Appointment start times (Dubai local) per ISO weekday. Saturday and Sunday: closed. */
    private const GRID = [
        1 => ['08:00', '09:30', '11:00', '12:30'],
        2 => ['08:00', '09:30', '11:00', '12:30'],
        3 => ['08:00', '09:30', '11:00', '12:30'],
        4 => ['08:00', '09:30', '11:00', '12:30'],
        5 => ['08:00', '09:30'],
    ];

    private const ADVISOR_NAMES = [
        'Omar Haddad', 'Layla Nasser', 'Yusuf Rahman', 'Mariam Qureshi',
        'Khalid Mansour', 'Noura Saeed', 'Tariq Aziz', 'Hana Farouk',
        'Rashid Karim', 'Salma Idris', 'Faisal Jabari', 'Dina Mazen',
    ];

    public function run(): void
    {
        mt_srand(2026);
        fake()->seed(2026);

        DB::transaction(function (): void {
            $services = $this->seedServices();
            $branches = $this->seedBranches();
            $closureDates = $this->seedHoursAndClosures($branches);
            $advisors = $this->seedStaff($branches, $services);
            $customers = Customer::factory()->count(90)->create();
            $appointments = $this->seedAppointments($branches, $advisors, $customers, $closureDates);
            $this->seedQueueHistory($branches, $advisors, $services, $appointments, $closureDates);
        });
    }

    /** @return Collection<string, Service> keyed by service code */
    private function seedServices(): Collection
    {
        $rows = [
            ['PAO', 'Personal Account Opening', 'Accounts', 'Open a current or savings account with one of our advisors.', 45, 15, 'A',
                ['Passport (original)', 'UAE residence visa', 'Emirates ID (original)', 'Proof of address, e.g. utility bill or tenancy contract', 'Latest salary certificate or payslip']],
            ['MTG', 'Mortgage Consultation', 'Lending', 'Discuss home finance options, eligibility and the application process.', 60, 15, 'M',
                ['Passport and Emirates ID (originals)', 'Last 6 months of bank statements', 'Salary certificate', 'Property details, if already chosen', 'Statements for any existing loans']],
            ['CRD', 'Card Services', 'Cards', 'Replace, upgrade or ask about a debit or credit card.', 30, 0, 'C',
                ['Emirates ID (original)', 'The card concerned, if replacing or upgrading']],
            ['WLT', 'Wealth Advisory', 'Wealth', 'Plan savings and investments with a wealth advisor.', 60, 15, 'W',
                ['Passport and Emirates ID (originals)', 'Summary of your source of funds', 'Recent statements for investments held elsewhere']],
            ['REM', 'Remittances', 'Payments', 'Send money abroad or set up a regular transfer.', 20, 0, 'R',
                ['Emirates ID (original)', "Recipient's full name, bank and country", 'Purpose of the transfer']],
            ['BAO', 'Business Account Opening', 'Business', 'Open a business account for a new or existing company.', 60, 15, 'B',
                ['Trade licence', 'Memorandum of association', 'Passports and Emirates IDs of all owners', 'Lease contract (Ejari)', 'Board resolution, for companies']],
        ];

        return collect($rows)->mapWithKeys(function (array $r): array {
            $service = Service::query()->create([
                'code' => $r[0], 'name' => $r[1], 'category' => $r[2], 'description' => $r[3],
                'duration_minutes' => $r[4], 'buffer_minutes' => $r[5], 'queue_prefix' => $r[6],
                'required_documents' => $r[7], 'min_notice_hours' => 2, 'max_days_ahead' => 30,
                'is_active' => true,
            ]);

            return [$r[0] => $service];
        });
    }

    /** @return Collection<string, Branch> keyed by branch code */
    private function seedBranches(): Collection
    {
        $rows = [
            ['DXB-MAR', 'Northwind Dubai Marina', 'Dubai', 'Marina Walk, Dubai Marina', 25.0805, 55.1403, '+97144001001'],
            ['AUH-COR', 'Northwind Abu Dhabi Corniche', 'Abu Dhabi', 'Corniche Road West, Abu Dhabi', 24.4745, 54.3320, '+97126001002'],
            ['SHJ-MAJ', 'Northwind Sharjah Al Majaz', 'Sharjah', 'Al Majaz Waterfront, Sharjah', 25.3300, 55.3850, '+97165001003'],
        ];

        return collect($rows)->mapWithKeys(fn (array $r): array => [$r[0] => Branch::query()->create([
            'code' => $r[0], 'name' => $r[1], 'city' => $r[2], 'address' => $r[3],
            'latitude' => $r[4], 'longitude' => $r[5], 'phone' => $r[6], 'is_active' => true,
        ])]);
    }

    /**
     * UAE banking week: Mon-Thu 08:00-15:00, Fri 08:00-12:00, weekend closed.
     *
     * @param  Collection<string, Branch>  $branches
     * @return array<string, array<string, true>> closure dates, keyed by "all" or branch code
     */
    private function seedHoursAndClosures(Collection $branches): array
    {
        foreach ($branches as $branch) {
            foreach ([1, 2, 3, 4] as $weekday) {
                BranchHour::query()->create(['branch_id' => $branch->id, 'weekday' => $weekday, 'opens_at' => '08:00', 'closes_at' => '15:00']);
            }
            BranchHour::query()->create(['branch_id' => $branch->id, 'weekday' => 5, 'opens_at' => '08:00', 'closes_at' => '12:00']);
        }

        // Islamic holiday dates depend on moon sighting and are announced late: verify each year.
        $verify = ' (verify Islamic dates)';
        $holidays = [
            '2026-01-01' => "New Year's Day",
            '2026-03-20' => 'Eid al-Fitr'.$verify, '2026-03-21' => 'Eid al-Fitr'.$verify, '2026-03-22' => 'Eid al-Fitr'.$verify,
            '2026-05-26' => 'Arafat Day'.$verify,
            '2026-05-27' => 'Eid al-Adha'.$verify, '2026-05-28' => 'Eid al-Adha'.$verify, '2026-05-29' => 'Eid al-Adha'.$verify,
            '2026-06-16' => 'Islamic New Year'.$verify,
            '2026-08-25' => "Prophet's Birthday".$verify,
            '2026-12-02' => 'National Day', '2026-12-03' => 'National Day',
            '2027-01-01' => "New Year's Day",
            '2027-03-09' => 'Eid al-Fitr'.$verify, '2027-03-10' => 'Eid al-Fitr'.$verify, '2027-03-11' => 'Eid al-Fitr'.$verify,
            '2027-05-15' => 'Arafat Day'.$verify,
            '2027-05-16' => 'Eid al-Adha'.$verify, '2027-05-17' => 'Eid al-Adha'.$verify, '2027-05-18' => 'Eid al-Adha'.$verify,
            '2027-06-06' => 'Islamic New Year'.$verify,
            '2027-08-14' => "Prophet's Birthday".$verify,
            '2027-12-02' => 'National Day', '2027-12-03' => 'National Day',
        ];

        $dates = ['all' => []];
        foreach ($holidays as $date => $reason) {
            Closure::query()->create(['branch_id' => null, 'date' => $date, 'reason' => $reason]);
            $dates['all'][$date] = true;
        }

        // One ad-hoc, single-branch closure to show both kinds exist.
        $sharjah = $branches['SHJ-MAJ'];
        Closure::query()->create(['branch_id' => $sharjah->id, 'date' => '2026-12-14', 'reason' => 'Building maintenance']);
        $dates['SHJ-MAJ'] = ['2026-12-14' => true];

        return $dates;
    }

    /**
     * Staff users for every role, plus advisors with skills, shifts and one leave period.
     *
     * @param  Collection<string, Branch>  $branches
     * @param  Collection<string, Service>  $services
     * @return Collection<int, Advisor>
     */
    private function seedStaff(Collection $branches, Collection $services): Collection
    {
        User::factory()->hqAdmin()->create(['name' => 'Huda Al Mazrouei (HQ)', 'email' => 'hq@northwind.test']);

        // Every branch gets the same four skill sets, so every service is available everywhere.
        $skillSets = [['PAO', 'CRD', 'REM'], ['PAO', 'MTG', 'WLT'], ['CRD', 'REM', 'BAO'], ['MTG', 'WLT', 'BAO', 'PAO']];
        $advisors = collect();
        $nameIndex = 0;

        foreach ($branches as $code => $branch) {
            $key = strtolower(substr($code, 0, 3));

            User::factory()->forBranch(Role::BranchManager, $branch)
                ->create(['name' => $branch->city.' Branch Manager', 'email' => "manager.{$key}@northwind.test"]);
            User::factory()->forBranch(Role::FrontDesk, $branch)
                ->create(['name' => $branch->city.' Front Desk', 'email' => "desk.{$key}@northwind.test"]);

            foreach ($skillSets as $i => $skills) {
                $user = User::factory()->forBranch(Role::Advisor, $branch)->create([
                    'name' => self::ADVISOR_NAMES[$nameIndex++],
                    'email' => 'advisor.'.$key.($i + 1).'@northwind.test',
                ]);
                $advisor = Advisor::query()->create([
                    'user_id' => $user->id, 'branch_id' => $branch->id,
                    'desk_label' => 'Desk '.($i + 1), 'is_active' => true,
                ]);
                $advisor->services()->attach($services->only($skills)->pluck('id')->all());

                foreach ([1, 2, 3, 4] as $weekday) {
                    AdvisorShift::query()->create(['advisor_id' => $advisor->id, 'weekday' => $weekday, 'starts_at' => '08:00', 'ends_at' => '15:00']);
                }
                AdvisorShift::query()->create(['advisor_id' => $advisor->id, 'weekday' => 5, 'starts_at' => '08:00', 'ends_at' => '12:00']);

                $advisors->push($advisor->load('services'));
            }
        }

        // One advisor on leave later this week, to exercise the availability engine.
        $leaveStart = CarbonImmutable::now('Asia/Dubai')->addDays(3)->startOfDay()->utc();
        AdvisorTimeOff::query()->create([
            'advisor_id' => $advisors->first()?->id,
            'starts_at' => $leaveStart, 'ends_at' => $leaveStart->addDay(), 'reason' => 'Annual leave',
        ]);

        return $advisors;
    }

    /**
     * @param  Collection<string, Branch>  $branches
     * @param  Collection<int, Advisor>  $advisors
     * @param  Collection<int, Customer>  $customers
     * @param  array<string, array<string, true>>  $closureDates
     * @return Collection<int, Appointment>
     */
    private function seedAppointments(Collection $branches, Collection $advisors, Collection $customers, array $closureDates): Collection
    {
        $today = CarbonImmutable::now('Asia/Dubai')->startOfDay();
        $now = CarbonImmutable::now();
        $created = collect();

        foreach ($branches as $code => $branch) {
            foreach ($advisors->where('branch_id', $branch->id) as $advisor) {
                $skills = $advisor->services->values();

                for ($offset = -10; $offset <= 7; $offset++) {
                    $day = $today->addDays($offset);
                    $date = $day->toDateString();

                    if (! isset(self::GRID[$day->dayOfWeekIso]) || isset($closureDates['all'][$date]) || isset($closureDates[$code][$date])) {
                        continue;
                    }

                    foreach (self::GRID[$day->dayOfWeekIso] as $time) {
                        if (mt_rand(1, 100) > 25) {
                            continue;
                        }

                        /** @var Service $service */
                        $service = $skills[mt_rand(0, $skills->count() - 1)];
                        $start = CarbonImmutable::parse("$date $time", 'Asia/Dubai')->utc();
                        $end = $start->addMinutes($service->duration_minutes);
                        $roll = mt_rand(1, 100);

                        $status = match (true) {
                            $end->lessThan($now) => $roll <= 75 ? AppointmentStatus::Completed : ($roll <= 90 ? AppointmentStatus::NoShow : AppointmentStatus::Cancelled),
                            default => $roll <= 90 ? AppointmentStatus::Confirmed : AppointmentStatus::Cancelled,
                        };

                        $created->push(Appointment::factory()->state([
                            'customer_id' => $customers[mt_rand(0, $customers->count() - 1)]->id,
                            'branch_id' => $branch->id,
                            'service_id' => $service->id,
                            'advisor_id' => $advisor->id,
                            'starts_at' => $start,
                            'ends_at' => $end,
                            'status' => $status,
                            'cancelled_reason' => $status === AppointmentStatus::Cancelled ? 'Customer cancelled via link' : null,
                        ])->create());
                    }
                }
            }
        }

        return $created;
    }

    /**
     * A week of queue tickets: one per completed appointment plus walk-ins, numbered per
     * branch, day and prefix in the order they were issued.
     *
     * @param  Collection<string, Branch>  $branches
     * @param  Collection<int, Advisor>  $advisors
     * @param  Collection<string, Service>  $services
     * @param  Collection<int, Appointment>  $appointments
     * @param  array<string, array<string, true>>  $closureDates
     */
    private function seedQueueHistory(Collection $branches, Collection $advisors, Collection $services, Collection $appointments, array $closureDates): void
    {
        $today = CarbonImmutable::now('Asia/Dubai')->startOfDay();
        $weekAgo = $today->subDays(7);

        /** @var list<array<string, mixed>> $rows */
        $rows = [];

        foreach ($appointments as $a) {
            if ($a->status !== AppointmentStatus::Completed || $a->starts_at->lessThan($weekAgo)) {
                continue;
            }
            $service = $services->firstWhere('id', $a->service_id);
            $issued = $a->starts_at->subMinutes(mt_rand(0, 12));
            $called = $a->starts_at->addMinutes(mt_rand(0, 15));
            $advisor = $advisors->firstWhere('id', $a->advisor_id);

            $rows[] = $this->ticketRow($a->branch_id, $a->service_id, (string) $service?->queue_prefix, $issued, $called, $a->ends_at->addMinutes(mt_rand(0, 8)), $a->advisor_id, (string) $advisor?->desk_label, $a->id, true);
        }

        foreach ($branches as $code => $branch) {
            for ($offset = -7; $offset <= -1; $offset++) {
                $day = $today->addDays($offset);
                $date = $day->toDateString();

                if (! isset(self::GRID[$day->dayOfWeekIso]) || isset($closureDates['all'][$date]) || isset($closureDates[$code][$date])) {
                    continue;
                }

                for ($i = mt_rand(5, 8); $i > 0; $i--) {
                    /** @var Service $service */
                    $service = $services->values()[mt_rand(0, $services->count() - 1)];
                    $qualified = $advisors->where('branch_id', $branch->id)
                        ->filter(fn (Advisor $x): bool => $x->services->contains('id', $service->id))->values();
                    /** @var Advisor $advisor */
                    $advisor = $qualified[mt_rand(0, $qualified->count() - 1)];

                    $issued = CarbonImmutable::parse("$date 08:00", 'Asia/Dubai')->utc()->addMinutes(mt_rand(0, 240));
                    $called = $issued->addMinutes(mt_rand(3, 40));
                    $completed = $called->addMinutes($service->duration_minutes + mt_rand(-5, 10));

                    $rows[] = $this->ticketRow($branch->id, $service->id, $service->queue_prefix, $issued, $called, $completed, $advisor->id, $advisor->desk_label, null, mt_rand(1, 100) > 8);
                }
            }
        }

        // Number each (branch, local day, prefix) group in issue order: A-001, A-002, ...
        $groups = collect($rows)->groupBy(fn (array $r): string => $r['branch_id'].'|'.$r['service_date'].'|'.$r['queue_prefix']);

        foreach ($groups as $group) {
            $sequence = 0;
            foreach ($group->sortBy(fn (array $r) => $r['issued_at']->getTimestamp()) as $row) {
                $sequence++;
                $row['sequence'] = $sequence;
                $row['display_code'] = sprintf('%s-%03d', $row['queue_prefix'], $sequence);
                $status = $row['status'];
                unset($row['status']);

                QueueTicket::factory()->state($row + ['status' => $status])->create();
            }
        }
    }

    /** @return array<string, mixed> */
    private function ticketRow(int $branchId, int $serviceId, string $prefix, CarbonImmutable $issued, CarbonImmutable $called, CarbonImmutable $completed, int $advisorId, string $desk, ?int $appointmentId, bool $served): array
    {
        return [
            'branch_id' => $branchId,
            'service_id' => $serviceId,
            'queue_prefix' => $prefix,
            'service_date' => $issued->setTimezone('Asia/Dubai')->toDateString(),
            'appointment_id' => $appointmentId,
            'issued_at' => $issued,
            'called_at' => $called,
            'served_at' => $served ? $called->addMinute() : null,
            'completed_at' => $served ? $completed : null,
            'advisor_id' => $advisorId,
            'desk_label' => $desk,
            'status' => $served ? TicketStatus::Completed : TicketStatus::NoShow,
        ];
    }
}
