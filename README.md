# Laravel Finpay Gateway

[![Latest Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://packagist.org/packages/rublex/laravel-finpay-gateway)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

A Laravel payment gateway package for Finpay integration.

## Features

- Payment initiation
- Payment verification
- Payment status inquiry
- Callback route for gateway notifications
- Configurable via environment variables
- Laravel facade support

## Installation

```bash
composer require rublex/laravel-finpay-gateway
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Finpay\FinpayServiceProvider" --tag="finpay-config"
```

Add credentials to your `.env` file:

```env
FINPAY_BASE_URL=https://devo.finnet.co.id
FINPAY_MERCHANT_ID=
FINPAY_MERCHANT_KEY=
```

## Quick Start

```php
use Finpay\Data\CustomerData;
use Finpay\Data\OrderData;
use Finpay\Facades\Finpay;

$response = Finpay::initiatePayment(
    new CustomerData(
        email: 'hajar.finnet@gmail.com',
        firstName: 'Hajar',
        lastName: 'Ismail',
        mobilePhone: '+6281286288844'
    ),
    new OrderData(
        id: 'INV-1774369486',
        amount: '10',
        currency: 'EUR',
        description: 'Testing'
    ),
    userCallbackUrl: 'https://example.com/payment/final-callback'
);
```

## Contract-Based Usage

```php
use Finpay\Services\FinpayGatewayService;
use Rublex\CoreGateway\Data\DynamicDataBag;
use Rublex\CoreGateway\Data\PaymentRequestData;

$gateway = app(FinpayGatewayService::class);

$result = $gateway->initiate(new PaymentRequestData(
    gatewayCode: $gateway->code(),
    orderId: 'INV-1774369486',
    amount: '10',
    currency: 'EUR',
    callbackUrl: 'https://example.com/payment/final-callback',
    meta: new DynamicDataBag([
        'customer' => [
            'email' => 'hajar.finnet@gmail.com',
            'firstName' => 'Hajar',
            'lastName' => 'Ismail',
            'mobilePhone' => '+6281286288844',
        ],
        'order' => [
            'description' => 'Testing',
        ],
    ])
));
```

## Backward Compatibility

- `initiatePayment(CustomerData, OrderData, string)` remains available and now wraps `initiate(PaymentRequestData)`.
- `verifyPayment()` and `getPaymentStatus()` remain explicit package methods and still throw not-implemented exceptions.

## Documentation

For installation and usage instructions, see [USAGE.md](USAGE.md).

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).
