<?php

namespace Finpay\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Finpay\Services\FinpayGatewayService;
use Throwable;

class CallbackController
{
    private const FORWARD_TIMEOUT_SECONDS = 30;

    public function handle(Request $request, FinpayGatewayService $finpayGatewayService): JsonResponse
    {
        $payload = $request->all();
        $orderId = $this->extractOrderId($payload);
        if ($orderId === null) {
            return response()->json([
                'responseCode' => '4000001',
                'responseMessage' => 'Order ID not found in callback payload.',
            ], 400);
        }

        $callbackUrl = $finpayGatewayService->getUserCallbackUrlForOrder($orderId);
        if ($callbackUrl === null) {
            return response()->json([
                'responseCode' => '4040001',
                'responseMessage' => 'User callback URL not found for order ID.',
                'orderId' => $orderId,
            ], 404);
        }

        try {
            $forwardResponse = Http::acceptJson()
                ->asJson()
                ->timeout(self::FORWARD_TIMEOUT_SECONDS)
                ->post($callbackUrl, $payload);
        } catch (Throwable $exception) {
            return response()->json([
                'responseCode' => '5000001',
                'responseMessage' => 'Callback forwarding failed.',
                'orderId' => $orderId,
                'forwarded' => false,
                'error' => $exception->getMessage(),
            ], 500);
        }

        if ($forwardResponse->successful()) {
            $finpayGatewayService->forgetUserCallbackUrlForOrder($orderId);
        }

        return response()->json([
            'responseCode' => '2000000',
            'responseMessage' => 'Callback processed.',
            'orderId' => $orderId,
            'forwarded' => $forwardResponse->successful(),
            'forwardStatus' => $forwardResponse->status(),
        ]);
    }

    private function extractOrderId(array $payload): ?string
    {
        if (isset($payload['order']['id']) && is_string($payload['order']['id'])) {
            return $payload['order']['id'];
        }

        if (isset($payload['orderId']) && is_string($payload['orderId'])) {
            return $payload['orderId'];
        }

        if (isset($payload['invoiceNo']) && is_string($payload['invoiceNo'])) {
            return $payload['invoiceNo'];
        }

        return null;
    }
}
