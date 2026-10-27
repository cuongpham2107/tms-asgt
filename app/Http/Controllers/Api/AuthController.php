<?php

namespace App\Http\Controllers\Api;

use App\Enums\TripStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DriverShiftResource;
use App\Http\Resources\UserResource;
use App\Models\DriverShift;
use App\Models\User;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

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
            'employee_code' => 'nullable|string',
            'password' => 'required|string',
            'fcm_token' => 'nullable|string|max:1000',
        ]);

        $account = trim((string) ($request->input('login') ?? $request->input('employee_code') ?? $request->input('email') ?? $request->input('phone')));
        if ($account === '') {
            return response()->json(['message' => 'Vui lòng nhập mã nhân viên, email hoặc số điện thoại'], 422);
        }

        $cleanPhone = preg_replace('/\D/', '', $account);
        if (str_starts_with($cleanPhone, '84') && strlen($cleanPhone) >= 11) {
            $cleanPhone = '0'.substr($cleanPhone, 2);
        }

        $upperAccount = strtoupper($account);

        $candidates = User::query()
            ->where(function ($query) use ($account, $cleanPhone, $upperAccount) {
                $query->where('email', $account)
                    ->orWhere('phone', $account)
                    ->orWhere('employee_code', $account);
                if ($upperAccount !== $account) {
                    $query->orWhere('employee_code', $upperAccount);
                }
                if ($cleanPhone !== '' && $cleanPhone !== $account) {
                    $query->orWhere('phone', $cleanPhone);
                }
            })
            ->with('roles')
            ->get()
            ->sortByDesc(function ($u) {
                return (method_exists($u, 'hasRole') && $u->hasRole('driver')) ? 1 : 0;
            });

        $user = $candidates->first(function ($candidate) use ($request) {
            return Hash::check($request->password, $candidate->password);
        });

        // Lớp 2: Nếu mật khẩu nội bộ không khớp, thử xác thực qua API của ASGL ID
        if (! $user) {
            try {
                $asglResponse = Http::timeout(8)->withHeaders([
                    'Content-Type' => 'application/json',
                ])->post('https://id.asgl.net.vn/api/auth/login', [
                    'login' => $account,
                    'password' => $request->password,
                ]);

                if ($asglResponse->successful()) {
                    $userResponse = $asglResponse->json('data.user') ?? [];
                    $asglUsername = trim((string) ($userResponse['username'] ?? ''));
                    $asglEmployeeCode = trim((string) ($userResponse['employee_code'] ?? $userResponse['code'] ?? ''));
                    $asglEmail = trim((string) ($userResponse['email'] ?? ''));
                    $asglMobile = trim((string) ($userResponse['mobile_phone'] ?? ''));
                    $cleanAsglPhone = preg_replace('/\D/', '', $asglMobile);
                    if (str_starts_with($cleanAsglPhone, '84') && strlen($cleanAsglPhone) >= 11) {
                        $cleanAsglPhone = '0'.substr($cleanAsglPhone, 2);
                    }

                    // Tìm user dựa trên mã nhân viên, username, email hoặc SĐT từ ASGL
                    $user = User::query()
                        ->where(function ($q) use ($asglUsername, $asglEmployeeCode, $asglEmail, $cleanAsglPhone) {
                            $hasCondition = false;
                            if ($asglEmployeeCode !== '') {
                                $q->where('employee_code', $asglEmployeeCode);
                                $hasCondition = true;
                            }
                            if ($asglUsername !== '') {
                                $method = $hasCondition ? 'orWhere' : 'where';
                                $q->{$method}('employee_code', $asglUsername)
                                    ->orWhere('email', $asglUsername);
                                $hasCondition = true;
                            }
                            if ($asglEmail !== '') {
                                $method = $hasCondition ? 'orWhere' : 'where';
                                $q->{$method}('email', $asglEmail);
                                $hasCondition = true;
                            }
                            if ($cleanAsglPhone !== '') {
                                $method = $hasCondition ? 'orWhere' : 'where';
                                $q->{$method}('phone', $cleanAsglPhone);
                            }
                        })
                        ->with('roles')
                        ->first();

                    if ($user) {
                        // Tự động đồng bộ mật khẩu mới từ ASGL vào DB nội bộ TMS
                        $user->update([
                            'password' => Hash::make($request->password),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Lỗi kết nối ASGL ID: '.$e->getMessage());
            }
        }

        if (! $user) {
            /** @status 401 */
            return response()->json(['message' => 'Thông tin đăng nhập không hợp lệ'], 401);
        }

        if (! method_exists($user, 'hasRole') || ! $user->hasRole('driver')) {
            /** @status 403 */
            return response()->json(['message' => 'Tài khoản này không có quyền tài xế'], 403);
        }

        if (! $user->is_active) {
            /** @status 403 */
            return response()->json(['message' => 'Tài khoản của bạn đã ngừng hoạt động. Vui lòng liên hệ điều hành.'], 403);
        }

        // Thu hồi toàn bộ token cũ để đảm bảo chỉ đăng nhập trên 1 thiết bị duy nhất
        $user->tokens()->delete();

        $token = $user->createToken('mobile')->plainTextToken;

        if ($request->filled('fcm_token')) {
            $user->update([
                'fcm_token' => $request->input('fcm_token'),
                'fcm_token_updated_at' => now(),
            ]);
        }

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
                ? DriverShiftResource::make($activeShift->load(['driver', 'trips' => fn ($q) => $q->whereNotIn('status', [TripStatus::Cancelled->value, TripStatus::Pending->value], 'and')->with(['vehicle', 'orders'])]))
                : null,
            'app_version' => [
                'latest_version' => (string) config('app.mobile_latest_version', '1.1.5'),
                'min_version' => (string) config('app.mobile_min_version', '1.0.0'),
                'release_notes' => (string) config('app.mobile_release_notes', 'Cập nhật tính năng và cải thiện hiệu năng hệ thống.'),
                'store_urls' => [
                    'android' => (string) config('app.mobile_android_url', 'https://play.google.com/store/apps/details?id=vn.asgt.tmsdriver'),
                    'ios' => (string) config('app.mobile_ios_url', 'https://apps.apple.com/app/id6810122889'),
                ],
            ],
        ]);
    }

    /**
     * Đăng xuất (thu hồi token hiện tại).
     *
     * @response array{message: string}
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user) {
            $token = $user->currentAccessToken();
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }
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
