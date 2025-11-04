<?php

namespace FireflyIII\Console\Commands;

use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\Category;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Support\Http\Controllers\PeriodOverview;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class TestPeriodOverview extends Command
{
    use PeriodOverview;

    protected ?TransactionCurrency $primaryCurrency;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'test:period-overview';

    /**
     * The console command description.
     */
    protected $description = 'Test the PeriodOverview trait functionality';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            // Set up authentication context - get the first user
            $user = User::first();
            if (!$user) {
                $this->error('No users found in database. Cannot run test without a user context.');
                return 1;
            }
            
            Auth::login($user);
            $this->info("Running test as user: {$user->email}");
            
            // Initialize the primary currency like the web controllers do
            $this->primaryCurrency = Amount::getPrimaryCurrency();
            
            $this->info('Testing PeriodOverview trait functionality...');

            // Get some test dates
            $start = Carbon::now()->startOfMonth();
            $end = Carbon::now()->endOfMonth();
            
            $this->info("Testing for period: {$start->format('Y-m-d')} to {$end->format('Y-m-d')}");

            // Get an asset account for testing
            $assetType = AccountType::where('type', 'Asset account')->first();
            if (!$assetType) {
                $this->error('No Asset account type found in database');
                return 1;
            }

            $account = Account::where('account_type_id', $assetType->id)->first();
            if (!$account) {
                $this->error('No Asset accounts found in database');
                return 1;
            }

            $this->info("Testing with account: {$account->name} (ID: {$account->id})");

            // Test getAccountPeriodOverview
            $this->info('Testing getAccountPeriodOverview...');
            $overview = $this->getAccountPeriodOverview($account, $start, $end);
            
            $this->info('Account Period Overview Results:');
            foreach ($overview as $key => $value) {
                if (is_array($value)) {
                    $this->info("  {$key}: " . json_encode($value));
                } else {
                    $this->info("  {$key}: {$value}");
                }
            }

            // Test getCategoryPeriodOverview if we have categories
            $this->info('Testing getCategoryPeriodOverview...');
            
            $category = Category::first();
            if (!$category) {
                $this->info('No categories found - skipping category test');
            } else {
                $this->info("Testing with category: {$category->name} (ID: {$category->id})");
                $categoryOverview = $this->getCategoryPeriodOverview($category, $start, $end);
                
                $this->info('Category Period Overview Results:');
                if (empty($categoryOverview)) {
                    $this->info('  No category data found');
                } else {
                    foreach ($categoryOverview as $key => $value) {
                        if (is_array($value)) {
                            $this->info("  {$key}: " . json_encode($value));
                        } else {
                            $this->info("  {$key}: {$value}");
                        }
                    }
                }
            }

            $this->info('Test completed successfully!');
            return 0;

        } catch (\Exception $e) {
            $this->error('Error testing PeriodOverview: ' . $e->getMessage());
            $this->error('Stack trace: ' . $e->getTraceAsString());
            return 1;
        }
    }
}