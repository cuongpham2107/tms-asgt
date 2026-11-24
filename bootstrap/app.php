<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
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
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('livewire-*/update') || $request->is('livewire/update')) {
                return response()->json(['message' => 'Livewire snapshot is stale.'], 419);
            }
        });
    })->create();
