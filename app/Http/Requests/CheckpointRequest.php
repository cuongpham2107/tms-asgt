<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Xác thực dữ liệu khi tạo một mốc hành trình.
     *
     * - `order_id`: đơn hàng bắt buộc và phải tồn tại.
     * - `shift_id`, `delivery_point_id`: tuỳ chọn, dùng khi có ca trực hoặc điểm giao cụ thể.
     * - `checkpoint_type`: loại mốc hành trình.
     * - `occurred_at`, `km_reading`, `gps_lat`, `gps_lng`: thông tin thời gian và vị trí.
     * - `voice_note`: ghi chú chuyển từ voice sang text.
     * - `photos.*`: ảnh đính kèm cho mốc.
     */
    public function rules(): array
    {
        return [
            // ID đơn hàng cần ghi nhận checkpoint.
            'order_id' => 'required|exists:orders,id',
            // ID ca trực hiện tại của lái xe (nếu có).
            'shift_id' => 'nullable|exists:driver_shifts,id',
            // ID điểm giao trong đơn hàng nhiều điểm (nếu có).
            'delivery_point_id' => 'nullable|exists:order_delivery_points,id',
            // Loại mốc hành trình.
            'checkpoint_type' => 'required|string',
            // Thời điểm phát sinh mốc.
            'occurred_at' => 'nullable|date',
            // Chỉ số km đồng hồ.
            'km_reading' => 'nullable|numeric',
            // Tọa độ vĩ độ.
            'gps_lat' => 'nullable|numeric',
            // Tọa độ kinh độ.
            'gps_lng' => 'nullable|numeric',
            // Ghi chú (voice to text).
            'voice_note' => 'nullable|string',
            // Danh sách ảnh đính kèm.
            'photos' => 'nullable|array',
            // Từng ảnh trong danh sách photos.
            'photos.*' => 'nullable|image|max:10240',
        ];
    }
}
