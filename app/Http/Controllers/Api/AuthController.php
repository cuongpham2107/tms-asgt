<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverShiftResource;
use App\Http\Resources\UserResource;
use App\Models\DriverShift;
use App\Models\User;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * Đăng nhập lái xe, trả về token Sanctum + ca đang active (nếu có).
     *
     * @response array{user: UserResource, token: string, shift: DriverShiftResource|null}
     */
    #[BodyParameter('email', type: 'string', example: 'cvt2307b@tms.local')]
    #[BodyParameter('password', type: 'string', example: '66668888')]
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (! Auth::attempt($data)) {
            /** @status 401 */
            return response()->json(['message' => 'Thông tin đăng nhập không hợp lệ'], 401);
        }

        /** @var User $user */
        $user = Auth::user();

        $token = $user->createToken('mobile')->plainTextToken;

        // Lấy ca đang active của lái xe (nếu có)
        $activeShift = DriverShift::query()
            ->where('driver_id', $user->id)
            ->where('start_time', '<=', now())
            ->whereNull('end_time')
            ->latest('start_time')
            ->first();

        return response()->json([
            'user' => UserResource::make($user),
            'token' => $token,
            'shift' => $activeShift
                ? DriverShiftResource::make($activeShift->load(['driver', 'trips' => fn ($q) => $q->where('status', '!=', 'cancelled')->with('vehicle')]))
                : null,
        ]);
    }

    /**
     * Đăng xuất (thu hồi token hiện tại).
     *
     * @response array{message: string}
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $user->currentAccessToken()?->delete();
        }

        return response()->json(['message' => 'Đã đăng xuất thành công']);
    }

    /**
     * Yêu cầu xóa tài khoản (Apple App Store Guideline 5.1.1v).
     * Xóa token FCM, thu hồi toàn bộ token đăng nhập và ghi nhận yêu cầu.
     *
     * @response array{message: string}
     */
    public function requestDeleteAccount(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user) {
            $user->update([
                'fcm_token' => null,
                'fcm_token_updated_at' => null,
            ]);

            $user->tokens()->delete();

            Log::info("Lái xe ID {$user->id} ({$user->name} - {$user->email}) đã gửi yêu cầu xóa tài khoản.");
        }

        return response()->json([
            'message' => 'Yêu cầu xóa tài khoản đã được ghi nhận. Phiên làm việc của bạn đã được đăng xuất khỏi hệ thống.',
        ]);
    }
}
