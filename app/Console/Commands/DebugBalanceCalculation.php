<?php

namespace FireflyIII\Console\Commands;

use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\Support\Http\Controllers\PeriodOverview;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class DebugBalanceCalculation extends Command
{
    use PeriodOverview;

    protected $primaryCurrency;

    protected $signature = 'debug:balance-calc';
    protected $description = 'Debug the balance calculation logic';

    public function handle(): int
    {
        try {
            $user = User::first();
            Auth::login($user);
            $this->primaryCurrency = Amount::getPrimaryCurrency();
            
            // Test with a previous month that might have data
            $start = Carbon::now()->subMonth()->startOfMonth();
            $end = Carbon::now()->subMonth()->endOfMonth();
            $assetType = AccountType::where('type', 'Asset account')->first();
            $account = Account::where('account_type_id', $assetType->id)->first();
            
            $periods = $this->getAccountPeriodOverview($account, $start, $end);
            
            $this->info("=== BALANCE CALCULATION DEBUG ===");
            $this->info("Testing period: {$start->format('Y-m-d')} to {$end->format('Y-m-d')}");
            
            if (!empty($periods) && is_array($periods[0])) {
                $period = $periods[0];
                
                $this->info("\n=== TRANSACTION AMOUNTS ===");
                $this->info("Earned: " . json_encode($period['earned']));
                $this->info("Spent: " . json_encode($period['spent']));
                $this->info("Transferred In: " . json_encode($period['transferred_in']));
                $this->info("Transferred Away: " . json_encode($period['transferred_away']));
                $this->info("Period Balance: " . json_encode($period['period_balance']));
                
                $this->info("\n=== MANUAL CALCULATION CHECK ===");
                // Let's manually verify the calculation
                if (isset($period['period_balance']) && count($period['period_balance']) > 1) {
                    foreach ($period['period_balance'] as $currencyId => $balance) {
                        if ($currencyId === 'count') continue;
                        
                        $this->info("Currency {$currencyId}:");
                        $this->info("  Calculated balance: {$balance['amount']}");
                        
                        // Manual calculation
                        $manual = '0';
                        if (isset($period['earned'][$currencyId])) {
                            $manual = bcadd($manual, $period['earned'][$currencyId]['amount']);
                            $this->info("  + Earned: {$period['earned'][$currencyId]['amount']} = {$manual}");
                        }
                        if (isset($period['spent'][$currencyId])) {
                            $manual = bcadd($manual, bcmul($period['spent'][$currencyId]['amount'], '-1'));
                            $this->info("  - Spent: {$period['spent'][$currencyId]['amount']} = {$manual}");
                        }
                        if (isset($period['transferred_in'][$currencyId])) {
                            $manual = bcadd($manual, $period['transferred_in'][$currencyId]['amount']);
                            $this->info("  + Transferred In: {$period['transferred_in'][$currencyId]['amount']} = {$manual}");
                        }
                        if (isset($period['transferred_away'][$currencyId])) {
                            $manual = bcadd($manual, bcmul($period['transferred_away'][$currencyId]['amount'], '-1'));
                            $this->info("  - Transferred Away: {$period['transferred_away'][$currencyId]['amount']} = {$manual}");
                        }
                        
                        $this->info("  Manual calculation result: {$manual}");
                        $this->info("  Match: " . ($manual === $balance['amount'] ? 'YES' : 'NO'));
                    }
                }
            } else {
                $this->info("No period data found or empty period");
            }
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
    }
}