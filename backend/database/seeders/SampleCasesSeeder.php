<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerEvent;
use App\Models\FollowUp;
use App\Support\CurrentCompany;
use Illuminate\Database\Seeder;

/**
 * SAMPLE DATA for trying the case screens: fills the development customers (DEV-0001 …) with fictitious dues,
 * addresses (real Hyderabad localities), a few callbacks and remarks. Refuses to run in production.
 *   php artisan db:seed --class=SampleCasesSeeder
 * Safe to run again (it overwrites the same sample values).
 */
final class SampleCasesSeeder extends Seeder
{
    private const PLACES = [
        ['Kondapur', 17.4612, 78.3549, '500084'], ['Madhapur', 17.4483, 78.3915, '500081'], ['Gachibowli', 17.4401, 78.3489, '500032'],
        ['Kukatpally', 17.4948, 78.3996, '500072'], ['Ameerpet', 17.4375, 78.4482, '500016'], ['Begumpet', 17.4440, 78.4630, '500016'],
        ['Banjara Hills', 17.4126, 78.4392, '500034'], ['Secunderabad', 17.4399, 78.4983, '500003'], ['Dilsukhnagar', 17.3688, 78.5247, '500060'],
        ['LB Nagar', 17.3457, 78.5522, '500074'], ['Mehdipatnam', 17.3950, 78.4310, '500028'], ['Uppal', 17.4058, 78.5591, '500039'],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new \RuntimeException('Sample data refused in production.');
        }
        foreach (Company::query()->get() as $company) {
            app(CurrentCompany::class)->run($company, function () {
                $rows = Customer::query()->where('customer_ref', 'like', 'DEV-%')->orderBy('customer_ref')->get();
                foreach ($rows as $i => $c) {
                    $n = $i + 1;
                    [$area, $lat, $lng, $pin] = self::PLACES[$i % count(self::PLACES)];
                    $emi = [3200, 4500, 6100, 8200, 12500][$n % 5];
                    $overdueEmis = [0, 1, 2, 3, 5, 8][$n % 6];
                    $loan = $emi * 36;
                    $outstanding = $emi * (36 - ($n % 12)) - 1500;
                    $hasLoc = $n % 7 !== 0;   // every 7th case has no map pin → shows "location unavailable"
                    $c->fill([
                        'address' => (10 + $n) . '-' . ($n % 9 + 1) . '-' . (100 + $n * 3) . ", Street {$n}",
                        'area' => $area, 'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => $pin,
                        'lat' => $hasLoc ? $lat + ($n % 5) * 0.0011 : null, 'lng' => $hasLoc ? $lng - ($n % 4) * 0.0009 : null,
                        'email' => $n % 3 === 0 ? "sample{$n}@example.com" : null,
                        'preferred_time' => ['After 6 pm', 'Morning 9–11', null, 'Lunch time'][$n % 4],
                        'loan_amount' => $loan, 'outstanding' => $outstanding,
                        'principal_os' => round($outstanding * 0.9), 'interest_os' => round($outstanding * 0.08), 'charges' => $overdueEmis * 500,
                        'emi_amount' => $emi, 'overdue_amount' => $overdueEmis * $emi + ($overdueEmis ? $overdueEmis * 500 : 0),
                        'overdue_installments' => $overdueEmis,
                        'oldest_due_date' => $overdueEmis ? now()->subDays($overdueEmis * 30 - 5)->toDateString() : null,
                        'due_date' => now()->addDays(($n * 3) % 12)->toDateString(),
                        'last_paid_on' => now()->subDays($overdueEmis * 30 + 25)->toDateString(), 'last_paid_amount' => $emi,
                        'balance_as_of' => now()->toDateString(),
                    ])->save();
                    if ($c->assigned_user_id && $n <= 6) {
                        // A few callbacks: overdue, due today, later.
                        FollowUp::query()->where('customer_id', $c->id)->where('status', 'open')->update(['status' => 'done']);
                        FollowUp::query()->create(['customer_id' => $c->id, 'user_id' => $c->assigned_user_id,
                            'due_at' => [now()->subDay()->setTime(11, 0), now()->setTime(17, 30), now()->addDays(2)->setTime(10, 0)][$n % 3],
                            'notes' => 'Sample callback — customer asked to call back', 'status' => 'open']);
                        $c->forceFill(['status' => 'follow_up', 'follow_up_on' => now()->toDateString()])->save();
                    }
                    if ($c->assigned_user_id && $n <= 4 && !CustomerEvent::query()->where('customer_id', $c->id)->where('type', 'note')->exists()) {
                        CustomerEvent::query()->create(['customer_id' => $c->id, 'user_id' => $c->assigned_user_id, 'type' => 'note',
                            'body' => 'Sample remark: salary credited on 10th, will pay the overdue then.']);
                    }
                }
                $this->command?->info("Sample case data added to {$rows->count()} customers.");
            });
        }
    }
}
