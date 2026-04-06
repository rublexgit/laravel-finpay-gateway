<?php

return [
    'base_url' => env('FINPAY_BASE_URL'),
    'merchant_id' => env('FINPAY_MERCHANT_ID'),
    'merchant_key' => env('FINPAY_MERCHANT_KEY'),

    'http' => [
        'timeout' => env('FINPAY_HTTP_TIMEOUT', 30),
        'connect_timeout' => env('FINPAY_HTTP_CONNECT_TIMEOUT', 10),
        'proxy' => env('FINPAY_HTTP_PROXY'),
        'verify' => env('FINPAY_HTTP_VERIFY', true),
    ],
];
