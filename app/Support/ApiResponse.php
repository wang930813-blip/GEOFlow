<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function success(array $data, string $requestId, int $status = 200, array $meta = []): JsonResponse
    {
        $metadata = [
            'request_id' => $requestId,
            'timestamp' => now()->toIso8601String(),
            ...$meta,
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
            'error' => null,
            'meta' => $metadata,
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function error(
        string $code,
        string $message,
        string $requestId,
        int $status = 400,
        array $details = []
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json([
            'success' => false,
            'data' => null,
            'error' => $error,
            'meta' => [
                'request_id' => $requestId,
                'timestamp' => now()->toIso8601String(),
            ],
        ], $status);
    }
}
