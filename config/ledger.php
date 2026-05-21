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

];
