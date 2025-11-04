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

class DebugPeriods extends Command
{
    use PeriodOverview;

    protected $primaryCurrency;

    protected $signature = 'debug:periods';
    protected $description = 'Debug the periods data structure for template';

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
            
            $periods = $this->getAccountPeriodOverview($account, $start, $end);
            
            $this->info('=== COMPLETE DATA STRUCTURE ===');
            $this->info('Type: ' . gettype($periods));
            $this->info('Keys: ' . implode(', ', array_keys($periods)));
            
            $this->info("\n=== CHECKING BALANCE KEY ===");
            if (array_key_exists('balance', $periods)) {
                $this->info('✅ Balance key EXISTS');
                $this->info('Balance type: ' . gettype($periods['balance']));
                $this->info('Balance content: ' . json_encode($periods['balance']));
                
                if (is_array($periods['balance']) && !empty($periods['balance'])) {
                    $this->info('✅ Balance is non-empty array');
                    foreach ($periods['balance'] as $date => $balanceInfo) {
                        $this->info("  Date: $date");
                        $this->info("  Balance info: " . json_encode($balanceInfo));
                        
                        if (isset($balanceInfo['balance'])) {
                            $amount = $balanceInfo['balance'];
                            $this->info("  Balance amount: $amount");
                            $this->info("  Is zero? " . ($amount == 0 ? 'YES' : 'NO'));
                        }
                    }
                } else {
                    $this->info('❌ Balance is empty or not array');
                }
            } else {
                $this->info('❌ Balance key does NOT exist');
            }
            
            $this->info("\n=== TWIG TEMPLATE CONDITIONS ===");
            $this->info('periods.balance is defined: ' . (isset($periods['balance']) ? 'YES' : 'NO'));
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
    }
}