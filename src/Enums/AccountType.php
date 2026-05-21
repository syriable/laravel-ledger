<?php

declare(strict_types=1);

namespace Syriable\Ledger\Enums;

/**
 * AccountType — the five standard accounting account types.
 *
 * The mapping to NormalBalance follows the accounting equation:
 *   Assets + Expenses    have a normal DEBIT balance.
 *   Liabilities, Equity, Revenue  have a normal CREDIT balance.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

    public function normalBalance(): NormalBalance
    {
        return match ($this) {
            self::Asset, self::Expense => NormalBalance::Debit,
            self::Liability, self::Equity, self::Revenue => NormalBalance::Credit,
        };
    }
}
