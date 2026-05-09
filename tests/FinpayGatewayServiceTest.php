<?php

declare(strict_types=1);

namespace Finpay\Tests;

use Finpay\Data\CustomerData;
use Finpay\Data\OrderData;
use Finpay\Services\FinpayGatewayService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Rublex\CoreGateway\Data\DynamicDataBag;
use Rublex\CoreGateway\Data\PaymentInitResultData;
use Rublex\CoreGateway\Data\PaymentRequestData;
use Rublex\CoreGateway\Enums\PaymentStatus;
use Rublex\CoreGateway\Exceptions\ValidationException;

final class FinpayGatewayServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([]));
        Facade::setFacadeApplication($app);
    }

    public function test_order_payload_uses_request_fields_and_allows_meta_overrides(): void
    {
        $service = new FinpayGatewayService();
        $resolveOrderPayload = new \ReflectionMethod(FinpayGatewayService::class, 'resolveOrderPayload');
        $resolveOrderPayload->setAccessible(true);

        $payload = $resolveOrderPayload->invoke($service, new PaymentRequestData(
            gatewayCode: 'finpay',
            orderId: 'INV-555',
            amount: '15',
            currency: 'EUR',
            callbackUrl: 'https://merchant.example/callback',
            meta: new DynamicDataBag([
                'order' => [
                    'description' => 'Testing',
                ],
            ])
        ));

        self::assertIsArray($payload);
        self::assertSame([
            'id' => 'INV-555',
            'amount' => '15',
            'currency' => 'EUR',
            'description' => 'Testing',
        ], $payload);
    }

    public function test_backward_compatible_wrapper_maps_to_contract_request(): void
    {
        $service = new class extends FinpayGatewayService {
            public ?PaymentRequestData $capturedRequest = null;

            public function initiate(PaymentRequestData $request): PaymentInitResultData
            {
                $this->capturedRequest = $request;

                return new PaymentInitResultData(
                    status: PaymentStatus::PENDING,
                    transactionId: 'TRX-FP-1001',
                    redirectUrl: 'https://finpay.example/redirect/INV-1001',
                    gatewayReference: 'REF-FP-1001',
                    meta: new DynamicDataBag([
                        'responseCode' => '2000000',
                        'responseMessage' => 'OK',
                    ]),
                    raw: new DynamicDataBag([
                        'providerField' => 'providerValue',
                    ])
                );
            }
        };

        $response = $service->initiatePayment(
            new CustomerData('user@example.test', 'Test', 'User', '+620001111'),
            new OrderData('INV-1001', '10.00', 'EUR', 'Order payment'),
            'https://merchant.example/callback'
        );

        self::assertSame([
            'status' => 'pending',
            'responseCode' => '2000000',
            'responseMessage' => 'OK',
            'orderId' => 'INV-1001',
            'transactionId' => 'TRX-FP-1001',
            'redirect_url' => 'https://finpay.example/redirect/INV-1001',
            'gatewayReference' => 'REF-FP-1001',
            'raw' => [
                'providerField' => 'providerValue',
            ],
        ], $response);
        self::assertInstanceOf(PaymentRequestData::class, $service->capturedRequest);
        self::assertSame('finpay', $service->capturedRequest?->gatewayCode());
        self::assertSame('user@example.test', $service->capturedRequest?->meta()->requireString('customer.email'));
        self::assertSame('INV-1001', $service->capturedRequest?->meta()->requireString('order.id'));
    }

    public function test_init_response_mapping_preserves_raw_and_extracts_fields(): void
    {
        $service = new class extends FinpayGatewayService {
            public function exposeMap(array $response): PaymentInitResultData
            {
                return $this->mapInitResponseToResult($response);
            }
        };

        /** @var mixed $service */
        $result = $service->exposeMap([
            'responseCode' => '2000000',
            'responseMessage' => 'Initiated',
            'transactionId' => 'TRX-1',
            'redirecturl' => 'https://pay.example/redirect',
        ]);

        self::assertSame(PaymentStatus::PENDING, $result->status());
        self::assertSame('TRX-1', $result->transactionId());
        self::assertSame('https://pay.example/redirect', $result->redirectUrl());
        self::assertSame('2000000', $result->raw()->requireString('responseCode'));
    }

    public function test_wrapper_throws_validation_exception_on_invalid_callback_url(): void
    {
        $service = new FinpayGatewayService();

        $this->expectException(ValidationException::class);

        $service->initiatePayment(
            new CustomerData('user@example.test', 'Test', 'User', '+620001111'),
            new OrderData('INV-1002', '10.00', 'EUR', 'Order payment'),
            'invalid-callback-url'
        );
    }

    public function test_gateway_http_options_returns_normalized_options_from_config(): void
    {
        Config::set('finpay.http', [
            'timeout' => 60,
            'connect_timeout' => 5,
            'proxy' => 'http://proxy.example.com:8080',
            'verify' => false,
        ]);

        $options = (new FinpayGatewayService())->gatewayHttpOptions();

        self::assertSame(60.0, $options['timeout']);
        self::assertSame(5.0, $options['connect_timeout']);
        self::assertSame('http://proxy.example.com:8080', $options['proxy']);
        self::assertFalse($options['verify']);
    }

    public function test_gateway_http_options_supports_protocol_array_proxy(): void
    {
        Config::set('finpay.http.proxy', [
            'http' => 'http://proxy.example.com:3128',
            'https' => 'http://proxy.example.com:3129',
            'no' => ['localhost'],
        ]);

        $options = (new FinpayGatewayService())->gatewayHttpOptions();

        self::assertSame([
            'http' => 'http://proxy.example.com:3128',
            'https' => 'http://proxy.example.com:3129',
            'no' => ['localhost'],
        ], $options['proxy']);
    }

    public function test_gateway_http_options_omits_null_proxy(): void
    {
        Config::set('finpay.http', [
            'timeout' => 30,
            'connect_timeout' => 10,
            'proxy' => null,
            'verify' => true,
        ]);

        $options = (new FinpayGatewayService())->gatewayHttpOptions();

        self::assertArrayNotHasKey('proxy', $options);
        self::assertSame(30.0, $options['timeout']);
    }

    // -------------------------------------------------------------------------
    // Signature verification
    // -------------------------------------------------------------------------

    public function test_verify_finpay_signature_accepts_valid_hmac(): void
    {
        $service     = new FinpayGatewayService();
        $merchantKey = 'test-merchant-secret';

        $fields = [
            'merchant' => ['id' => 'MID001'],
            'order'    => ['id' => 'ORD-123', 'reference' => 'REF-123', 'amount' => '150000', 'currency' => 'IDR'],
            'result'   => ['payment' => ['status' => 'PAID', 'statusDesc' => 'Success', 'datetime' => '2026-01-01T00:00:00Z']],
        ];

        $signature = hash_hmac(
            'sha512',
            json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $merchantKey
        );

        self::assertTrue($service->verifyFinpaySignature($fields, $signature, $merchantKey));
    }

    public function test_verify_finpay_signature_rejects_wrong_signature(): void
    {
        $service = new FinpayGatewayService();

        $fields = ['order' => ['id' => 'ORD-999', 'amount' => '50000', 'currency' => 'IDR']];

        self::assertFalse($service->verifyFinpaySignature($fields, 'invalidsignature', 'some-key'));
    }

    public function test_verify_finpay_signature_is_case_insensitive(): void
    {
        $service     = new FinpayGatewayService();
        $merchantKey = 'my-secret';

        $fields = ['merchant' => ['id' => 'M01'], 'order' => ['id' => 'O01', 'amount' => '10000', 'currency' => 'IDR']];

        $signature = hash_hmac(
            'sha512',
            json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $merchantKey
        );

        // Both uppercase and lowercase hex should be accepted.
        self::assertTrue($service->verifyFinpaySignature($fields, strtoupper($signature), $merchantKey));
        self::assertTrue($service->verifyFinpaySignature($fields, strtolower($signature), $merchantKey));
    }

    public function test_verify_finpay_signature_rejects_empty_signature(): void
    {
        $service = new FinpayGatewayService();
        $fields  = ['order' => ['id' => 'ORD-1']];

        self::assertFalse($service->verifyFinpaySignature($fields, '', 'some-key'));
    }

    public function test_verify_finpay_signature_rejects_empty_merchant_key(): void
    {
        $service = new FinpayGatewayService();
        $fields  = ['order' => ['id' => 'ORD-1']];

        self::assertFalse($service->verifyFinpaySignature($fields, 'abc123', ''));
    }

    // -------------------------------------------------------------------------
    // Payload normalisation
    // -------------------------------------------------------------------------

    public function test_normalize_callback_payload_maps_all_top_level_keys(): void
    {
        $service = new FinpayGatewayService();

        $raw = [
            'merchant'     => ['id' => 'MID001'],
            'customer'     => ['id' => 'CUS001'],
            'order'        => ['id' => 'ORD-001', 'reference' => 'REF-001', 'amount' => 150000, 'currency' => 'idr'],
            'card'         => [
                'mask' => '411111xxxxxx1111',
                'info' => ['brand' => 'VISA', 'issuing' => 'BCA', 'type' => 'CREDIT', 'subType' => 'GOLD', 'country' => 'ID'],
            ],
            'meta'         => ['data' => 'extra-meta'],
            'result'       => [
                'payment' => ['status' => 'PAID', 'statusDesc' => 'Success', 'datetime' => '2026-01-01T00:00:00Z'],
                'flagging' => [
                    'paymentCode' => 'PC01', 'productCode' => 'PROD01', 'bill' => 'BILL01',
                    'reffNo' => 'REFF01', 'amount' => '150000', 'customerName' => 'John Doe',
                    'billingInfo1' => 'B1', 'billingInfo2' => 'B2', 'billingInfo3' => 'B3',
                    'billingInfo4' => 'B4', 'billingInfo5' => 'B5', 'billingInfo6' => 'B6',
                    'billingInfo7' => 'B7', 'billingInfo8' => 'B8', 'billingInfo9' => 'B9',
                    'billingInfo10' => 'B10', 'ntpn' => 'NTPN01', 'ntb' => 'NTB01',
                    'paymentDateTime' => '2026-01-01T00:00:00Z', 'gmt' => '+07', 'settlementDate' => '2026-01-02',
                ],
            ],
            'sourceOfFunds' => ['type' => 'CARD', 'channel' => 'ONLINE', 'paymentCode' => 'SOF01'],
            'signature'    => 'should-not-appear',
        ];

        $normalized = $service->normalizeCallbackPayload($raw);

        // Top-level keys present; signature absent.
        self::assertArrayHasKey('merchant', $normalized);
        self::assertArrayHasKey('customer', $normalized);
        self::assertArrayHasKey('order', $normalized);
        self::assertArrayHasKey('card', $normalized);
        self::assertArrayHasKey('meta', $normalized);
        self::assertArrayHasKey('result', $normalized);
        self::assertArrayHasKey('sourceOfFunds', $normalized);
        self::assertArrayNotHasKey('signature', $normalized);

        // order.amount int → string; order.currency uppercased.
        self::assertSame('150000', $normalized['order']['amount']);
        self::assertSame('IDR', $normalized['order']['currency']);
        self::assertSame('ORD-001', $normalized['order']['id']);

        // Nested card.info fields.
        self::assertSame('VISA', $normalized['card']['info']['brand']);
        self::assertSame('ID', $normalized['card']['info']['country']);

        // result.payment and result.flagging fields.
        self::assertSame('PAID', $normalized['result']['payment']['status']);
        self::assertSame('B10', $normalized['result']['flagging']['billingInfo10']);
        self::assertSame('+07', $normalized['result']['flagging']['gmt']);

        // sourceOfFunds.
        self::assertSame('CARD', $normalized['sourceOfFunds']['type']);
        self::assertSame('SOF01', $normalized['sourceOfFunds']['paymentCode']);
    }

    public function test_normalize_callback_payload_uses_nulls_for_absent_fields(): void
    {
        $service    = new FinpayGatewayService();
        $normalized = $service->normalizeCallbackPayload([]);

        self::assertNull($normalized['merchant']['id']);
        self::assertNull($normalized['order']['id']);
        self::assertNull($normalized['order']['amount']);
        self::assertNull($normalized['order']['currency']);
        self::assertNull($normalized['card']['mask']);
        self::assertNull($normalized['result']['payment']['status']);
        self::assertNull($normalized['result']['flagging']['ntpn']);
        self::assertNull($normalized['sourceOfFunds']['type']);
    }

    public function test_normalize_callback_payload_handles_string_amount(): void
    {
        $service    = new FinpayGatewayService();
        $normalized = $service->normalizeCallbackPayload([
            'order' => ['id' => 'ORD-X', 'amount' => '75000.50', 'currency' => 'IDR'],
        ]);

        self::assertSame('75000.50', $normalized['order']['amount']);
    }
}
