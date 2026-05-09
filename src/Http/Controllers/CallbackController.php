<?php

declare(strict_types=1);

namespace Finpay\Http\Controllers;

use Finpay\Jobs\ForwardCallbackJob;
use Finpay\Services\FinpayGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;

/**
 * Handles inbound payment callbacks from Finpay.
 *
 * Route: POST api/v1/finpay/callback/{callbackKey}
 *
 * The {callbackKey} path segment is a cryptographically random, single-use token
 * generated at payment-init time and embedded in the callback URL registered with
 * Finpay. Its SHA-256 hash is stored on the transaction row; the raw token is
 * never logged or stored.
 *
 * Signature: HMAC-SHA512 over the full decoded payload WITHOUT the `signature`
 * key, using the merchant key. On failure, the Check Status API is called as a
 * fallback before rejecting the callback.
 *
 * Success response: HTTP 201
 * {
 *   "responseCode": "2010000",
 *   "responseMessage": "Callback accepted."
 * }
 */
class CallbackController
{
    /** @var list<string> Headers redacted from the stored audit record. */
    private const MASKED_HEADERS = ['authorization', 'x-api-key', 'x-auth-token', 'cookie'];

    public function handle(
        Request $request,
        string $callbackKey,
        FinpayGatewayService $finpayGatewayService,
    ): JsonResponse {
        // Capture audit data before touching the body stream or returning early.
        $rawBody  = $request->getContent();
        $fullUrl  = $request->fullUrl();
        $clientIp = (string) $request->ip();
        $headers  = $this->sanitizeHeaders($request->headers->all());

        // Resolve the transaction by hashing the incoming key and doing a DB lookup.
        $transaction = $finpayGatewayService->resolveTransactionByCallbackKey($callbackKey);
        if ($transaction === null) {
            return new JsonResponse([
                'responseCode'    => '4040002',
                'responseMessage' => 'Invalid callback key.',
            ], 404);
        }

        $orderId = (string) $transaction->order_id;

        // Reject replay attempts.
        if ((bool) $transaction->callback_key_consumed) {
            return new JsonResponse([
                'responseCode'    => '4030001',
                'responseMessage' => 'Callback key has already been used.',
            ], 403);
        }

        // Parse JSON body.
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $finpayGatewayService->storeCallbackAudit(
                $orderId, [], $fullUrl, $clientIp, $headers, $rawBody,
                false, 'Invalid JSON body.',
            );
            return new JsonResponse([
                'responseCode'    => '4000002',
                'responseMessage' => 'Invalid JSON body.',
            ], 400);
        }

        // Verify HMAC-SHA512 signature.
        $merchantKey = (string) Config::get('finpay.merchant_key', '');
        $signature   = is_string($payload['signature'] ?? null) ? (string) $payload['signature'] : '';
        $fields      = $payload;
        unset($fields['signature']);

        if (!$finpayGatewayService->verifyFinpaySignature($fields, $signature, $merchantKey)) {
            // Signature failed — try Check Status API as fallback.
            $trustedPayload = $finpayGatewayService->checkStatusFallback(
                orderId: $orderId,
                expectedAmount: (string) $transaction->expected_amount,
                expectedCurrency: (string) $transaction->expected_currency,
                merchantKey: $merchantKey,
            );

            if ($trustedPayload === null) {
                $finpayGatewayService->storeCallbackAudit(
                    $orderId, [], $fullUrl, $clientIp, $headers, $rawBody,
                    false, 'Signature verification failed.',
                );
                return new JsonResponse([
                    'responseCode'    => '4030002',
                    'responseMessage' => 'Signature verification failed.',
                ], 403);
            }

            // Use the trusted payload from the fallback for the rest of the flow.
            $payload = $trustedPayload;
        }

        $normalizedPayload = $finpayGatewayService->normalizeCallbackPayload($payload);

        // Validate order.id is present in the payload.
        $invoiceId = $normalizedPayload['order']['id'] ?? null;
        if (!is_string($invoiceId) || $invoiceId === '') {
            $finpayGatewayService->storeCallbackAudit(
                $orderId, $normalizedPayload, $fullUrl, $clientIp, $headers, $rawBody,
                false, 'Order ID not found in payload.',
            );
            return new JsonResponse([
                'responseCode'    => '4220001',
                'responseMessage' => 'Order ID not found in callback payload.',
            ], 422);
        }

        // Verify the payload order.id matches the transaction row's order_id.
        if ($invoiceId !== $orderId) {
            $finpayGatewayService->storeCallbackAudit(
                $orderId, $normalizedPayload, $fullUrl, $clientIp, $headers, $rawBody,
                false, sprintf('Order ID mismatch: received %s, expected %s.', $invoiceId, $orderId),
            );
            return new JsonResponse([
                'responseCode'    => '4220004',
                'responseMessage' => 'Order ID does not match the expected value.',
            ], 422);
        }

        // Validate amount (decimal-safe comparison via bccomp).
        $callbackAmount = $normalizedPayload['order']['amount'] ?? null;
        $expectedAmount = (string) $transaction->expected_amount;
        if (!$this->amountsMatch($callbackAmount, $expectedAmount)) {
            $finpayGatewayService->storeCallbackAudit(
                $orderId, $normalizedPayload, $fullUrl, $clientIp, $headers, $rawBody,
                false, sprintf(
                    'Amount mismatch: received %s, expected %s.',
                    is_string($callbackAmount) ? $callbackAmount : 'null',
                    $expectedAmount,
                ),
            );
            return new JsonResponse([
                'responseCode'    => '4220002',
                'responseMessage' => 'Amount does not match expected value.',
            ], 422);
        }

        // Validate currency (case-insensitive).
        $callbackCurrency = $normalizedPayload['order']['currency'] ?? null;
        $expectedCurrency = (string) $transaction->expected_currency;
        if (!$this->currenciesMatch($callbackCurrency, $expectedCurrency)) {
            $finpayGatewayService->storeCallbackAudit(
                $orderId, $normalizedPayload, $fullUrl, $clientIp, $headers, $rawBody,
                false, sprintf(
                    'Currency mismatch: received %s, expected %s.',
                    is_string($callbackCurrency) ? $callbackCurrency : 'null',
                    $expectedCurrency,
                ),
            );
            return new JsonResponse([
                'responseCode'    => '4220003',
                'responseMessage' => 'Currency does not match expected value.',
            ], 422);
        }

        // All checks passed.
        // Dispatch the forward job BEFORE storing audit / consuming the key so that
        // a queue-unavailable error lets Finpay retry with the same key.
        $callbackUrl = $finpayGatewayService->getUserCallbackUrlForOrder($orderId);
        if ($callbackUrl !== null) {
            Bus::dispatch(new ForwardCallbackJob(
                $orderId,
                $callbackUrl,
                $finpayGatewayService->buildPaymentOutcome($orderId, $normalizedPayload),
            ));
        }

        $finpayGatewayService->storeCallbackAudit(
            $orderId, $normalizedPayload, $fullUrl, $clientIp, $headers, $rawBody,
            true, null, jobDispatched: $callbackUrl !== null,
        );

        $finpayGatewayService->markCallbackKeyConsumed($orderId);

        return new JsonResponse([
            'responseCode'    => '2010000',
            'responseMessage' => 'Callback accepted.',
        ], 201);
    }

    /**
     * Redacts sensitive header values before they are persisted.
     *
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    private function sanitizeHeaders(array $headers): array
    {
        foreach (self::MASKED_HEADERS as $name) {
            if (array_key_exists($name, $headers)) {
                $headers[$name] = ['[REDACTED]'];
            }
        }
        return $headers;
    }

    /**
     * Decimal-safe amount comparison. Normalises "100", "100.0", and "100.00"
     * as equal by delegating to bccomp with eight decimal places of precision.
     */
    private function amountsMatch(mixed $received, string $expected): bool
    {
        if (!is_string($received) && !is_numeric($received)) {
            return false;
        }
        $received = (string) $received;
        if (!is_numeric($received) || !is_numeric($expected)) {
            return false;
        }
        return bccomp($received, $expected, 8) === 0;
    }

    /**
     * Case-insensitive currency comparison.
     */
    private function currenciesMatch(mixed $received, string $expected): bool
    {
        if (!is_string($received)) {
            return false;
        }
        return strcasecmp($received, $expected) === 0;
    }
}
