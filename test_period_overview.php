<?php

require_once 'bootstrap/app.php';

use FireflyIII\Models\Account;
use Carbon\Carbon;
use FireflyIII\Support\Http\Controllers\PeriodOverview;

// Create a test class that uses the trait
class TestPeriodOverview
{
    use PeriodOverview;
}

try {
    // Initialize Laravel
    $app = require_once 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $test = new TestPeriodOverview();
    
    // Get the first account
    $account = Account::first();
    
    if (!$account) {
        echo "No accounts found in database\n";
        exit(1);
    }
    
    echo "Testing with Account: {$account->name} (ID: {$account->id})\n";
    
    // Test with a recent date range
    $start = Carbon::parse('2024-10-01');
    $end = Carbon::parse('2024-10-31');
    
    echo "Date range: {$start->format('Y-m-d')} to {$end->format('Y-m-d')}\n";
    
    // Test the method
    $result = $test->getAccountPeriodOverview($account, $start, $end);
    
    echo "Results:\n";
    echo "Period count: " . count($result) . "\n";
    
    // Show balance if available
    if (isset($result['balance'])) {
        echo "Balance data: " . json_encode($result['balance']) . "\n";
    }
    
    // Show first few periods
    $count = 0;
    foreach ($result as $index => $period) {
        if (is_array($period) && $count < 3) {
            echo "Period {$index}: ";
            if (isset($period['title'])) {
                echo "{$period['title']} - ";
            }
            if (isset($period['total_transactions'])) {
                echo "{$period['total_transactions']} transactions";
            }
            echo "\n";
            $count++;
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}