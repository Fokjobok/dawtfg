<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // Process health is deliberately independent of database availability.
        return response()->json(['status' => 'ok']);
    }
}
