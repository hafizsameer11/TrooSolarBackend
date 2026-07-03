<?php

return [
    'delivery_fee' => env('CHECKOUT_DELIVERY_FEE', 0),
    'delivery_min_working_days' => (int) env('CHECKOUT_DELIVERY_MIN_DAYS', 7),
    'delivery_max_working_days' => (int) env('CHECKOUT_DELIVERY_MAX_DAYS', 10),
    'insurance_fee' => (int) env('CHECKOUT_INSURANCE_FEE', 0),
    'vat_percentage' => (float) env('CHECKOUT_VAT_PERCENTAGE', 7.5),
    'insurance_fee_percentage' => (float) env('CHECKOUT_INSURANCE_PERCENTAGE', 3),
    'installation_flat_addon' => (int) env('CHECKOUT_INSTALLATION_FLAT_ADDON', 0),
    'installation_schedule_working_days' => (int) env('CHECKOUT_INSTALL_LEAD_DAYS', 7),
    'installation_price' => 2000,
    'installation_text' => 'Installation will be carried out by our skilled technicians. You can choose to use our installers.',
    'product_categories' => [
        ['key' => 'full-kit', 'label' => 'Full solar kit (panels + inverter + battery)'],
        ['key' => 'inverter-battery', 'label' => 'Inverter & battery'],
        ['key' => 'battery-only', 'label' => 'Battery only'],
        ['key' => 'inverter-only', 'label' => 'Inverter only'],
        ['key' => 'panels-only', 'label' => 'Solar panels only'],
    ],
];