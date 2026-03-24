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
FINPAY_BASE_URL=
FINPAY_MERCHANT_ID=
FINPAY_MERCHANT_KEY=
```

## Documentation

For installation and usage instructions, see [USAGE.md](USAGE.md).

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).
