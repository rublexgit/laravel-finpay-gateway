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

// Unified wrapper response shape:
// [
//     'status' => 'pending',
//     'responseCode' => '2000000',
//     'responseMessage' => 'Initiated',
//     'orderId' => 'INV-1774369486',
//     'transactionId' => 'TRX-123',
//     'redirect_url' => 'https://pay.example/redirect/...',
//     'gatewayReference' => 'REF-123',
//     'raw' => [/* full provider payload */],
// ]
```

Provider-specific fields are preserved under `raw`.

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
    callbackUrl: 'https://your-app.example.com/finpay/final-callback',
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

## Migration note

- Existing facade and wrapper method signatures are unchanged.
- New integrations can use the shared core contract DTOs directly.

### Callback flow

- Finpay sends callback to package route: `POST /finpay/callback` (`finpay.callback`).
- Package finds the stored user callback URL by `orderId`.
- Package forwards the full callback payload to the user callback URL.
