<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppVersionController extends Controller
{
    /**
     * Thông tin phiên bản ứng dụng tài xế (TMS Driver).
     *
     * Endpoint công khai để mobile app kiểm tra:
     * - latest_version: Phiên bản mới nhất
     * - min_version: Phiên bản tối thiểu bắt buộc
     * - release_notes: Ghi chú phát hành
     * - store_urls: Link tải trên Android / iOS
     *
     * @response array{latest_version: string, min_version: string, release_notes: string, store_urls: array{android: string, ios: string}}
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'latest_version' => (string) config('app.mobile_latest_version', '1.1.0'),
            'min_version' => (string) config('app.mobile_min_version', '1.0.0'),
            'release_notes' => (string) config('app.mobile_release_notes', 'Cập nhật tính năng và cải thiện hiệu năng hệ thống.'),
            'store_urls' => [
                'android' => (string) config('app.mobile_android_url', 'https://play.google.com/store/apps/details?id=vn.asgt.tmsdriver'),
                'ios' => (string) config('app.mobile_ios_url', 'https://apps.apple.com/app/id6810122889'),
            ],
        ]);
    }
}
