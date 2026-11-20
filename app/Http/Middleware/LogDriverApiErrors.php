<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ghi Nhật ký hoạt động (log `driver_api`) khi App tài xế gọi API bị lỗi (HTTP >= 400), và mọi lần gọi API ca
 * (`driver/shifts/*`) kể cả thành công kèm trạng thái chuyến server trả về — để tra lại khi tài xế báo
 * "không kết thúc được ca": App có gọi lên không, server trả gì, chặn ở đâu.
 */
class LogDriverApiErrors
{
    public const LOG_NAME = 'driver_api';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() >= 400 || $request->is('api/driver/shifts*')) {
            $this->log($request, $response);
        }

        return $response;
    }

    private function log(Request $request, Response $response): void
    {
        $data = $response instanceof JsonResponse ? $response->getData(true) : [];
        $message = $data['message'] ?? null;
        $errors = Arr::flatten((array) ($data['errors'] ?? []));

        $isError = $response->getStatusCode() >= 400;
        $trips = collect($data['shift']['trips'] ?? [])
            ->map(fn ($trip): string => ($trip['trip_code'] ?? '#'.($trip['id'] ?? '?')).': '.($trip['status'] ?? '?'))
            ->values()
            ->all();

        activity(self::LOG_NAME)
            ->causedBy($request->user())
            ->event($isError ? 'api_error' : 'api_call')
            ->withProperties([
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                'message' => Str::limit(is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE), 500),
                'errors' => array_slice($errors, 0, 5),
                'shift_trips' => $trips,
                'input' => Arr::except($request->except(['password', 'photos', 'photo', 'voice_note']), ['token']),
                'user_agent' => Str::limit((string) $request->userAgent(), 120),
            ])
            ->log(sprintf('%s %s → %d', $request->method(), $request->path(), $response->getStatusCode()));
    }
}
