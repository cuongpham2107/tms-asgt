<?php

namespace App\Exceptions;

use Exception;
use Filament\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InvalidTransitionException extends Exception
{
    public function render(Request $request): Response|JsonResponse
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => $this->getMessage(),
            ], 422);
        }

        Notification::make()
            ->danger()
            ->title('Chuyển trạng thái không hợp lệ')
            ->body($this->getMessage())
            ->send();

        return back();
    }
}
