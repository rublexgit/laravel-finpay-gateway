# Laravel Finpay Gateway Usage

## Installation

1. Install the package via Composer:

```bash
composer require rublex/laravel-finpay-gateway
```

2. Publish the configuration file:

```bash
php artisan vendor:publish --provider="Finpay\FinpayServiceProvider" --tag="finpay-config"
```

3. Add your Finpay credentials to your `.env` file:

```env
FINPAY_BASE_URL=
FINPAY_MERCHANT_ID=
FINPAY_MERCHANT_KEY=
```

## Usage

Usage examples will be added in a future release.
