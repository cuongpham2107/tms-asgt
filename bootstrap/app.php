<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->redirectGuestsTo(fn () => route('filament.app.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Xác định vì sao Livewire từ chối request update, dựa trên chính các điều kiện abort(404)
        // trong Livewire: thiếu header/nội dung JSON, thiếu components, hoặc snapshot cũ thiếu memo route context
        // (Livewire >= 4.4.9 thêm memo.path/method/origin/route nên snapshot của tab mở trước khi deploy bị loại).
        $livewireRejectionReason = static function (Request $request): string {
            if (! $request->hasHeader('X-Livewire') || ! $request->isJson()) {
                return 'missing_livewire_headers';
            }

            $components = $request->input('components');

            if (! is_array($components) || $components === []) {
                return 'empty_components';
            }

            $snapshot = json_decode((string) ($components[0]['snapshot'] ?? ''), true);
            $memo = is_array($snapshot) ? ($snapshot['memo'] ?? null) : null;

            if (! is_array($memo) || ! array_key_exists('route', $memo)) {
                return 'stale_snapshot_before_livewire_4_4_9';
            }

            return 'invalid_payload_structure';
        };

        $exceptions->shouldRenderJsonWhen(function (Request $request) {
            return $request->is('api/*') || $request->expectsJson();
        });

        // Link theo dõi sai/hết hạn: trả trang thông báo thân thiện thay vì lỗi mặc định.
        $exceptions->render(function (InvalidSignatureException $e, Request $request) {
            if (($request->is('theo-doi/*') || $request->is('vi-tri-xe/*')) && ! $request->expectsJson()) {
                return response()->view('public.trip-tracking-expired', [], 403);
            }
        });

        // Endpoint Livewire trả 404 khi tab cũ (mở từ trước khi deploy) gửi snapshot không còn hợp lệ.
        // Đổi thành 419 để Livewire/Filament không bắn toast "404 Not Found"; tab cũ sẽ tự reload.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($livewireRejectionReason) {
            if (! $request->is('livewire-*/update') && ! $request->is('livewire/update')) {
                return null;
            }

            Log::warning('Livewire update rejected', [
                'reason' => $livewireRejectionReason($request),
                'has_livewire_header' => $request->hasHeader('X-Livewire'),
                'is_json' => $request->isJson(),
                'body_bytes' => strlen($request->getContent()),
                'user_id' => $request->user()?->getAuthIdentifier(),
                'referer' => $request->header('Referer'),
                'user_agent' => str($request->userAgent() ?? '')->limit(120)->toString(),
            ]);

            return response()->json(['message' => 'Livewire snapshot is stale.'], 419);
        });
    })->create();
