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

class TestDataStructure extends Command
{
    use PeriodOverview;

    protected $primaryCurrency;

    protected $signature = 'test:data-structure';
    protected $description = 'Show raw data structure from PeriodOverview';

    public function handle(): int
    {
        try {
            // Set up authentication context
            $user = User::first();
            Auth::login($user);
            $this->primaryCurrency = Amount::getPrimaryCurrency();
            
            // Get test data
            $start = Carbon::now()->startOfMonth();
            $end = Carbon::now()->endOfMonth();
            $assetType = AccountType::where('type', 'Asset account')->first();
            $account = Account::where('account_type_id', $assetType->id)->first();
            
            $overview = $this->getAccountPeriodOverview($account, $start, $end);
            
            $this->info('=== RAW DATA STRUCTURE ===');
            $this->info('Data type: ' . gettype($overview));
            $this->info('Is array: ' . (is_array($overview) ? 'YES' : 'NO'));
            $this->info('Count: ' . count($overview));
            
            $this->info("\n=== FIRST ELEMENT STRUCTURE ===");
            if (!empty($overview)) {
                $firstElement = $overview[0];
                $this->info('First element type: ' . gettype($firstElement));
                $this->info('First element keys: ' . implode(', ', array_keys($firstElement)));
                
                $this->info("\n=== SAMPLE VALUES ===");
                $this->info('Title: ' . var_export($firstElement['title'], true));
                $this->info('Total transactions: ' . var_export($firstElement['total_transactions'], true));
                $this->info('Spent structure: ' . var_export($firstElement['spent'], true));
            }
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
    }
}