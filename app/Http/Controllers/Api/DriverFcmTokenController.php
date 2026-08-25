<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverFcmTokenController extends Controller
{
    /**
     * Cập nhật FCM Push Token của thiết bị lái xe.
     *
     * App mobile gửi token FCM lên sau khi đăng nhập thành công để nhận thông báo đơn hàng mới.
     *
     * @response array{message: string, success: bool}
     */
    #[BodyParameter('fcm_token', type: 'string', description: 'Token FCM của thiết bị di động.', example: 'f87aBc...xyz')]
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fcm_token' => ['required', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $user->update([
            'fcm_token' => $validated['fcm_token'],
            'fcm_token_updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật FCM token thành công.',
        ]);
    }
}
