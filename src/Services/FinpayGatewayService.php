<?php

declare(strict_types=1);

namespace Finpay\Services;

use DateTimeImmutable;
use Finpay\Data\CustomerData;
use Finpay\Data\OrderData;
use Finpay\Exceptions\NotImplementedException;
use Rublex\CoreGateway\Contracts\Common\GatewayInterface;
use Rublex\CoreGateway\Contracts\Http\ConfiguresGatewayHttpInterface;
use Rublex\CoreGateway\Contracts\Payment\InitiatesPaymentInterface;
use Rublex\CoreGateway\Data\DynamicDataBag;
use Rublex\CoreGateway\Data\PaymentInitResultData;
use Rublex\CoreGateway\Data\PaymentOutcomeData;
use Rublex\CoreGateway\Data\PaymentRequestData;
use Rublex\CoreGateway\Enums\GatewayType;
use Rublex\CoreGateway\Enums\PaymentStatus;
use Rublex\CoreGateway\Support\GatewayHttpOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Throwable;

class FinpayGatewayService implements GatewayInterface, InitiatesPaymentInterface, ConfiguresGatewayHttpInterface
{
    private const INITIATE_PATH = '/pg/payment/card/initiate';
    private const CHECK_CARD_PATH = '/sof/bp/checkCard/';
    private const TRANSACTIONS_TABLE = 'finpay_transactions';

    public const DEFAULT_PAYMENT_METHOD = 'cc';

    /**
     * Catalog of payment methods this driver can request from Finpay. Each entry
     * maps a stable identifier (used by callers and by the FiatGateway admin UI)
     * to the `sourceOfFunds.type` value Finpay expects on the initiate call.
     *
     * Channels documented at https://hub.finpay.id/docs/finpay-pg/api are listed
     * here verbatim. `googlepay` and `applepay` are placeholders kept for parity
     * with the Aviagram driver — Finpay must whitelist them on the merchant
     * account before they will succeed at runtime.
     *
     * @var array<string, array{label: string, source_of_funds: string}>
     */
    public const PAYMENT_METHODS = [
        'cc'              => ['label' => 'Credit / Debit Card', 'source_of_funds' => 'cc'],
        'googlepay'       => ['label' => 'Google Pay',          'source_of_funds' => 'googlepay'],
        'applepay'        => ['label' => 'Apple Pay',           'source_of_funds' => 'applepay'],
        'qris'            => ['label' => 'QRIS',                'source_of_funds' => 'qris'],
        'dana'            => ['label' => 'DANA',                'source_of_funds' => 'dana'],
        'ovo'             => ['label' => 'OVO',                 'source_of_funds' => 'ovo'],
        'shopeepay'       => ['label' => 'ShopeePay',           'source_of_funds' => 'shopeepay'],
        'linkaja'         => ['label' => 'LinkAja',             'source_of_funds' => 'linkaja'],
        'finpaymoney'     => ['label' => 'Finpay Money',        'source_of_funds' => 'finpaymoney'],
        'jeniuspay'       => ['label' => 'Jenius Pay',          'source_of_funds' => 'jeniuspay'],
        'virgo'           => ['label' => 'Virgo',               'source_of_funds' => 'virgo'],
        'vabca'           => ['label' => 'Virtual Account BCA', 'source_of_funds' => 'vabca'],
        'vabni'           => ['label' => 'Virtual Account BNI', 'source_of_funds' => 'vabni'],
        'vabri'           => ['label' => 'Virtual Account BRI', 'source_of_funds' => 'vabri'],
        'vamandiri'       => ['label' => 'Virtual Account Mandiri', 'source_of_funds' => 'vamandiri'],
        'vapermata'       => ['label' => 'Virtual Account Permata', 'source_of_funds' => 'vapermata'],
        'bcaklikpay'      => ['label' => 'BCA KlikPay',         'source_of_funds' => 'bcaklikpay'],
        'octoclicks'      => ['label' => 'OCTO Clicks',         'source_of_funds' => 'octoclicks'],
        'permatanet'      => ['label' => 'PermataNet',          'source_of_funds' => 'permatanet'],
        'debitatmbersama' => ['label' => 'Debit ATM Bersama',   'source_of_funds' => 'debitatmbersama'],
        'finpaycode'      => ['label' => 'Finpay Code',         'source_of_funds' => 'finpaycode'],
        'indodana'        => ['label' => 'Indodana Paylater',   'source_of_funds' => 'indodana'],
    ];

    public function code(): string
    {
        return 'finpay';
    }

    public function type(): GatewayType
    {
        return GatewayType::FIAT;
    }

    /**
     * @return array<int, array{key: string, label: string, source_of_funds: string}>
     */
    public static function availablePaymentMethods(): array
    {
        $methods = [];
        foreach (self::PAYMENT_METHODS as $key => $meta) {
            $methods[] = [
                'key'             => $key,
                'label'           => $meta['label'],
                'source_of_funds' => $meta['source_of_funds'],
            ];
        }

        return $methods;
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

        // Generate a cryptographically random, single-use callback key.
        // Only the SHA-256 hash is stored; the raw token travels in the callback URL.
        $callbackKey = bin2hex(random_bytes(32));

        $this->storeUserCallbackUrl(
            $request->orderId(),
            $request->callbackUrl(),
            hash('sha256', $callbackKey),
            $request->amount(),
            $request->currency(),
        );

        $body = [
            'customer' => $this->resolveCustomerPayload($request),
            'order' => $this->resolveOrderPayload($request),
            'url' => [
                'callbackUrl' => $this->resolveGatewayCallbackUrl($callbackKey),
            ],
        ];

        // Only include sourceOfFunds when the caller explicitly asked for a
        // channel. An absent value preserves pre-update behaviour: Finpay's
        // payment-page picker decides for the customer.
        $sourceOfFunds = $this->resolveSourceOfFundsPayload($request);
        if ($sourceOfFunds !== []) {
            $body['sourceOfFunds'] = $sourceOfFunds;
        }

        $responsePayload = $this->sendInitiateRequest($body);
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

    public function gatewayHttpOptions(): array
    {
        return GatewayHttpOptions::fromConfig(
            (array) Config::get('finpay.http', [])
        );
    }

    /**
     * Resolves the finpay_transactions row by matching the SHA-256 hash of the
     * provided raw callback key. Returns null when no matching row exists.
     */
    public function resolveTransactionByCallbackKey(string $callbackKey): ?object
    {
        $row = DB::table(self::TRANSACTIONS_TABLE)
            ->where('callback_key_hash', hash('sha256', $callbackKey))
            ->first();

        return ($row instanceof \stdClass) ? $row : null;
    }

    /**
     * Marks the callback key for the given order as consumed so replay attempts
     * are rejected. Should be called only after a successful callback has been
     * acknowledged and the forward job dispatched.
     */
    public function markCallbackKeyConsumed(string $orderId): void
    {
        DB::table(self::TRANSACTIONS_TABLE)
            ->where('order_id', $orderId)
            ->update([
                'callback_key_consumed' => true,
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * Verifies the Finpay HMAC-SHA512 signature on a callback payload.
     *
     * $fields must be the full decoded callback object with the `signature` key
     * already removed. The computed HMAC is compared case-insensitively so both
     * upper- and lower-hex representations are accepted.
     *
     * @param array<string, mixed> $fields  Payload WITHOUT the `signature` key.
     * @param string               $signature  Signature value received in the payload.
     * @param string               $merchantKey  Finpay merchant secret key.
     */
    public function verifyFinpaySignature(array $fields, string $signature, string $merchantKey): bool
    {
        if ($signature === '' || $merchantKey === '') {
            return false;
        }

        $json = json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return false;
        }

        $computed = hash_hmac('sha512', $json, $merchantKey);

        return hash_equals(strtolower($computed), strtolower($signature));
    }

    /**
     * Calls the Finpay Check Status API as a signature-verification fallback.
     *
     * Returns the trusted `data` array (with `signature` removed) when all checks
     * pass: successful HTTP call, valid responseCode, HMAC-verified data, and
     * order.id / amount / currency match the stored transaction expectations.
     *
     * Returns null on any failure — the controller must treat this as a
     * verification failure and reject the callback.
     *
     * @return array<string, mixed>|null
     */
    public function checkStatusFallback(
        string $orderId,
        string $expectedAmount,
        string $expectedCurrency,
        string $merchantKey,
    ): ?array {
        $statusCheckBaseUrl = rtrim((string) Config::get('finpay.base_url', ''), '/');
        if ($statusCheckBaseUrl === '') {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['Authorization' => $this->buildAuthorizationHeader()])
                ->withOptions($this->gatewayHttpOptions())
                ->get($statusCheckBaseUrl . self::CHECK_CARD_PATH . rawurlencode($orderId));
        } catch (Throwable) {
            return null;
        }

        $decoded = $response->json();
        if (!is_array($decoded)) {
            return null;
        }

        if (($decoded['responseCode'] ?? null) !== '2000000') {
            return null;
        }

        if (!isset($decoded['data']) || !is_array($decoded['data'])) {
            return null;
        }

        $data = $decoded['data'];

        // Verify HMAC on data payload (signature removed before hashing).
        $dataSignature = is_string($data['signature'] ?? null) ? (string) $data['signature'] : '';
        unset($data['signature']);

        if (!$this->verifyFinpaySignature($data, $dataSignature, $merchantKey)) {
            return null;
        }

        // Verify order.id matches the transaction row.
        $dataOrderId = isset($data['order']['id']) && is_string($data['order']['id'])
            ? $data['order']['id']
            : null;
        if ($dataOrderId !== $orderId) {
            return null;
        }

        // Verify amount (decimal-safe).
        $dataAmount = isset($data['order']['amount']) && is_numeric($data['order']['amount'])
            ? (string) $data['order']['amount']
            : null;
        if ($dataAmount === null || !is_numeric($expectedAmount) || bccomp($dataAmount, $expectedAmount, 8) !== 0) {
            return null;
        }

        // Verify currency (case-insensitive).
        $dataCurrency = isset($data['order']['currency']) && is_string($data['order']['currency'])
            ? strtoupper($data['order']['currency'])
            : null;
        if ($dataCurrency === null || strcasecmp($dataCurrency, strtoupper($expectedCurrency)) !== 0) {
            return null;
        }

        return $data;
    }

    /**
     * Normalises the Finpay callback body into a canonical payload.
     *
     * Amount in order is cast to string (Finpay may send it as an integer).
     * The `signature` key is never included in the output.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function normalizeCallbackPayload(array $payload): array
    {
        $rawAmount = $payload['order']['amount'] ?? null;

        return [
            'merchant' => [
                'id' => $this->extractString($payload, ['merchant.id']),
            ],
            'customer' => [
                'id' => $this->extractString($payload, ['customer.id']),
            ],
            'order' => [
                'id'        => $this->extractString($payload, ['order.id']),
                'reference' => $this->extractString($payload, ['order.reference']),
                'amount'    => is_numeric($rawAmount) ? (string) $rawAmount : $this->extractString($payload, ['order.amount']),
                'currency'  => isset($payload['order']['currency']) && is_string($payload['order']['currency'])
                    ? strtoupper($payload['order']['currency'])
                    : null,
            ],
            'card' => [
                'mask' => $this->extractString($payload, ['card.mask']),
                'info' => [
                    'brand'   => $this->extractString($payload, ['card.info.brand']),
                    'issuing' => $this->extractString($payload, ['card.info.issuing']),
                    'type'    => $this->extractString($payload, ['card.info.type']),
                    'subType' => $this->extractString($payload, ['card.info.subType']),
                    'country' => $this->extractString($payload, ['card.info.country']),
                ],
            ],
            'meta' => [
                'data' => $this->extractString($payload, ['meta.data']),
            ],
            'result' => [
                'payment' => [
                    'status'     => $this->extractString($payload, ['result.payment.status']),
                    'statusDesc' => $this->extractString($payload, ['result.payment.statusDesc']),
                    'datetime'   => $this->extractString($payload, ['result.payment.datetime']),
                ],
                'flagging' => [
                    'paymentCode'     => $this->extractString($payload, ['result.flagging.paymentCode']),
                    'productCode'     => $this->extractString($payload, ['result.flagging.productCode']),
                    'bill'            => $this->extractString($payload, ['result.flagging.bill']),
                    'reffNo'          => $this->extractString($payload, ['result.flagging.reffNo']),
                    'amount'          => $this->extractString($payload, ['result.flagging.amount']),
                    'customerName'    => $this->extractString($payload, ['result.flagging.customerName']),
                    'billingInfo1'    => $this->extractString($payload, ['result.flagging.billingInfo1']),
                    'billingInfo2'    => $this->extractString($payload, ['result.flagging.billingInfo2']),
                    'billingInfo3'    => $this->extractString($payload, ['result.flagging.billingInfo3']),
                    'billingInfo4'    => $this->extractString($payload, ['result.flagging.billingInfo4']),
                    'billingInfo5'    => $this->extractString($payload, ['result.flagging.billingInfo5']),
                    'billingInfo6'    => $this->extractString($payload, ['result.flagging.billingInfo6']),
                    'billingInfo7'    => $this->extractString($payload, ['result.flagging.billingInfo7']),
                    'billingInfo8'    => $this->extractString($payload, ['result.flagging.billingInfo8']),
                    'billingInfo9'    => $this->extractString($payload, ['result.flagging.billingInfo9']),
                    'billingInfo10'   => $this->extractString($payload, ['result.flagging.billingInfo10']),
                    'ntpn'            => $this->extractString($payload, ['result.flagging.ntpn']),
                    'ntb'             => $this->extractString($payload, ['result.flagging.ntb']),
                    'paymentDateTime' => $this->extractString($payload, ['result.flagging.paymentDateTime']),
                    'gmt'             => $this->extractString($payload, ['result.flagging.gmt']),
                    'settlementDate'  => $this->extractString($payload, ['result.flagging.settlementDate']),
                ],
            ],
            'sourceOfFunds' => [
                'type'        => $this->extractString($payload, ['sourceOfFunds.type']),
                'channel'     => $this->extractString($payload, ['sourceOfFunds.channel']),
                'paymentCode' => $this->extractString($payload, ['sourceOfFunds.paymentCode']),
            ],
        ];
    }

    /**
     * Builds a PaymentOutcomeData from the normalised Finpay callback payload.
     *
     * Provider-specific fields (merchant, customer, card, meta, result,
     * sourceOfFunds) are placed in `raw`; `signature` is never included.
     *
     * @param array<string, mixed> $normalizedPayload  Output of normalizeCallbackPayload().
     */
    public function buildPaymentOutcome(string $orderId, array $normalizedPayload): PaymentOutcomeData
    {
        $paymentStatus = $this->normalizeNullableString(
            $normalizedPayload['result']['payment']['status'] ?? null
        );
        $internalStatus = $this->resolveCallbackStatus($paymentStatus);

        $amount   = (string) ($normalizedPayload['order']['amount'] ?? '');
        $currency = strtoupper((string) ($normalizedPayload['order']['currency'] ?? ''));

        $errorMessage = in_array($internalStatus, [PaymentStatus::FAILED, PaymentStatus::UNKNOWN], true)
            ? $this->normalizeNullableString($normalizedPayload['result']['payment']['statusDesc'] ?? null)
            : null;

        $raw = array_filter([
            'merchant'      => $normalizedPayload['merchant'] ?? null,
            'customer'      => $normalizedPayload['customer'] ?? null,
            'card'          => $normalizedPayload['card'] ?? null,
            'meta'          => $normalizedPayload['meta'] ?? null,
            'result'        => $normalizedPayload['result'] ?? null,
            'sourceOfFunds' => $normalizedPayload['sourceOfFunds'] ?? null,
        ], static fn(mixed $v): bool => $v !== null);

        return new PaymentOutcomeData(
            orderId: $orderId,
            status: $internalStatus->value,
            currency: $currency,
            amount: $amount,
            errorMessage: $errorMessage,
            gatewayCode: $this->code(),
            occurredAt: new DateTimeImmutable(),
            raw: $raw !== [] ? $raw : null,
        );
    }

    /**
     * Persists audit data for a callback attempt together with the validation
     * outcome. Called for every request that reaches the validation stage.
     *
     * @param array<string, mixed> $normalizedPayload
     * @param array<string, mixed> $headers
     */
    public function storeCallbackAudit(
        string $orderId,
        array $normalizedPayload,
        string $requestUrl,
        string $clientIp,
        array $headers,
        string $rawBody,
        bool $validationPassed,
        ?string $validationReason,
        bool $jobDispatched = false,
    ): void {
        $paymentStatus = $this->normalizeNullableString(
            $normalizedPayload['result']['payment']['status'] ?? null
        );

        $now = Carbon::now();
        DB::table(self::TRANSACTIONS_TABLE)->upsert([
            [
                'order_id' => $orderId,
                'status' => $this->resolveCallbackStatus($paymentStatus)->value,
                'callback_payload' => $this->encodeJsonPayload($normalizedPayload),
                'callback_request_url' => $requestUrl,
                'callback_client_ip' => $clientIp,
                'callback_headers' => $this->encodeJsonPayload($headers),
                'callback_raw_body' => $rawBody,
                'callback_validation_passed' => $validationPassed,
                'callback_validation_reason' => $validationReason,
                'forward_job_dispatched' => $jobDispatched,
                'forward_job_dispatched_at' => $jobDispatched ? $now : null,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        ], ['order_id'], [
            'status',
            'callback_payload',
            'callback_request_url',
            'callback_client_ip',
            'callback_headers',
            'callback_raw_body',
            'callback_validation_passed',
            'callback_validation_reason',
            'forward_job_dispatched',
            'forward_job_dispatched_at',
            'updated_at',
        ]);
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
                'callback_payload' => $this->encodeJsonPayload($payload),
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
            ])
            ->withOptions($this->gatewayHttpOptions())
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

    private function resolveGatewayCallbackUrl(string $callbackKey): string
    {
        return URL::route('finpay.callback', ['callbackKey' => $callbackKey]);
    }

    private function storeUserCallbackUrl(
        string $orderId,
        string $userCallbackUrl,
        string $callbackKeyHash,
        string $expectedAmount,
        string $expectedCurrency,
    ): void {
        $now = Carbon::now();
        DB::table(self::TRANSACTIONS_TABLE)->upsert([
            [
                'order_id' => $orderId,
                'callback_url' => $userCallbackUrl,
                'callback_key_hash' => $callbackKeyHash,
                'callback_key_consumed' => false,
                'expected_amount' => $expectedAmount,
                'expected_currency' => strtoupper($expectedCurrency),
                'updated_at' => $now,
                'created_at' => $now,
            ],
        ], ['order_id'], [
            'callback_url',
            'callback_key_hash',
            'callback_key_consumed',
            'expected_amount',
            'expected_currency',
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
                'status' => PaymentStatus::PENDING->value,
                'response_code' => $this->extractString($responsePayload, ['responseCode']),
                'response_message' => $this->extractString($responsePayload, ['responseMessage']),
                'transaction_id' => $this->extractString($responsePayload, ['transactionId', 'trxId', 'transaction.id']),
                'gateway_reference' => $this->extractString($responsePayload, ['gatewayReference', 'referenceNo', 'invoiceNo']),
                'provider_payload' => $this->encodeJsonPayload($responsePayload),
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

    /**
     * Maps a raw Finpay callback payment status to the internal PaymentStatus enum.
     */
    private function resolveCallbackStatus(?string $finpayStatus): PaymentStatus
    {
        return match ($finpayStatus !== null ? strtoupper($finpayStatus) : '') {
            'PAID'                => PaymentStatus::SUCCESS,
            'PENDING', 'PROCESSING' => PaymentStatus::PENDING,
            'FAILED', 'FAILED_TRANSACTION' => PaymentStatus::FAILED,
            'CANCELED', 'CANCELLED' => PaymentStatus::CANCELED,
            'EXPIRED'             => PaymentStatus::EXPIRED,
            default               => PaymentStatus::UNKNOWN,
        };
    }

    /**
     * @param array<string, mixed> $response
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
     * Build the `sourceOfFunds` block for the initiate request.
     *
     * The caller selects the payment method by passing one of:
     *   - `meta.payment_method`      (preferred — same key as Aviagram for parity)
     *   - `meta.sourceOfFunds.type`  (full passthrough — wins over payment_method)
     *
     * When neither is provided this method returns `[]`, signalling to the
     * caller in `initiate()` to omit `sourceOfFunds` from the request body
     * entirely. That preserves the pre-update behaviour where Finpay's hosted
     * payment page lets the customer pick the channel.
     *
     * Unknown payment_method keys also fall through to "no channel selected"
     * rather than guessing, so callers never silently send the wrong sourceOfFunds.
     * Any extra keys provided under meta.sourceOfFunds (e.g. paymentCode for VAs)
     * are merged on top of the resolved type.
     *
     * @return array<string, mixed>
     */
    protected function resolveSourceOfFundsPayload(PaymentRequestData $request): array
    {
        $overrides = $request->meta()->get('sourceOfFunds', []);
        if (!is_array($overrides)) {
            $overrides = [];
        }

        if (isset($overrides['type']) && is_string($overrides['type']) && trim($overrides['type']) !== '') {
            return $overrides;
        }

        $paymentMethod = $request->meta()->get('payment_method');
        if (!is_string($paymentMethod) || trim($paymentMethod) === '') {
            return $overrides;
        }

        $methodKey = strtolower(trim($paymentMethod));
        if (!isset(self::PAYMENT_METHODS[$methodKey])) {
            return $overrides;
        }

        return array_replace(['type' => self::PAYMENT_METHODS[$methodKey]['source_of_funds']], $overrides);
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
     * @param array<string, mixed> $payload
     */
    private function encodeJsonPayload(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '{}';
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
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
