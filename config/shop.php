<?php

return [
    'demo' => env('SHOP_DEMO', true),
    'bulk_base' => 'reseller',
    'unpaid_minutes' => max(15, (int) env('UNPAID_CHECKOUT_MINUTES', 30)),
    'partial_due_days' => max(7, min(30, (int) env('PARTIAL_PAYMENT_DUE_DAYS', 7))),
    'paymongo' => [
        'mode' => env('PAYMONGO_MODE', 'test'),
        'secret' => env('PAYMONGO_SECRET_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        'methods' => array_filter(explode(',', env('PAYMONGO_METHODS', 'card,gcash,qrph'))),
    ],
    'lalamove' => [
        'mode' => env('LALAMOVE_MODE', 'sandbox'),
        'key' => env('LALAMOVE_API_KEY'),
        'secret' => env('LALAMOVE_API_SECRET'),
        'service' => env('LALAMOVE_SERVICE_TYPE', 'MOTORCYCLE'),
        'address' => env('STORE_PICKUP_ADDRESS'),
        'lat' => env('STORE_PICKUP_LAT'),
        'lng' => env('STORE_PICKUP_LNG'),
        'phone' => env('STORE_PICKUP_PHONE'),
    ],
    'grok' => ['key' => env('GROK_API_KEY'), 'model' => env('GROK_MODEL', 'grok-4')],
];
