<?php

declare(strict_types=1);

namespace Finpay\Facades;

use Finpay\Services\FinpayGatewayService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Rublex\CoreGateway\Data\PaymentInitResultData initiate(\Rublex\CoreGateway\Data\PaymentRequestData $request)
 * @method static array initiatePayment(\Finpay\Data\CustomerData $customer, \Finpay\Data\OrderData $order, string $userCallbackUrl)
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
