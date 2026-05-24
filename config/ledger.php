<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Ledger
    |--------------------------------------------------------------------------
    |
    | If your application only uses one ledger, set its slug here and the
    | HasAccounts trait will resolve it automatically. Multi-ledger apps
    | should leave this null and pass the slug explicitly.
    |
    */
    'default_ledger_slug' => env('LEDGER_DEFAULT_SLUG'),

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | Override the table names if they collide with existing tables in your
    | application. Most users will never need to touch this.
    |
    */
    'table_names' => [
        'ledgers' => 'ledgers',
        'accounts' => 'accounts',
        'transactions' => 'transactions',
        'entries' => 'entries',
        'balances' => 'balances',
    ],

    /*
    |--------------------------------------------------------------------------
    | Additional Validators
    |--------------------------------------------------------------------------
    |
    | The package's required validators (minimum entries, positive amounts,
    | single currency, ledger scope, account currency match, account state,
    | balanced transaction) are ALWAYS run first and cannot be removed. Any
    | classes you list here are appended after the required set.
    |
    | Custom validators must implement
    | Syriable\Ledger\Validators\TransactionValidator and MUST be pure —
    | they may not perform I/O.
    |
    */
    'validators' => [
        // \App\Ledger\Validators\AmountCeilingValidator::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Maximum Clock Skew
    |--------------------------------------------------------------------------
    |
    | Maximum number of seconds a Posting's posted_at may be in the future
    | relative to the package Clock. Protects against clock-skew bugs and
    | callers that backdate from the future, both of which silently corrupt
    | every balanceAsOf() query.
    |
    | Set to 0 to forbid any future-dated postings. Sensible default: 300s.
    |
    */
    'max_clock_skew_seconds' => env('LEDGER_MAX_CLOCK_SKEW_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | Historical Lower Bound
    |--------------------------------------------------------------------------
    |
    | Optional inclusive lower bound for posted_at. Accepts:
    |   - null   (no lower bound)
    |   - an ISO-8601 datetime string (e.g. '2024-01-01T00:00:00Z')
    |   - a callable returning a \DateTimeInterface
    |
    | Useful to prevent imports or buggy postings from booking entries
    | before the ledger was actually opened.
    |
    */
    'historical_lower_bound' => env('LEDGER_HISTORICAL_LOWER_BOUND'),

    /*
    |--------------------------------------------------------------------------
    | Recorder
    |--------------------------------------------------------------------------
    |
    | Tunables for the TransactionRecorder. The defaults are appropriate for
    | almost every workload; only adjust if you have measured a specific
    | bottleneck.
    |
    |   max_attempts — deadlock-retry budget for a single Posting.
    |
    */
    'recorder' => [
        'max_attempts' => (int) env('LEDGER_RECORDER_MAX_ATTEMPTS', 3),
    ],

];
