<?php

/**
 * PeriodOverview.php
 * Copyright (c) 2019 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Support\Http\Controllers;

use Carbon\Carbon;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Models\Account;
use FireflyIII\Models\Category;
use FireflyIII\Models\PeriodStatistic;
use FireflyIII\Models\Tag;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\Category\CategoryRepositoryInterface;
use FireflyIII\Repositories\Journal\JournalRepositoryInterface;
use FireflyIII\Repositories\PeriodStatistic\PeriodStatisticRepositoryInterface;
use FireflyIII\Repositories\Tag\TagRepositoryInterface;
use FireflyIII\Support\CacheProperties;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Facades\Navigation;
use FireflyIII\Support\Facades\Steam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Trait PeriodOverview.
 *
 * TODO verify this all works as expected.
 *
 * - Always request start date and end date.
 * - Group expenses, income, etc. under this period.
 * - Returns collection of arrays. Fields
 *      title (string),
 *      route (string)
 *      total_transactions (int)
 *      spent (array),
 *      earned (array),
 *      transferred_away (array)
 *      transferred_in (array)
 *      transferred (array)
 *
 * each array has the following format:
 * currency_id => [
 *       currency_id : 1, (int)
 *       currency_symbol : X (str)
 *       currency_name: Euro (str)
 *       currency_code: EUR (str)
 *       amount: -1234 (str)
 *       count: 23
 *       ]
 */
trait PeriodOverview
{
    protected AccountRepositoryInterface         $accountRepository;
    protected CategoryRepositoryInterface        $categoryRepository;
    protected TagRepositoryInterface             $tagRepository;
    protected JournalRepositoryInterface         $journalRepos;
    protected PeriodStatisticRepositoryInterface $periodStatisticRepo;
    private Collection                           $statistics;   // temp data holder
    private array                                $transactions; // temp data holder

    /**
     * This method returns "period entries", so nov-2015, dec-2015, etc. (this depends on the users session range)
     * and for each period, the amount of money spent and earned. This is a complex operation which is cached for
     * performance reasons.
     *
     * @throws FireflyException
     */
    protected function getAccountPeriodOverview(Account $account, Carbon $start, Carbon $end): array
    {
        Log::debug(sprintf('Now in getAccountPeriodOverview(#%d, %s %s)', $account->id, $start->format('Y-m-d H:i:s.u'), $end->format('Y-m-d H:i:s.u')));
        $this->accountRepository   = app(AccountRepositoryInterface::class);
        $this->accountRepository->setUser($account->user);
        $this->periodStatisticRepo = app(PeriodStatisticRepositoryInterface::class);
        $range                     = Navigation::getViewRange(true);
        [$start, $end]             = $end < $start ? [$end, $start] : [$start, $end];

        /** @var array $dates */
        $dates                     = Navigation::blockPeriods($start, $end, $range);
        [$start, $end]             = $this->getPeriodFromBlocks($dates, $start, $end);
        $this->statistics          = $this->periodStatisticRepo->allInRangeForModel($account, $start, $end);

        $entries                   = [];
        Log::debug(sprintf('Count of loops: %d', count($dates)));
        foreach ($dates as $currentDate) {
            $entries[] = $this->getSingleModelPeriod($account, $currentDate['period'], $currentDate['start'], $currentDate['end']);
        }
        $entries['balance'] = Steam::finalAccountBalanceInRange($account, $start, $end, true);
        Log::debug('End of getAccountPeriodOverview()');

        return $entries;
    }

    private function getPeriodFromBlocks(array $dates, Carbon $start, Carbon $end): array
    {
        Log::debug('Filter generated periods to select the oldest and newest date.');
        foreach ($dates as $row) {
            $currentStart = clone $row['start'];
            $currentEnd   = clone $row['end'];
            if ($currentStart->lt($start)) {
                Log::debug(sprintf('New start: was %s, now %s', $start->format('Y-m-d'), $currentStart->format('Y-m-d')));
                $start = $currentStart;
            }
            if ($currentEnd->gt($end)) {
                Log::debug(sprintf('New end: was %s, now %s', $end->format('Y-m-d'), $currentEnd->format('Y-m-d')));
                $end = $currentEnd;
            }
        }

        return [$start, $end];
    }

    /**
     * Overview for single category. Has been refactored recently.
     *
     * @throws FireflyException
     */
    protected function getCategoryPeriodOverview(Category $category, Carbon $start, Carbon $end): array
    {
        $this->categoryRepository  = app(CategoryRepositoryInterface::class);
        $this->categoryRepository->setUser($category->user);
        $this->periodStatisticRepo = app(PeriodStatisticRepositoryInterface::class);

        $range                     = Navigation::getViewRange(true);
        [$start, $end]             = $end < $start ? [$end, $start] : [$start, $end];

        /** @var array $dates */
        $dates                     = Navigation::blockPeriods($start, $end, $range);
        $entries                   = [];
        [$start, $end]             = $this->getPeriodFromBlocks($dates, $start, $end);
        $this->statistics          = $this->periodStatisticRepo->allInRangeForModel($category, $start, $end);


        Log::debug(sprintf('Count of loops: %d', count($dates)));
        foreach ($dates as $currentDate) {
            $entries[] = $this->getSingleModelPeriod($category, $currentDate['period'], $currentDate['start'], $currentDate['end']);
        }

        return $entries;
    }

    /**
     * Same as above, but for lists that involve transactions without a budget.
     *
     * This method has been refactored recently.
     *
     * @throws FireflyException
     */
    protected function getNoModelPeriodOverview(string $model, Carbon $start, Carbon $end): array
    {
        Log::debug(sprintf('Now in getNoModelPeriodOverview(%s, %s %s)', $model, $start->format('Y-m-d'), $end->format('Y-m-d')));
        $this->periodStatisticRepo = app(PeriodStatisticRepositoryInterface::class);
        $range                     = Navigation::getViewRange(true);
        [$start, $end]             = $end < $start ? [$end, $start] : [$start, $end];

        /** @var array $dates */
        $dates                     = Navigation::blockPeriods($start, $end, $range);
        [$start, $end]             = $this->getPeriodFromBlocks($dates, $start, $end);
        $entries                   = [];
        $this->statistics          = $this->periodStatisticRepo->allInRangeForPrefix(sprintf('no_%s', $model), $start, $end);
        Log::debug(sprintf('Collected %d stats', $this->statistics->count()));

        foreach ($dates as $currentDate) {
            $entries[] = $this->getSingleNoModelPeriodOverview($model, $currentDate['start'], $currentDate['end'], $currentDate['period']);
        }

        return $entries;
    }

    private function getSingleNoModelPeriodOverview(string $model, Carbon $start, Carbon $end, string $period): array
    {
        Log::debug(sprintf('getSingleNoModelPeriodOverview(%s, %s, %s, %s)', $model, $start->format('Y-m-d'), $end->format('Y-m-d'), $period));
        $statistics = $this->filterPrefixedStatistics($start, $end, sprintf('no_%s', $model));
        $title      = Navigation::periodShow($end, $period);

        if (0 === $statistics->count()) {
            Log::debug(sprintf('Found no statistics in period %s - %s, regenerating them.', $start->format('Y-m-d'), $end->format('Y-m-d')));

            switch ($model) {
                default:
                    throw new FireflyException(sprintf('Cannot deal with model of type "%s"', $model));

                case 'budget':
                    // get all expenses without a budget.
                    /** @var GroupCollectorInterface $collector */
                    $collector   = app(GroupCollectorInterface::class);
                    $collector->setRange($start, $end)->withoutBudget()->withAccountInformation()->setTypes([TransactionTypeEnum::WITHDRAWAL->value]);
                    $spent       = $collector->getExtractedJournals();
                    $earned      = [];
                    $transferred = [];

                    break;

                case 'category':
                    // collect all expenses in this period:
                    /** @var GroupCollectorInterface $collector */
                    $collector   = app(GroupCollectorInterface::class);
                    $collector->withoutCategory();
                    $collector->setRange($start, $end);
                    $collector->setTypes([TransactionTypeEnum::DEPOSIT->value]);
                    $earned      = $collector->getExtractedJournals();

                    // collect all income in this period:
                    /** @var GroupCollectorInterface $collector */
                    $collector   = app(GroupCollectorInterface::class);
                    $collector->withoutCategory();
                    $collector->setRange($start, $end);
                    $collector->setTypes([TransactionTypeEnum::WITHDRAWAL->value]);
                    $spent       = $collector->getExtractedJournals();

                    // collect all transfers in this period:
                    /** @var GroupCollectorInterface $collector */
                    $collector   = app(GroupCollectorInterface::class);
                    $collector->withoutCategory();
                    $collector->setRange($start, $end);
                    $collector->setTypes([TransactionTypeEnum::TRANSFER->value]);
                    $transferred = $collector->getExtractedJournals();

                    break;
            }
            $groupedSpent       = $this->groupByCurrency($spent);
            $groupedEarned      = $this->groupByCurrency($earned);
            $groupedTransferred = $this->groupByCurrency($transferred);
            $entry
                                = [
                                    'title'              => $title,
                                    'route'              => route(sprintf('%s.no-%s', Str::plural($model), $model), [$start->format('Y-m-d'), $end->format('Y-m-d')]),
                                    'total_transactions' => count($spent),
                                    'spent'              => $groupedSpent,
                                    'earned'             => $groupedEarned,
                                    'transferred'        => $groupedTransferred,
                                ];
            
            // For no-model periods, calculate a simple period balance
            $entry['period_balance'] = $this->calculatePeriodBalance($entry);
            
            // Calculate opening balance for this period  
            $entry['opening_balance'] = $this->calculateOpeningBalance($entry);
            
            // Calculate net change for this period
            $entry['net_change'] = $this->calculateNetChange($entry);
            
            // Calculate financial metrics for this period
            // Note: For no-model periods, we use 'transferred' instead of 'transferred_in' and 'transferred_away'
            $metricsData = [
                'spent' => $groupedSpent,
                'earned' => $groupedEarned,
                'transferred_in' => [], // No separate in/out for no-model
                'transferred_away' => $groupedTransferred,
            ];
            $entry['period_metrics'] = $this->calculatePeriodMetrics($metricsData, $start, $end);
            
            $this->saveGroupedForPrefix(sprintf('no_%s', $model), $start, $end, 'spent', $groupedSpent);
            $this->saveGroupedForPrefix(sprintf('no_%s', $model), $start, $end, 'earned', $groupedEarned);
            $this->saveGroupedForPrefix(sprintf('no_%s', $model), $start, $end, 'transferred', $groupedTransferred);

            return $entry;
        }
        Log::debug(sprintf('Found %d statistics in period %s - %s.', count($statistics), $start->format('Y-m-d'), $end->format('Y-m-d')));

        $entry
                    = [
                        'title'              => $title,
                        'route'              => route(sprintf('%s.no-%s', Str::plural($model), $model), [$start->format('Y-m-d'), $end->format('Y-m-d')]),
                        'total_transactions' => 0,
                        'spent'              => [],
                        'earned'             => [],
                        'transferred'        => [],
                    ];
        $grouped    = [];

        /** @var PeriodStatistic $statistic */
        foreach ($statistics as $statistic) {
            $type                = str_replace(sprintf('no_%s_', $model), '', $statistic->type);
            $id                  = (int)$statistic->transaction_currency_id;
            $currency            = Amount::getTransactionCurrencyById($id);
            $grouped[$type]['count'] ??= 0;
            $grouped[$type][$id] = [
                'amount'                  => (string)$statistic->amount,
                'count'                   => (int)$statistic->count,
                'currency_id'             => $currency->id,
                'currency_name'           => $currency->name,
                'currency_code'           => $currency->code,
                'currency_symbol'         => $currency->symbol,
                'currency_decimal_places' => $currency->decimal_places,
            ];
            $grouped[$type]['count'] += (int)$statistic->count;
        }
        $types      = ['spent', 'earned', 'transferred'];
        foreach ($types as $type) {
            if (array_key_exists($type, $grouped)) {
                $entry['total_transactions'] += $grouped[$type]['count'];
                unset($grouped[$type]['count']);
                $entry[$type] = $grouped[$type];
            }

        }

        // Calculate period balance for this no-model entry
        $entry['period_balance'] = $this->calculatePeriodBalance($entry);
        
        // Calculate opening balance for this period
        $entry['opening_balance'] = $this->calculateOpeningBalance($entry);

        // Calculate net change for this period
        $entry['net_change'] = $this->calculateNetChange($entry);

        // Calculate financial metrics for this period
        // Note: For no-model periods, we use 'transferred' instead of 'transferred_in' and 'transferred_away'
        $metricsData = [
            'spent' => $entry['spent'],
            'earned' => $entry['earned'],
            'transferred_in' => [], // No separate in/out for no-model
            'transferred_away' => $entry['transferred'],
        ];
        $entry['period_metrics'] = $this->calculatePeriodMetrics($metricsData, $start, $end);

        return $entry;
    }

    protected function getSingleModelPeriod(Model $model, string $period, Carbon $start, Carbon $end): array
    {
        Log::debug(sprintf('Now in getSingleModelPeriod(%s #%d, %s %s)', $model::class, $model->id, $start->format('Y-m-d'), $end->format('Y-m-d')));
        $types              = ['spent', 'earned', 'transferred_in', 'transferred_away'];
        $return             = [
            'title'              => Navigation::periodShow($start, $period),
            'route'              => route(sprintf('%s.show', strtolower(Str::plural(class_basename($model)))), [$model->id, $start->format('Y-m-d'), $end->format('Y-m-d')]),
            'total_transactions' => 0,
        ];
        $this->transactions = [];
        foreach ($types as $type) {
            $set           = $this->getSingleModelPeriodByType($model, $start, $end, $type);
            $return['total_transactions'] += $set['count'];
            unset($set['count']);
            $return[$type] = $set;
        }

        // Get the actual account balance at the end of this period (for accounts only)
        if ($model instanceof Account) {
            $return['period_balance'] = $this->getAccountBalanceForPeriod($model, $end);
        } else {
            // For non-account models (categories, tags), calculate net change
            $return['period_balance'] = $this->calculatePeriodBalance($return);
        }

        // Calculate opening balance for this period
        $return['opening_balance'] = $this->calculateOpeningBalance($return);

        // Calculate net change for this period
        $return['net_change'] = $this->calculateNetChange($return);

        // Calculate financial metrics for this period
        $return['period_metrics'] = $this->calculatePeriodMetrics($return, $start, $end);

        return $return;
    }

    /**
     * Get the actual account balance at the end of a period.
     * Returns the same format as other transaction type arrays.
     */
    private function getAccountBalanceForPeriod(Account $account, Carbon $date): array
    {
        // Get the balance at the end of this period
        $balanceData = Steam::finalAccountBalanceInRange($account, $date, $date, true);
        
        $result = ['count' => 0];
        
        // Convert the balance data to the same format as other transaction types
        foreach ($balanceData as $dateKey => $balances) {
            foreach ($balances as $currencyCode => $amount) {
                // Skip special balance keys that aren't actual currencies
                if (in_array($currencyCode, ['balance', 'pc_balance'])) {
                    continue;
                }
                
                // Get currency info - we need to find the currency by code
                try {
                    $currency = Amount::getTransactionCurrencyByCode($currencyCode);
                    $currencyId = $currency->id;
                    
                    $result[$currencyId] = [
                        'amount' => (string)$amount,
                        'count' => 1, // Balance is a single "transaction"
                        'currency_id' => $currency->id,
                        'currency_name' => $currency->name,
                        'currency_code' => $currency->code,
                        'currency_symbol' => $currency->symbol,
                        'currency_decimal_places' => $currency->decimal_places,
                    ];
                    $result['count'] += 1;
                } catch (\Exception $e) {
                    Log::warning("Could not find currency for code: {$currencyCode}");
                    continue;
                }
            }
        }
        
        return $result;
    }

    /**
     * Calculate the period balance by summing all transaction types per currency.
     * Returns the same format as other transaction type arrays.
     */
    private function calculatePeriodBalance(array $periodData): array
    {
        $balances = ['count' => 0];
        
        // Helper function to add amounts by currency
        $addToCurrency = function(array &$balances, array $entries, int $multiplier = 1) {
            foreach ($entries as $currencyId => $entry) {
                if ($currencyId === 'count') {
                    continue;
                }
                
                if (!isset($balances[$currencyId])) {
                    $balances[$currencyId] = [
                        'amount' => '0',
                        'count' => 0,
                        'currency_id' => $entry['currency_id'],
                        'currency_name' => $entry['currency_name'],
                        'currency_code' => $entry['currency_code'],
                        'currency_symbol' => $entry['currency_symbol'],
                        'currency_decimal_places' => $entry['currency_decimal_places'],
                    ];
                }
                
                $balances[$currencyId]['amount'] = bcadd(
                    $balances[$currencyId]['amount'], 
                    bcmul($entry['amount'], (string)$multiplier)
                );
                $balances[$currencyId]['count'] += $entry['count'];
            }
        };
        
        // Add earned and transferred_in (positive contribution to balance)
        if (isset($periodData['earned'])) {
            $addToCurrency($balances, $periodData['earned'], 1);
        }
        if (isset($periodData['transferred_in'])) {
            $addToCurrency($balances, $periodData['transferred_in'], 1);
        }
        
        // Subtract spent and transferred_away (negative contribution to balance)
        if (isset($periodData['spent'])) {
            // Spent amounts are typically positive values representing outgoing money
            // We need to subtract them, so multiply by -1
            $addToCurrency($balances, $periodData['spent'], -1);
        }
        if (isset($periodData['transferred_away'])) {
            // Transferred away amounts are typically positive values representing outgoing money
            // We need to subtract them, so multiply by -1
            $addToCurrency($balances, $periodData['transferred_away'], -1);
        }
        
        // Calculate total count and remove zero balances
        foreach ($balances as $currencyId => $entry) {
            if ($currencyId === 'count') {
                continue;
            }
            
            if (bccomp($entry['amount'], '0') === 0) {
                unset($balances[$currencyId]);
            } else {
                $balances['count'] += $entry['count'];
            }
        }
        
        return $balances;
    }

    /**
     * Calculate the net change for a period by summing all transaction types per currency.
     * Returns the same format as other transaction type arrays.
     */
    private function calculateNetChange(array $periodData): array
    {
        $netChanges = ['count' => 0];
        
        // Get all unique currencies from the period data
        $currencies = [];
        foreach (['earned', 'spent', 'transferred_in', 'transferred_away'] as $type) {
            if (isset($periodData[$type])) {
                foreach ($periodData[$type] as $currencyId => $entry) {
                    if ($currencyId !== 'count') {
                        $currencies[$currencyId] = $entry;
                    }
                }
            }
        }
        
        // Calculate net change for each currency
        foreach ($currencies as $currencyId => $currencyInfo) {
            // Get amounts for this currency using the same logic as the Twig template
            $netEarned = '0';
            $netSpent = '0';
            $netTransferredIn = '0';
            $netTransferredAway = '0';
            
            // Get earned amount for this currency (make positive)
            if (isset($periodData['earned'][$currencyId])) {
                $amount = $periodData['earned'][$currencyId]['amount'];
                $netEarned = bccomp($amount, '0') < 0 ? bcmul($amount, '-1') : $amount;
            }
            
            // Get spent amount for this currency (keep as-is, usually negative)
            if (isset($periodData['spent'][$currencyId])) {
                $netSpent = $periodData['spent'][$currencyId]['amount'];
            }
            
            // Get transferred in amount for this currency (make positive)
            if (isset($periodData['transferred_in'][$currencyId])) {
                $amount = $periodData['transferred_in'][$currencyId]['amount'];
                $netTransferredIn = bccomp($amount, '0') < 0 ? bcmul($amount, '-1') : $amount;
            }
            
            // Get transferred away amount for this currency (make negative)
            if (isset($periodData['transferred_away'][$currencyId])) {
                $amount = $periodData['transferred_away'][$currencyId]['amount'];
                $netTransferredAway = bccomp($amount, '0') < 0 ? $amount : bcmul($amount, '-1');
            }
            
            // Calculate net change: earned + spent + transferred_in + transferred_away
            $netChange = bcadd(
                bcadd($netEarned, $netSpent),
                bcadd($netTransferredIn, $netTransferredAway)
            );
            
            // Only include if there's a meaningful change
            if (bccomp($netChange, '0') !== 0) {
                $netChanges[$currencyId] = [
                    'amount' => $netChange,
                    'count' => 1, // Net change is a single calculated "transaction"
                    'currency_id' => $currencyInfo['currency_id'],
                    'currency_name' => $currencyInfo['currency_name'],
                    'currency_code' => $currencyInfo['currency_code'],
                    'currency_symbol' => $currencyInfo['currency_symbol'],
                    'currency_decimal_places' => $currencyInfo['currency_decimal_places'],
                ];
                $netChanges['count'] += 1;
            }
        }
        
        return $netChanges;
    }

    /**
     * Calculate the opening balance for a period by subtracting the net change from the closing balance.
     * Returns the same format as other transaction type arrays.
     */
    private function calculateOpeningBalance(array $periodData): array
    {
        $openingBalances = ['count' => 0];
        
        // Get the closing balance (period_balance)
        if (!isset($periodData['period_balance'])) {
            return $openingBalances;
        }
        
        $closingBalances = $periodData['period_balance'];
        
        // For each currency in the closing balance, calculate the opening balance
        foreach ($closingBalances as $currencyId => $balanceEntry) {
            if ($currencyId === 'count') {
                continue;
            }
            
            // Calculate net change for this currency
            $netEarned = '0';
            $netSpent = '0';
            $netTransferredIn = '0';
            $netTransferredAway = '0';
            
            // Get earned amount for this currency
            if (isset($periodData['earned'][$currencyId])) {
                $amount = $periodData['earned'][$currencyId]['amount'];
                $netEarned = $amount < 0 ? bcmul($amount, '-1') : $amount;
            }
            
            // Get spent amount for this currency (already negative)
            if (isset($periodData['spent'][$currencyId])) {
                $netSpent = $periodData['spent'][$currencyId]['amount'];
            }
            
            // Get transferred in amount for this currency
            if (isset($periodData['transferred_in'][$currencyId])) {
                $amount = $periodData['transferred_in'][$currencyId]['amount'];
                $netTransferredIn = $amount < 0 ? bcmul($amount, '-1') : $amount;
            }
            
            // Get transferred away amount for this currency (make it negative)
            if (isset($periodData['transferred_away'][$currencyId])) {
                $amount = $periodData['transferred_away'][$currencyId]['amount'];
                $netTransferredAway = $amount < 0 ? $amount : bcmul($amount, '-1');
            }
            
            // Calculate net change: earned + spent + transferred_in + transferred_away
            $netChange = bcadd(
                bcadd($netEarned, $netSpent),
                bcadd($netTransferredIn, $netTransferredAway)
            );
            
            // Calculate opening balance: closing_balance - net_change
            $openingAmount = bcsub($balanceEntry['amount'], $netChange);
            
            // Add to opening balances array
            $openingBalances[$currencyId] = [
                'amount' => $openingAmount,
                'count' => 1, // Opening balance is a single "transaction"
                'currency_id' => $balanceEntry['currency_id'],
                'currency_name' => $balanceEntry['currency_name'],
                'currency_code' => $balanceEntry['currency_code'],
                'currency_symbol' => $balanceEntry['currency_symbol'],
                'currency_decimal_places' => $balanceEntry['currency_decimal_places'],
            ];
            $openingBalances['count'] += 1;
        }
        
        return $openingBalances;
    }

    /**
     * Calculate financial metrics for the period.
     * Returns metrics like burn rate, savings rate, and expense ratio.
     */
    private function calculatePeriodMetrics(array $periodData, Carbon $start, Carbon $end): array
    {
        $metrics = ['count' => 0];
        $daysInPeriod = $start->diffInDays($end) + 1; // +1 to include both start and end day
        
        // Get all unique currencies from the period data
        $currencies = [];
        foreach (['earned', 'spent', 'transferred_in', 'transferred_away'] as $type) {
            if (isset($periodData[$type])) {
                foreach ($periodData[$type] as $currencyId => $entry) {
                    if ($currencyId !== 'count') {
                        $currencies[$currencyId] = $entry;
                    }
                }
            }
        }
        
        // Calculate metrics for each currency
        foreach ($currencies as $currencyId => $currencyInfo) {
            // Get amounts for this currency
            $earned = $periodData['earned'][$currencyId]['amount'] ?? '0';
            $spent = $periodData['spent'][$currencyId]['amount'] ?? '0';
            $transferredIn = $periodData['transferred_in'][$currencyId]['amount'] ?? '0';
            $transferredAway = $periodData['transferred_away'][$currencyId]['amount'] ?? '0';
            
            // Calculate total inflows (earned + transferred in)
            $totalInflows = bcadd($earned, $transferredIn);
            
            // Calculate total outflows (spent + transferred away - taking absolute values)
            $totalOutflows = bcadd(bcmul($spent, '-1'), bcmul($transferredAway, '-1'));
            
            // 1. Burn rate (spend only) = expenses ÷ days (lifestyle spending velocity)
            $burnRate = $daysInPeriod > 0 ? bcdiv(bcmul($spent, '-1'), (string)$daysInPeriod, $currencyInfo['currency_decimal_places']) : '0';
            
            // 2. Net burn (total cash out) = (outflows - inflows) ÷ days (how fast cash balance changes)
            $netCashOut = bcsub($totalOutflows, $totalInflows);
            $netBurn = $daysInPeriod > 0 ? bcdiv($netCashOut, (string)$daysInPeriod, $currencyInfo['currency_decimal_places']) : '0';
            
            // 3. Savings/transfer rate = transfers out ÷ inflows
            $savingsRate = bccomp($totalInflows, '0') > 0 ? 
                bcdiv(bcmul($transferredAway, '-1'), $totalInflows, 4) : '0';
            
            // 4. Expense ratio = expenses ÷ inflows
            $expenseRatio = bccomp($totalInflows, '0') > 0 ? 
                bcdiv(bcmul($spent, '-1'), $totalInflows, 4) : '0';
            
            // Only include metrics if there's meaningful data
            if (bccomp($totalInflows, '0') > 0 || bccomp($totalOutflows, '0') > 0) {
                $metrics[$currencyId] = [
                    'burn_rate' => $burnRate,
                    'net_burn' => $netBurn,
                    'savings_rate' => $savingsRate,
                    'expense_ratio' => $expenseRatio,
                    'days_in_period' => $daysInPeriod,
                    'total_inflows' => $totalInflows,
                    'total_outflows' => $totalOutflows,
                    'currency_id' => $currencyInfo['currency_id'],
                    'currency_name' => $currencyInfo['currency_name'],
                    'currency_code' => $currencyInfo['currency_code'],
                    'currency_symbol' => $currencyInfo['currency_symbol'],
                    'currency_decimal_places' => $currencyInfo['currency_decimal_places'],
                ];
                $metrics['count']++;
            }
        }
        
        return $metrics;
    }

    private function filterStatistics(Carbon $start, Carbon $end, string $type): Collection
    {
        if (0 === $this->statistics->count()) {
            Log::debug('Have no statistic to filter!');

            return new Collection();
        }

        return $this->statistics->filter(
            fn (PeriodStatistic $statistic) => $statistic->start->eq($start) && $statistic->end->eq($end) && $statistic->type === $type
        );
    }

    private function filterPrefixedStatistics(Carbon $start, Carbon $end, string $prefix): Collection
    {
        if (0 === $this->statistics->count()) {
            Log::debug('Have no statistic to filter!');

            return new Collection();
        }

        return $this->statistics->filter(
            fn (PeriodStatistic $statistic) => $statistic->start->eq($start) && $statistic->end->eq($end) && str_starts_with($statistic->type, $prefix)
        );
    }

    private function getSingleModelPeriodByType(Model $model, Carbon $start, Carbon $end, string $type): array
    {
        Log::debug(sprintf('Now in getSingleModelPeriodByType(%s #%d, %s %s, %s)', $model::class, $model->id, $start->format('Y-m-d'), $end->format('Y-m-d'), $type));
        $statistics = $this->filterStatistics($start, $end, $type);

        // nothing found, regenerate them.
        if (0 === $statistics->count()) {
            Log::debug(sprintf('Found nothing in this period for type "%s"', $type));
            if (0 === count($this->transactions)) {
                switch ($model::class) {
                    default:
                        throw new FireflyException(sprintf('Cannot deal with model of type "%s"', $model::class));

                    case Category::class:
                        $this->transactions = $this->categoryRepository->periodCollection($model, $start, $end);

                        break;

                    case Account::class:
                        $this->transactions = $this->accountRepository->periodCollection($model, $start, $end);

                        break;

                    case Tag::class:
                        $this->transactions = $this->tagRepository->periodCollection($model, $start, $end);

                        break;
                }
            }

            switch ($type) {
                default:
                    throw new FireflyException(sprintf('Cannot deal with category period type %s', $type));

                case 'spent':

                    $result = $this->filterTransactionsByType(TransactionTypeEnum::WITHDRAWAL, $start, $end);

                    break;

                case 'earned':
                    $result = $this->filterTransactionsByType(TransactionTypeEnum::DEPOSIT, $start, $end);

                    break;

                case 'transferred_in':
                    $result = $this->filterTransfers('in', $start, $end);

                    break;

                case 'transferred_away':
                    $result = $this->filterTransfers('away', $start, $end);

                    break;
            }
            // each result must be grouped by currency, then saved as period statistic.
            Log::debug(sprintf('Going to group %d found journal(s)', count($result)));
            $grouped = $this->groupByCurrency($result);

            $this->saveGroupedAsStatistics($model, $start, $end, $type, $grouped);

            return $grouped;
        }
        $grouped    = [
            'count' => 0,
        ];

        /** @var PeriodStatistic $statistic */
        foreach ($statistics as $statistic) {
            $id           = (int)$statistic->transaction_currency_id;
            $currency     = Amount::getTransactionCurrencyById($id);
            $grouped[$id] = [
                'amount'                  => (string)$statistic->amount,
                'count'                   => (int)$statistic->count,
                'currency_id'             => $currency->id,
                'currency_name'           => $currency->name,
                'currency_code'           => $currency->code,
                'currency_symbol'         => $currency->symbol,
                'currency_decimal_places' => $currency->decimal_places,
            ];
            $grouped['count'] += (int)$statistic->count;
        }

        return $grouped;
    }

    /**
     * This shows a period overview for a tag. It goes back in time and lists all relevant transactions and sums.
     *
     * @throws FireflyException
     */
    protected function getTagPeriodOverview(Tag $tag, Carbon $start, Carbon $end): array // period overview for tags.
    {
        $this->tagRepository       = app(TagRepositoryInterface::class);
        $this->tagRepository->setUser($tag->user);
        $this->periodStatisticRepo = app(PeriodStatisticRepositoryInterface::class);

        $range                     = Navigation::getViewRange(true);
        [$start, $end]             = $end < $start ? [$end, $start] : [$start, $end];

        /** @var array $dates */
        $dates                     = Navigation::blockPeriods($start, $end, $range);
        $entries                   = [];
        [$start, $end]             = $this->getPeriodFromBlocks($dates, $start, $end);
        $this->statistics          = $this->periodStatisticRepo->allInRangeForModel($tag, $start, $end);


        Log::debug(sprintf('Count of loops: %d', count($dates)));
        foreach ($dates as $currentDate) {
            $entries[] = $this->getSingleModelPeriod($tag, $currentDate['period'], $currentDate['start'], $currentDate['end']);
        }

        return $entries;
    }

    /**
     * @throws FireflyException
     */
    protected function getTransactionPeriodOverview(string $transactionType, Carbon $start, Carbon $end): array
    {
        $range         = Navigation::getViewRange(true);
        $types         = config(sprintf('firefly.transactionTypesByType.%s', $transactionType));
        [$start, $end] = $end < $start ? [$end, $start] : [$start, $end];

        // properties for cache
        $cache         = new CacheProperties();
        $cache->addProperty($start);
        $cache->addProperty($end);
        $cache->addProperty('transactions-period-entries');
        $cache->addProperty($transactionType);
        if ($cache->has()) {
            return $cache->get();
        }

        /** @var array $dates */
        $dates         = Navigation::blockPeriods($start, $end, $range);
        $entries       = [];
        $spent         = [];
        $earned        = [];
        $transferred   = [];
        // collect all journals in this period (regardless of type)
        $collector     = app(GroupCollectorInterface::class);
        $collector->setTypes($types)->setRange($start, $end);
        $genericSet    = $collector->getExtractedJournals();
        $loops         = 0;

        foreach ($dates as $currentDate) {
            $title = Navigation::periodShow($currentDate['end'], $currentDate['period']);

            if ($loops < 10) {
                // set to correct array
                if ('expenses' === $transactionType || 'withdrawal' === $transactionType) {
                    $spent = $this->filterJournalsByDate($genericSet, $currentDate['start'], $currentDate['end']);
                }
                if ('revenue' === $transactionType || 'deposit' === $transactionType) {
                    $earned = $this->filterJournalsByDate($genericSet, $currentDate['start'], $currentDate['end']);
                }
                if ('transfer' === $transactionType || 'transfers' === $transactionType) {
                    $transferred = $this->filterJournalsByDate($genericSet, $currentDate['start'], $currentDate['end']);
                }
            }
            $entries[]
                   = [
                       'title'              => $title,
                       'route'              => route('transactions.index', [$transactionType, $currentDate['start']->format('Y-m-d'), $currentDate['end']->format('Y-m-d')]),
                       'total_transactions' => count($spent) + count($earned) + count($transferred),
                       'spent'              => $this->groupByCurrency($spent),
                       'earned'             => $this->groupByCurrency($earned),
                       'transferred'        => $this->groupByCurrency($transferred),
                   ];
            ++$loops;
        }

        return $entries;
    }

    private function saveGroupedAsStatistics(Model $model, Carbon $start, Carbon $end, string $type, array $array): void
    {
        unset($array['count']);
        Log::debug(sprintf('saveGroupedAsStatistics(%s #%d, %s, %s, "%s", array(%d))', $model::class, $model->id, $start->format('Y-m-d'), $end->format('Y-m-d'), $type, count($array)));
        foreach ($array as $entry) {
            $this->periodStatisticRepo->saveStatistic($model, $entry['currency_id'], $start, $end, $type, $entry['count'], $entry['amount']);
        }
        if (0 === count($array)) {
            Log::debug('Save empty statistic.');
            $this->periodStatisticRepo->saveStatistic($model, $this->primaryCurrency->id, $start, $end, $type, 0, '0');
        }
    }

    private function saveGroupedForPrefix(string $prefix, Carbon $start, Carbon $end, string $type, array $array): void
    {
        unset($array['count']);
        Log::debug(sprintf('saveGroupedForPrefix("%s", %s, %s, "%s", array(%d))', $prefix, $start->format('Y-m-d'), $end->format('Y-m-d'), $type, count($array)));
        foreach ($array as $entry) {
            $this->periodStatisticRepo->savePrefixedStatistic($prefix, $entry['currency_id'], $start, $end, $type, $entry['count'], $entry['amount']);
        }
        if (0 === count($array)) {
            Log::debug('Save empty statistic.');
            $this->periodStatisticRepo->savePrefixedStatistic($prefix, $this->primaryCurrency->id, $start, $end, $type, 0, '0');
        }
    }

    /**
     * Filter a list of journals by a set of dates, and then group them by currency.
     */
    private function filterJournalsByDate(array $array, Carbon $start, Carbon $end): array
    {
        $result = [];

        /** @var array $journal */
        foreach ($array as $journal) {
            if ($journal['date'] <= $end && $journal['date'] >= $start) {
                $result[] = $journal;
            }
        }

        return $result;
    }

    private function filterTransactionsByType(TransactionTypeEnum $type, Carbon $start, Carbon $end): array
    {
        $result = [];

        /**
         * @var int   $index
         * @var array $item
         */
        foreach ($this->transactions as $item) {
            $date = Carbon::parse($item['date']);
            $fits = $item['type'] === $type->value && $date >= $start && $date <= $end;
            if ($fits) {

                // if type is withdrawal, negative amount:
                if (TransactionTypeEnum::WITHDRAWAL === $type && 1 === bccomp((string)$item['amount'], '0')) {
                    $item['amount'] = Steam::negative($item['amount']);
                }
                $result[] = $item;
            }
        }

        return $result;
    }

    private function filterTransfers(string $direction, Carbon $start, Carbon $end): array
    {
        $result = [];

        /**
         * @var int   $index
         * @var array $item
         */
        foreach ($this->transactions as $item) {
            $date = Carbon::parse($item['date']);
            if ($date >= $start && $date <= $end) {
                if ('Transfer' === $item['type'] && 'away' === $direction && -1 === bccomp((string)$item['amount'], '0')) {
                    $result[] = $item;

                    continue;
                }
                if ('Transfer' === $item['type'] && 'in' === $direction && 1 === bccomp((string)$item['amount'], '0')) {
                    $result[] = $item;
                }
            }
        }

        return $result;
    }

    private function groupByCurrency(array $journals): array
    {
        Log::debug('groupByCurrency()');
        $return = [
            'count' => 0,
        ];
        if (0 === count($journals)) {
            return $return;
        }

        /** @var array $journal */
        foreach ($journals as $journal) {
            $currencyId                    = (int)$journal['currency_id'];
            $currencyCode                  = $journal['currency_code'];
            $currencyName                  = $journal['currency_name'];
            $currencySymbol                = $journal['currency_symbol'];
            $currencyDecimalPlaces         = $journal['currency_decimal_places'];
            $foreignCurrencyId             = $journal['foreign_currency_id'];
            $amount                        = (string) ($journal['amount'] ?? '0');

            if ($this->convertToPrimary && $currencyId !== $this->primaryCurrency->id && $foreignCurrencyId !== $this->primaryCurrency->id) {
                $amount                = (string)  ($journal['pc_amount'] ?? '0');
                $currencyId            = $this->primaryCurrency->id;
                $currencyCode          = $this->primaryCurrency->code;
                $currencyName          = $this->primaryCurrency->name;
                $currencySymbol        = $this->primaryCurrency->symbol;
                $currencyDecimalPlaces = $this->primaryCurrency->decimal_places;
            }
            if ($this->convertToPrimary && $currencyId !== $this->primaryCurrency->id && $foreignCurrencyId === $this->primaryCurrency->id) {
                $currencyId            = (int)$foreignCurrencyId;
                $currencyCode          = $journal['foreign_currency_code'];
                $currencyName          = $journal['foreign_currency_name'];
                $currencySymbol        = $journal['foreign_currency_symbol'];
                $currencyDecimalPlaces = $journal['foreign_currency_decimal_places'];
                $amount                = (string) ($journal['foreign_amount'] ?? '0');
            }
            $return[$currencyId] ??= [
                'amount'                  => '0',
                'count'                   => 0,
                'currency_id'             => $currencyId,
                'currency_name'           => $currencyName,
                'currency_code'           => $currencyCode,
                'currency_symbol'         => $currencySymbol,
                'currency_decimal_places' => $currencyDecimalPlaces,
            ];


            $return[$currencyId]['amount'] = bcadd((string)$return[$currencyId]['amount'], $amount);
            ++$return[$currencyId]['count'];
            ++$return['count'];
        }

        return $return;
    }
}
