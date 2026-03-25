<?php

declare(strict_types=1);

namespace Finpay\Tests;

use Finpay\Data\CustomerData;
use Finpay\Data\OrderData;
use Finpay\Services\FinpayGatewayService;
use PHPUnit\Framework\TestCase;
use Rublex\CoreGateway\Data\DynamicDataBag;
use Rublex\CoreGateway\Data\PaymentInitResultData;
use Rublex\CoreGateway\Data\PaymentRequestData;
use Rublex\CoreGateway\Enums\PaymentStatus;
use Rublex\CoreGateway\Exceptions\ValidationException;

final class FinpayGatewayServiceTest extends TestCase
{
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
}
