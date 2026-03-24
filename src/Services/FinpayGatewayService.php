<?php

namespace Finpay\Services;

use Finpay\Exceptions\NotImplementedException;

class FinpayGatewayService
{
    public function initiatePayment(array $customer, array $order, string $callbackUrl): array
    {
        throw new NotImplementedException();
    }

    public function verifyPayment(string $transactionId): array
    {
        throw new NotImplementedException();
    }

    public function getPaymentStatus(string $transactionId): array
    {
        throw new NotImplementedException();
    }
}
