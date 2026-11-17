<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Vehicle\PublicVehicleLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API public (xác thực qua X-Api-Key) cho hệ thống ngoài tra cứu thông tin xe, lái xe và link GPS theo biển số.
 */
class PublicVehicleController extends Controller
{
    public function __construct(
        private readonly PublicVehicleLookupService $lookupService,
    ) {}

    /**
     * Tra cứu thông tin xe + lái xe + link bản đồ GPS theo biển số (hỗ trợ cả 4 số cuối hoặc biển đầy đủ).
     *
     * @queryParam search string Biển số hoặc 4 số cuối cần tìm. Example: 12067
     * @queryParam limit int Số kết quả tối đa (mặc định 10, tối đa 25). Example: 5
     *
     * @response array{query: string, count: int, data: array<int, array<string, mixed>>}
     */
    public function search(Request $request): JsonResponse
    {
        $rawSearch = trim((string) ($request->query('search') ?? $request->query('q') ?? ''));
        $normalized = $this->lookupService->normalizeSearch($rawSearch);

        if (strlen($normalized) < 2) {
            return response()->json([
                'message' => 'Vui lòng nhập ít nhất 2 ký tự biển số để tìm kiếm.',
            ], 422);
        }

        $limit = max(1, min((int) $request->query('limit', 10), 25));

        $vehicles = $this->lookupService->search($rawSearch, $limit);
        $data = $vehicles->map(fn ($vehicle): array => $this->lookupService->formatVehiclePayload($vehicle))->values()->all();

        return response()->json([
            'query' => $rawSearch,
            'count' => count($data),
            'data' => $data,
        ]);
    }
}
