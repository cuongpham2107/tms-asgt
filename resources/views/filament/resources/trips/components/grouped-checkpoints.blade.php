@php
    use App\Models\Trip;
    use App\Enums\CheckpointType;

    /** @var Trip $trip */
    $allCheckpoints = $trip->checkpoints()->with('order')->orderBy('occurred_at')->get();

    // Group by (checkpoint_type, km_reading) — same type+km = same row
    $grouped = $allCheckpoints->groupBy(function ($cp) {
        $km = $cp->km_reading ? number_format((float) $cp->km_reading, 1, '.', '') : null;
        return $cp->checkpoint_type->value . '|' . ($km ?? '_null');
    });

    $typeLabels = collect(CheckpointType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->getLabel()]);
    $typeColors = collect(CheckpointType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->getColor()]);

    $colorMap = [
        'info'    => 'border-blue-300 bg-blue-50 text-blue-700',
        'warning' => 'border-amber-300 bg-amber-50 text-amber-700',
        'success' => 'border-emerald-300 bg-emerald-50 text-emerald-700',
        'danger'  => 'border-red-300 bg-red-50 text-red-700',
        'primary' => 'border-purple-300 bg-purple-50 text-purple-700',
        'gray'    => 'border-gray-300 bg-gray-50 text-gray-600',
    ];
@endphp

<div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 dark:bg-gray-800">
            <tr>
                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase w-[160px]">Loại</th>
                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase w-[100px]">Km</th>
                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase w-[170px]">Giờ</th>
                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase">Đơn hàng</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach ($grouped as $group)
                @php
                    $first = $group->first();
                    $type = $first->checkpoint_type->value;
                    $label = $typeLabels[$type] ?? $type;
                    $color = $typeColors[$type] ?? 'gray';
                    $colorClass = $colorMap[$color] ?? $colorMap['gray'];
                    $orderCodes = $group->pluck('order.order_code')->filter()->unique()->values();
                @endphp
                <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/50 transition-colors">
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border {{ $colorClass }}">
                            {{ $label }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300 font-mono tabular-nums">
                        {{ $first->km_reading ? number_format((float) $first->km_reading, 1) : '—' }}
                    </td>
                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400 text-xs whitespace-nowrap">
                        {{ $first->occurred_at?->format('H:i d/m/Y') ?? '—' }}
                    </td>
                    <td class="px-4 py-3">
                        @if ($orderCodes->isNotEmpty())
                            <div class="flex flex-wrap gap-1">
                                @foreach ($orderCodes as $code)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                        {{ $code }}
                                    </span>
                                @endforeach
                            </div>
                        @else
                            <span class="text-gray-400 text-xs">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
