<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /**
     * Đăng nhập lái xe, trả về token Sanctum.
     *
     * @response array{user: UserResource, token: string}
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
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        /** @var User $user */
        $user = Auth::user();

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'user' => UserResource::make($user),
            'token' => $token,
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

        return response()->json(['message' => 'Logged out']);
    }
}
