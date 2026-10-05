@php
    use App\Services\ActivityLog\ActivityLogFormatter;

    $event = $activity->event ?? 'updated';
    $icon = match($event) {
        'created' => 'heroicon-m-check-badge',
        'updated' => 'heroicon-m-pencil-square',
        'deleted' => 'heroicon-m-trash',
        default => 'heroicon-m-information-circle',
    };

    $color = match($event) {
        'created' => 'success',
        'updated' => 'warning',
        'deleted' => 'danger',
        default => 'info',
    };

    $changes = $activity->attribute_changes;
    if (is_array($changes) && (isset($changes['attributes']) || isset($changes['old']))) {
        $attributes = $changes['attributes'] ?? [];
        $old = $changes['old'] ?? [];
    } elseif ($changes instanceof \Illuminate\Support\Collection) {
        $attributes = $changes->get('attributes', []);
        $old = $changes->get('old', []);
    } else {
        $properties = $activity->properties;
        if (is_array($properties) && (isset($properties['attributes']) || isset($properties['old']))) {
            $attributes = $properties['attributes'] ?? [];
            $old = $properties['old'] ?? [];
        } elseif ($properties instanceof \Illuminate\Support\Collection) {
            $attributes = $properties->get('attributes', []);
            $old = $properties->get('old', []);
        } else {
            $attributes = is_array($changes) ? $changes : ($changes?->toArray() ?? []);
            $old = [];
        }
    }
@endphp

<div class="space-y-6">
    <dl class="grid grid-cols-1 gap-4 rounded-xl bg-gray-50 p-4 text-sm sm:grid-cols-2 dark:bg-white/5">
        <div>
            <dt class="text-xs text-gray-500 dark:text-gray-400">Sự kiện</dt>
            <dd class="mt-1 flex">
                <x-filament::badge :color="$color" :icon="$icon">
                    {{ match($event) {
                        'created' => 'Tạo mới',
                        'updated' => 'Cập nhật',
                        'deleted' => 'Xóa',
                        default => ucfirst($event)
                    } }}
                </x-filament::badge>
            </dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500 dark:text-gray-400">Người thực hiện</dt>
            <dd class="mt-1 flex items-center gap-1.5 font-medium text-gray-950 dark:text-white">
                <x-filament::icon icon="heroicon-m-user" class="h-4 w-4 text-gray-400 dark:text-gray-500" />
                {{ $activity->causer?->name ?? 'Hệ thống' }}
            </dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500 dark:text-gray-400">Đối tượng</dt>
            <dd class="mt-1 font-medium text-gray-950 dark:text-white">
                {{ class_basename($activity->subject_type ?? '') }} <span class="tabular-nums">#{{ $activity->subject_id }}</span>
            </dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500 dark:text-gray-400">Thời gian</dt>
            <dd class="mt-1 flex items-center gap-1.5 font-medium text-gray-950 dark:text-white">
                <x-filament::icon icon="heroicon-m-calendar" class="h-4 w-4 text-gray-400 dark:text-gray-500" />
                <span class="tabular-nums">{{ $activity->created_at->format('H:i:s d/m/Y') }}</span>
                <span class="text-xs font-normal text-gray-500 dark:text-gray-400">({{ $activity->created_at->diffForHumans() }})</span>
            </dd>
        </div>
    </dl>

    @if (!empty($attributes))
        <x-filament::section heading="Chi tiết biến động dữ liệu" icon="heroicon-o-arrows-right-left" compact>
            <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
                <table class="w-full divide-y divide-gray-200 text-start text-sm dark:divide-white/10">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr class="text-xs text-gray-500 dark:text-gray-400">
                            <th class="w-1/3 px-3 py-2 text-start font-medium">Trường dữ liệu</th>
                            @if (!empty($old))
                                <th class="w-1/3 px-3 py-2 text-start font-medium">Trước đó</th>
                                <th class="w-1/3 px-3 py-2 text-start font-medium">Thay đổi thành</th>
                            @else
                                <th class="px-3 py-2 text-start font-medium" colspan="2">Giá trị thiết lập</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($attributes as $key => $newVal)
                            @php
                                $oldVal = $old[$key] ?? null;
                                $label = ActivityLogFormatter::getFieldLabel($key);
                                $formattedOld = ActivityLogFormatter::formatValue($key, $oldVal, $activity->subject_type);
                                $formattedNew = ActivityLogFormatter::formatValue($key, $newVal, $activity->subject_type);
                            @endphp
                            <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="px-3 py-2 font-medium text-gray-950 dark:text-white">{{ $label }}</td>
                                @if (!empty($old))
                                    <td class="px-3 py-2">
                                        <span class="inline-flex items-center rounded-md bg-danger-50 px-1.5 py-0.5 text-danger-700 line-through dark:bg-danger-400/10 dark:text-danger-400">
                                            {{ $formattedOld }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex items-center rounded-md bg-success-50 px-1.5 py-0.5 font-medium text-success-700 dark:bg-success-400/10 dark:text-success-400">
                                            {{ $formattedNew }}
                                        </span>
                                    </td>
                                @else
                                    <td class="px-3 py-2" colspan="2">
                                        <span class="inline-flex items-center rounded-md bg-gray-100 px-1.5 py-0.5 font-medium text-gray-950 dark:bg-white/10 dark:text-white">
                                            {{ $formattedNew }}
                                        </span>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</div>
