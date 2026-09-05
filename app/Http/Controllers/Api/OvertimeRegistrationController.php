<?php

namespace App\Http\Controllers\Api;

use App\Enums\OvertimeStatus;
use App\Enums\ShiftType;
use App\Http\Controllers\Controller;
use App\Models\OvertimeRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OvertimeRegistrationController extends Controller
{
    /**
     * Danh sách lịch đăng ký tăng cường của tài xế.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = OvertimeRegistration::query()
            ->where('driver_id', $user->id)
            ->orderBy('overtime_date', 'desc')
            ->orderBy('created_at', 'desc');

        if ($request->filled('month')) {
            $query->where('overtime_date', 'like', $request->month.'%');
        }

        $registrations = $query->get()->map(function (OvertimeRegistration $item) {
            return [
                'id' => $item->id,
                'driver_id' => $item->driver_id,
                'shift_type' => $item->shift_type?->value ?? $item->shift_type,
                'shift_type_label' => $item->shift_type?->getLabel() ?? $item->shift_type,
                'overtime_date' => $item->overtime_date?->format('Y-m-d'),
                'status' => $item->status?->value ?? $item->status,
                'status_label' => $item->status?->getLabel() ?? $item->status,
                'status_color' => $item->status?->getColor(),
                'notes' => $item->notes,
                'confirmed_at' => $item->confirmed_at?->format('Y-m-d H:i:s'),
                'created_at' => $item->created_at?->format('Y-m-d H:i:s'),
            ];
        });

        return response()->json([
            'data' => $registrations,
        ]);
    }

    /**
     * Tài xế gửi yêu cầu đăng ký tăng cường.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'shift_type' => ['required', Rule::enum(ShiftType::class)],
            'overtime_date' => [
                'required',
                'date',
                'date_format:Y-m-d',
                'after_or_equal:today',
                function ($attribute, $value, $fail) use ($user) {
                    $exists = OvertimeRegistration::where('driver_id', $user->id)
                        ->whereDate('overtime_date', $value)
                        ->exists();

                    if ($exists) {
                        $fail('Bạn đã đăng ký lịch tăng cường cho ngày này rồi.');
                    }
                },
            ],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'overtime_date.after_or_equal' => 'Ngày tăng cường phải từ hôm nay trở đi.',
            'shift_type.required' => 'Vui lòng chọn loại ca tăng cường.',
        ]);

        $registration = OvertimeRegistration::create([
            'driver_id' => $user->id,
            'shift_type' => $validated['shift_type'],
            'overtime_date' => $validated['overtime_date'],
            'status' => OvertimeStatus::Pending,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'data' => [
                'id' => $registration->id,
                'driver_id' => $registration->driver_id,
                'shift_type' => $registration->shift_type?->value ?? $registration->shift_type,
                'shift_type_label' => $registration->shift_type?->getLabel() ?? $registration->shift_type,
                'overtime_date' => $registration->overtime_date?->format('Y-m-d'),
                'status' => $registration->status?->value ?? $registration->status,
                'status_label' => $registration->status?->getLabel() ?? $registration->status,
                'status_color' => $registration->status?->getColor(),
                'notes' => $registration->notes,
                'created_at' => $registration->created_at?->format('Y-m-d H:i:s'),
            ],
            'message' => 'Đăng ký tăng cường thành công, vui lòng chờ quản lý duyệt.',
        ], 201);
    }

    /**
     * Tài xế huỷ đăng ký tăng cường (chỉ khi còn ở trạng thái chờ duyệt).
     */
    public function destroy(Request $request, OvertimeRegistration $overtimeRegistration): JsonResponse
    {
        $user = $request->user();

        if ($overtimeRegistration->driver_id !== $user->id) {
            return response()->json([
                'message' => 'Bạn không có quyền thao tác trên bản ghi này.',
            ], 403);
        }

        if ($overtimeRegistration->status !== OvertimeStatus::Pending) {
            return response()->json([
                'message' => 'Không thể huỷ đăng ký đã được xử lý (đã xác nhận hoặc từ chối).',
            ], 422);
        }

        $overtimeRegistration->delete();

        return response()->json([
            'message' => 'Huỷ đăng ký tăng cường thành công.',
        ]);
    }
}
