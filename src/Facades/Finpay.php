<?php

namespace Finpay\Facades;

use Finpay\Services\FinpayGatewayService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array initiatePayment(array $customer, array $order, string $callbackUrl)
 * @method static array verifyPayment(string $transactionId)
 * @method static array getPaymentStatus(string $transactionId)
 *
 * @see FinpayGatewayService
 */
class Finpay extends Facade
{
    final public const VERSION = '1.0.0';

    protected static function getFacadeAccessor(): string
    {
        return FinpayGatewayService::class;
    }
}
