<?php

declare(strict_types=1);

namespace Finpay\Services;

use Finpay\Data\CustomerData;
use Finpay\Data\OrderData;
use Finpay\Exceptions\NotImplementedException;
use Rublex\CoreGateway\Contracts\Common\GatewayInterface;
use Rublex\CoreGateway\Contracts\Payment\InitiatesPaymentInterface;
use Rublex\CoreGateway\Data\DynamicDataBag;
use Rublex\CoreGateway\Data\PaymentInitResultData;
use Rublex\CoreGateway\Data\PaymentRequestData;
use Rublex\CoreGateway\Enums\GatewayType;
use Rublex\CoreGateway\Enums\PaymentStatus;
use Illuminate\Support\Facades\Http;

class FinpayGatewayService implements GatewayInterface, InitiatesPaymentInterface
{
    private const INITIATE_PATH = '/pg/payment/card/initiate';
    private const REQUEST_TIMEOUT_SECONDS = 30;
    private const CALLBACK_TTL_MINUTES = 120;

    public function code(): string
    {
        return 'finpay';
    }

    public function type(): GatewayType
    {
        return GatewayType::FIAT;
    }

    public function initiate(PaymentRequestData $request): PaymentInitResultData
    {
        $missingConfigKeys = $this->getMissingConfigKeys();
        if ($missingConfigKeys !== []) {
            return $this->mapInitResponseToResult([
                'responseCode' => '5000002',
                'responseMessage' => 'Finpay configuration is missing.',
                'missingConfig' => $missingConfigKeys,
            ]);
        }

        $this->storeUserCallbackUrl($request->orderId(), $request->callbackUrl());

        $responsePayload = $this->sendInitiateRequest([
            'customer' => $request->meta()->get('customer', []),
            'order' => $request->meta()->get('order', [
                'id' => $request->orderId(),
                'amount' => $request->amount(),
                'currency' => $request->currency(),
            ]),
            'url' => [
                'callbackUrl' => $this->resolveGatewayCallbackUrl(),
            ],
        ]);

        return $this->mapInitResponseToResult($responsePayload);
    }

    public function initiatePayment(CustomerData $customer, OrderData $order, string $userCallbackUrl): array
    {
        $request = new PaymentRequestData(
            gatewayCode: $this->code(),
            orderId: $order->getId(),
            amount: $order->amount(),
            currency: $order->currency(),
            callbackUrl: $userCallbackUrl,
            meta: new DynamicDataBag([
                'customer' => $customer->toArray(),
                'order' => $order->toArray(),
            ])
        );

        return $this->initiate($request)->raw()->all();
    }

    public function verifyPayment(string $transactionId): array
    {
        throw new NotImplementedException('verify');
    }

    public function getPaymentStatus(string $transactionId): array
    {
        throw new NotImplementedException('queryStatus');
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

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    protected function sendInitiateRequest(array $payload): array
    {
        $response = Http::acceptJson()->asJson()
            ->withHeaders([
                'Authorization' => $this->buildAuthorizationHeader(),
            ])->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->post($this->buildInitiateUrl(), $payload);

        $decoded = $response->json();
        if (!is_array($decoded)) {
            return [
                'responseCode' => (string) $response->status(),
                'responseMessage' => 'Invalid JSON response from Finpay.',
            ];
        }

        return $decoded;
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

    /**
     * @param array<string, mixed> $response
     */
    protected function mapInitResponseToResult(array $response): PaymentInitResultData
    {
        $status = $this->resolveInitStatus($response);
        $transactionId = $this->extractString($response, ['transactionId', 'trxId', 'transaction.id']);
        $redirectUrl = $this->extractString($response, ['redirectUrl', 'paymentUrl', 'url.redirectUrl']);
        $gatewayReference = $this->extractString($response, ['gatewayReference', 'referenceNo', 'invoiceNo']);

        return new PaymentInitResultData(
            status: $status,
            transactionId: $transactionId,
            redirectUrl: $redirectUrl,
            gatewayReference: $gatewayReference,
            meta: new DynamicDataBag([
                'responseCode' => $this->extractString($response, ['responseCode']),
                'responseMessage' => $this->extractString($response, ['responseMessage']),
            ]),
            raw: new DynamicDataBag($response)
        );
    }

    /**
     * @param array<string, mixed> $response
     */
    private function resolveInitStatus(array $response): PaymentStatus
    {
        $statusValue = $this->extractString($response, ['status', 'paymentStatus']);
        if ($statusValue !== null) {
            return match (strtolower($statusValue)) {
                'success', 'paid', 'completed' => PaymentStatus::SUCCESS,
                'pending', 'processing', 'waiting' => PaymentStatus::PENDING,
                'failed', 'error' => PaymentStatus::FAILED,
                'canceled', 'cancelled' => PaymentStatus::CANCELED,
                'expired' => PaymentStatus::EXPIRED,
                default => PaymentStatus::UNKNOWN,
            };
        }

        $responseCode = $this->extractString($response, ['responseCode']);
        if ($responseCode === null) {
            return PaymentStatus::UNKNOWN;
        }

        return str_starts_with($responseCode, '2') ? PaymentStatus::PENDING : PaymentStatus::FAILED;
    }

    /**
     * @param array<string, mixed> $response
     * @param array<int, string> $paths
     */
    private function extractString(array $response, array $paths): ?string
    {
        foreach ($paths as $path) {
            $segments = explode('.', $path);
            $cursor = $response;

            foreach ($segments as $segment) {
                if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                    continue 2;
                }

                $cursor = $cursor[$segment];
            }

            if (is_string($cursor) && trim($cursor) !== '') {
                return $cursor;
            }
        }

        return null;
    }

    private function getMissingConfigKeys(): array
    {
        $requiredConfig = [
            'FINPAY_BASE_URL' => config('finpay.base_url'),
            'FINPAY_MERCHANT_ID' => config('finpay.merchant_id'),
            'FINPAY_MERCHANT_KEY' => config('finpay.merchant_key'),
        ];

        $missingKeys = [];
        foreach ($requiredConfig as $key => $value) {
            if (!is_string($value) || trim($value) === '') {
                $missingKeys[] = $key;
            }
        }

        return $missingKeys;
    }
}
