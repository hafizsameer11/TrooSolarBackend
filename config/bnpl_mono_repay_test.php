<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mono Direct Debit production test lane (isolated from normal BNPL flow)
    |--------------------------------------------------------------------------
    |
    | Enable only for whitelisted user IDs. Requires a shared secret on bootstrap.
    | Set BNPL_MONO_REPAY_TEST_ENABLED=false when not testing.
    |
    */

    'enabled' => filter_var(env('BNPL_MONO_REPAY_TEST_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'user_ids' => array_values(array_filter(array_map(
        'intval',
        array_map('trim', explode(',', (string) env('BNPL_MONO_REPAY_TEST_USER_IDS', '')))
    ))),

    'secret' => env('BNPL_MONO_REPAY_TEST_SECRET'),

    /** Bundle used for order snapshot (0 = cheapest available bundle). */
    'bundle_id' => (int) env('BNPL_MONO_REPAY_TEST_BUNDLE_ID', 0),

    'installment_amount' => (float) env('BNPL_MONO_REPAY_TEST_INSTALLMENT_AMOUNT', 2000),

    'installment_count' => max(1, (int) env('BNPL_MONO_REPAY_TEST_INSTALLMENT_COUNT', 3)),

    'down_payment' => (float) env('BNPL_MONO_REPAY_TEST_DOWN_PAYMENT', 1000),

    /** When true, first installment due date is today (for collect-due-installments). */
    'due_today' => filter_var(env('BNPL_MONO_REPAY_TEST_DUE_TODAY', true), FILTER_VALIDATE_BOOLEAN),

];
