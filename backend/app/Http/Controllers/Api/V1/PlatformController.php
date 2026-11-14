<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformConfig;
use Illuminate\Http\JsonResponse;

class PlatformController extends Controller
{
    public function show(PlatformConfig $platform): JsonResponse
    {
        return response()->json(['data' => $platform->general() + ['modules' => $platform->modules()]]);
    }
}
