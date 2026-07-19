<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;

class ApiResponseService
{
    public static function success(
        mixed $data = null,
        string $message = 'Request completed successfully.',
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
        ], $status);
    }

    public static function error(
        string $message = 'Request failed.',
        mixed $errors = null,
        int $status = 400,
        mixed $data = null
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
        ], $status);
    }

    public static function validationError(
        string $message = 'Validation failed.',
        mixed $errors = null,
        int $status = 422
    ): JsonResponse {
        return self::error($message, $errors, $status);
    }
}
