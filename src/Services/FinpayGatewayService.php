<?php

namespace Finpay\Services;

use Finpay\Data\CustomerData;
use Finpay\Data\OrderData;
use Finpay\Exceptions\NotImplementedException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class FinpayGatewayService
{
    private const INITIATE_PATH = '/pg/payment/card/initiate';
    private const REQUEST_TIMEOUT_SECONDS = 30;
    private const CALLBACK_TTL_MINUTES = 120;

    public function initiatePayment(CustomerData $customer, OrderData $order, string $userCallbackUrl): array
    {
        if (filter_var($userCallbackUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('User callback URL is invalid.');
        }

        $this->storeUserCallbackUrl($order->getId(), $userCallbackUrl);

        $response = Http::acceptJson()->asJson()
            ->withHeaders([
                'Authorization' => $this->buildAuthorizationHeader(),
            ])->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->post($this->buildInitiateUrl(), [
                'customer' => $customer->toArray(),
                'order' => $order->toArray(),
                'url' => [
                    'callbackUrl' => $this->resolveGatewayCallbackUrl(),
                ],
            ]);

        $decoded = $response->json();
        if (!is_array($decoded)) {
            return [
                'responseCode' => (string) $response->status(),
                'responseMessage' => 'Invalid JSON response from Finpay.',
            ];
        }

        return $decoded;
    }

    public function verifyPayment(string $transactionId): array
    {
        throw new NotImplementedException();
    }

    public function getPaymentStatus(string $transactionId): array
    {
        throw new NotImplementedException();
    }

    public function getUserCallbackUrlForOrder(string $orderId): ?string
    {
        $value = cache()->get($this->callbackCacheKey($orderId));

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function forgetUserCallbackUrlForOrder(string $orderId): void
    {
        cache()->forget($this->callbackCacheKey($orderId));
    }

    private function buildAuthorizationHeader(): string
    {
        $merchantId = (string) config('finpay.merchant_id');
        $merchantKey = (string) config('finpay.merchant_key');

        return 'Basic ' . base64_encode($merchantId . ':' . $merchantKey);
    }

    private function buildInitiateUrl(): string
    {
        $baseUrl = rtrim((string) config('finpay.base_url'), '/');

        return $baseUrl . self::INITIATE_PATH;
    }

    private function resolveGatewayCallbackUrl(): string
    {
        return route('finpay.callback');
    }

    private function storeUserCallbackUrl(string $orderId, string $userCallbackUrl): void
    {
        cache()->put(
            $this->callbackCacheKey($orderId),
            $userCallbackUrl,
            now()->addMinutes(self::CALLBACK_TTL_MINUTES)
        );
    }

    private function callbackCacheKey(string $orderId): string
    {
        return 'finpay:callback:' . $orderId;
    }
}
