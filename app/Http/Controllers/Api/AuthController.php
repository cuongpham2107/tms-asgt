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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * Đăng nhập lái xe (bằng Email hoặc Số điện thoại), trả về token Sanctum + ca đang active (nếu có).
     *
     * @response array{user: UserResource, token: string, shift: DriverShiftResource|null}
     */
    #[BodyParameter('email', type: 'string', example: 'cvt2307b@tms.local hoặc 0965455995')]
    #[BodyParameter('login', type: 'string', example: '0965455995')]
    #[BodyParameter('password', type: 'string', example: '66668888')]
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'nullable|string',
            'login' => 'nullable|string',
            'phone' => 'nullable|string',
            'password' => 'required|string',
        ]);

        $account = trim((string) ($request->input('login') ?? $request->input('email') ?? $request->input('phone')));
        if ($account === '') {
            return response()->json(['message' => 'Vui lòng nhập email hoặc số điện thoại'], 422);
        }

        $cleanPhone = preg_replace('/\D/', '', $account);
        if (str_starts_with($cleanPhone, '84') && strlen($cleanPhone) >= 11) {
            $cleanPhone = '0'.substr($cleanPhone, 2);
        }

        $user = User::query()
            ->where(function ($query) use ($account, $cleanPhone) {
                $query->where('email', $account)
                    ->orWhere('phone', $account);
                if ($cleanPhone !== '' && $cleanPhone !== $account) {
                    $query->orWhere('phone', $cleanPhone);
                }
            })
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            /** @status 401 */
            return response()->json(['message' => 'Thông tin đăng nhập không hợp lệ'], 401);
        }

        Auth::setUser($user);

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
            'min_app_version' => config('app.min_mobile_version'),
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

    /**
     * Đổi mật khẩu tài khoản lái xe.
     *
     * @response array{message: string}
     */
    #[BodyParameter('current_password', type: 'string', example: '66668888')]
    #[BodyParameter('new_password', type: 'string', example: 'newPassword123')]
    #[BodyParameter('new_password_confirmation', type: 'string', example: 'newPassword123')]
    public function changePassword(Request $request): JsonResponse
    {
        $currentPassword = (string) $request->input('current_password');
        $newPassword = (string) ($request->input('new_password') ?? $request->input('password'));
        $confirmPassword = (string) ($request->input('new_password_confirmation') ?? $request->input('password_confirmation'));

        if ($currentPassword === '') {
            return response()->json(['message' => 'Vui lòng nhập mật khẩu hiện tại'], 422);
        }

        if ($newPassword === '' || strlen($newPassword) < 6) {
            return response()->json(['message' => 'Mật khẩu mới phải có ít nhất 6 ký tự'], 422);
        }

        if ($newPassword !== $confirmPassword) {
            return response()->json(['message' => 'Xác nhận mật khẩu mới không khớp'], 422);
        }

        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($currentPassword, $user->password)) {
            return response()->json([
                'message' => 'Mật khẩu hiện tại không chính xác',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($newPassword),
        ]);

        return response()->json([
            'message' => 'Đổi mật khẩu thành công',
        ]);
    }
}
