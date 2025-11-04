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

class TestBalanceStructure extends Command
{
    use PeriodOverview;

    protected $primaryCurrency;

    protected $signature = 'test:balance-structure';
    protected $description = 'Show balance data structure specifically';

    public function handle(): int
    {
        try {
            $user = User::first();
            Auth::login($user);
            $this->primaryCurrency = Amount::getPrimaryCurrency();
            
            $start = Carbon::now()->startOfMonth();
            $end = Carbon::now()->endOfMonth();
            $assetType = AccountType::where('type', 'Asset account')->first();
            $account = Account::where('account_type_id', $assetType->id)->first();
            
            $overview = $this->getAccountPeriodOverview($account, $start, $end);
            
            $this->info('=== BALANCE DATA STRUCTURE ===');
            
            if (!empty($overview)) {
                $firstPeriod = $overview[0];
                
                if (isset($firstPeriod['balance'])) {
                    $balance = $firstPeriod['balance'];
                    $this->info('Balance exists: YES');
                    $this->info('Balance type: ' . gettype($balance));
                    $this->info('Balance structure:');
                    
                    foreach ($balance as $date => $balanceData) {
                        $this->info("  Date key: $date");
                        $this->info("  Balance data type: " . gettype($balanceData));
                        $this->info("  Balance data keys: " . implode(', ', array_keys($balanceData)));
                        
                        foreach ($balanceData as $key => $value) {
                            $this->info("    $key: $value (" . gettype($value) . ")");
                        }
                    }
                } else {
                    $this->info('Balance key does NOT exist in period data');
                    $this->info('Available keys: ' . implode(', ', array_keys($firstPeriod)));
                }
            }
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
    }
}