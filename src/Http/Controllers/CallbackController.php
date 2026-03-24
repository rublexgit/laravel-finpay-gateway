<?php

namespace Finpay\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CallbackController
{
    public function handle(Request $request): JsonResponse
    {
        return response()->json([], 501);
    }
}
