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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

class FinpayGatewayService implements GatewayInterface, InitiatesPaymentInterface
{
    private const INITIATE_PATH = '/pg/payment/card/initiate';
    private const REQUEST_TIMEOUT_SECONDS = 30;
    private const TRANSACTIONS_TABLE = 'finpay_transactions';

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
            'customer' => $this->resolveCustomerPayload($request),
            'order' => $this->resolveOrderPayload($request),
            'url' => [
                'callbackUrl' => $this->resolveGatewayCallbackUrl(),
            ],
        ]);
        $this->storeInitTransaction($request->orderId(), $responsePayload);

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

        $result = $this->initiate($request);

        return [
            'status' => $result->status()->value,
            'responseCode' => $result->meta()->get('responseCode'),
            'responseMessage' => $result->meta()->get('responseMessage'),
            'orderId' => $order->getId(),
            'transactionId' => $result->transactionId(),
            'redirect_url' => $result->redirectUrl(),
            'gatewayReference' => $result->gatewayReference(),
            'raw' => $result->raw()->all(),
        ];
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
        $value = DB::table(self::TRANSACTIONS_TABLE)
            ->where('order_id', $orderId)
            ->value('callback_url');

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    public function forgetUserCallbackUrlForOrder(string $orderId): void
    {
        DB::table(self::TRANSACTIONS_TABLE)
            ->where('order_id', $orderId)
            ->update([
                'callback_url' => null,
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function storeCallbackResult(
        string $orderId,
        array $payload,
        bool $forwarded,
        ?int $forwardStatus,
        ?string $error = null
    ): void {
        $now = Carbon::now();
        DB::table(self::TRANSACTIONS_TABLE)->upsert([
            [
                'order_id' => $orderId,
                'callback_payload' => $payload,
                'forwarded' => $forwarded,
                'forward_status' => $forwardStatus,
                'forward_error' => $this->normalizeNullableString($error),
                'forwarded_at' => $forwarded ? $now : null,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        ], ['order_id'], [
            'callback_payload',
            'forwarded',
            'forward_status',
            'forward_error',
            'forwarded_at',
            'updated_at',
        ]);
    }

    private function buildAuthorizationHeader(): string
    {
        $merchantId = (string) Config::get('finpay.merchant_id');
        $merchantKey = (string) Config::get('finpay.merchant_key');

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
        $baseUrl = rtrim((string) Config::get('finpay.base_url'), '/');

        return $baseUrl . self::INITIATE_PATH;
    }

    private function resolveGatewayCallbackUrl(): string
    {
        return URL::route('finpay.callback');
    }

    private function storeUserCallbackUrl(string $orderId, string $userCallbackUrl): void
    {
        $now = Carbon::now();
        DB::table(self::TRANSACTIONS_TABLE)->upsert([
            [
                'order_id' => $orderId,
                'callback_url' => $userCallbackUrl,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        ], ['order_id'], [
            'callback_url',
            'updated_at',
        ]);
    }

    /**
     * @param array<string, mixed> $responsePayload
     */
    private function storeInitTransaction(string $orderId, array $responsePayload): void
    {
        $now = Carbon::now();
        DB::table(self::TRANSACTIONS_TABLE)->upsert([
            [
                'order_id' => $orderId,
                'status' => $this->resolveInitStatus($responsePayload)->value,
                'response_code' => $this->extractString($responsePayload, ['responseCode']),
                'response_message' => $this->extractString($responsePayload, ['responseMessage']),
                'transaction_id' => $this->extractString($responsePayload, ['transactionId', 'trxId', 'transaction.id']),
                'gateway_reference' => $this->extractString($responsePayload, ['gatewayReference', 'referenceNo', 'invoiceNo']),
                'provider_payload' => $responsePayload,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        ], ['order_id'], [
            'status',
            'response_code',
            'response_message',
            'transaction_id',
            'gateway_reference',
            'provider_payload',
            'updated_at',
        ]);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveCustomerPayload(PaymentRequestData $request): array
    {
        $customerPayload = $request->meta()->get('customer', []);

        return is_array($customerPayload) ? $customerPayload : [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveOrderPayload(PaymentRequestData $request): array
    {
        $orderOverrides = $request->meta()->get('order', []);
        if (!is_array($orderOverrides)) {
            $orderOverrides = [];
        }

        return array_replace([
            'id' => $request->orderId(),
            'amount' => $request->amount(),
            'currency' => $request->currency(),
        ], $orderOverrides);
    }

    /**
     * @param array<string, mixed> $response
     */
    protected function mapInitResponseToResult(array $response): PaymentInitResultData
    {
        $status = $this->resolveInitStatus($response);
        $transactionId = $this->extractString($response, ['transactionId', 'trxId', 'transaction.id']);
        $redirectUrl = $this->extractString($response, ['redirectUrl', 'redirecturl', 'paymentUrl', 'url.redirectUrl']);
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
            'FINPAY_BASE_URL' => Config::get('finpay.base_url'),
            'FINPAY_MERCHANT_ID' => Config::get('finpay.merchant_id'),
            'FINPAY_MERCHANT_KEY' => Config::get('finpay.merchant_key'),
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
