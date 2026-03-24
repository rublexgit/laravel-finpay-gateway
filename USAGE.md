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
FINPAY_BASE_URL=https://devo.finnet.co.id
FINPAY_MERCHANT_ID=
FINPAY_MERCHANT_KEY=
```

## Usage

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
    userCallbackUrl: 'https://your-app.example.com/finpay/final-callback'
);
```

### Callback flow

- Finpay sends callback to package route: `POST /finpay/callback` (`finpay.callback`).
- Package finds the stored user callback URL by `orderId`.
- Package forwards the full callback payload to the user callback URL.
