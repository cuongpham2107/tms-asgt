@php
    use App\Models\Trip;
    use App\Enums\CheckpointType;

    /** @var Trip $record */
    $allCheckpoints = $record->checkpoints()->with('order')->orderBy('occurred_at')->get();

    // Group by (checkpoint_type, km_reading) — same type+km = same row
    $grouped = $allCheckpoints->groupBy(function ($cp) {
        $km = $cp->km_reading ? number_format((float) $cp->km_reading, 1, '.', '') : null;
        return $cp->checkpoint_type->value . '|' . ($km ?? '_null');
    });

    $typeLabels = collect(CheckpointType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->getLabel()]);
    $typeColors = collect(CheckpointType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->getColor()]);

    $colorMap = [
        'info'    => 'border-blue-300 bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
        'warning' => 'border-amber-300 bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
        'success' => 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
        'danger'  => 'border-red-300 bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300',
        'primary' => 'border-purple-300 bg-purple-50 text-purple-700 dark:bg-purple-950 dark:text-purple-300',
        'gray'    => 'border-gray-300 bg-gray-50 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
    ];

    // Get checkpoint IDs per group for the update action
    $groupIds = [];
    foreach ($grouped as $key => $group) {
        $groupIds[$key] = $group->pluck('id')->toArray();
    }
@endphp

<div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700" x-data="{ editing: null }">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 dark:bg-gray-800">
            <tr>
                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase w-[160px]">Loại</th>
                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase w-[100px]">Km</th>
                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase w-[170px]">Giờ</th>
                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase">Đơn hàng</th>
                <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-500 uppercase w-[70px]"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach ($grouped as $groupKey => $group)
                @php
                    $first = $group->first();
                    $type = $first->checkpoint_type->value;
                    $label = $typeLabels[$type] ?? $type;
                    $color = $typeColors[$type] ?? 'gray';
                    $colorClass = $colorMap[$color] ?? $colorMap['gray'];
                    $orderCodes = $group->pluck('order.order_code')->filter()->unique()->values();
                    $ids = $groupIds[$groupKey] ?? [];
                    $idsJson = json_encode($ids);
                @endphp
                <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/50 transition-colors" x-data="{ km: '{{ (int) ($first->km_reading ?? 0) ?: '' }}', time: '{{ $first->occurred_at?->format('Y-m-d\TH:i') ?? '' }}' }">
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border {{ $colorClass }}">
                            {{ $label }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300 font-mono tabular-nums">
                        <template x-if="editing === '{{ $groupKey }}'">
                            <input type="number" step="0.1" x-model="km" class="w-20 rounded-md border-gray-300 text-sm py-1 px-2 dark:bg-gray-800 dark:border-gray-600 dark:text-gray-200" />
                        </template>
                        <template x-if="editing !== '{{ $groupKey }}'">
                            <span>{{ $first->km_reading ? number_format((float) $first->km_reading, 0, ',', '.') : '—' }}</span>
                        </template>
                    </td>
                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400 text-xs whitespace-nowrap">
                        <template x-if="editing === '{{ $groupKey }}'">
                            <input type="datetime-local" x-model="time" class="rounded-md border-gray-300 text-sm py-1 px-2 dark:bg-gray-800 dark:border-gray-600 dark:text-gray-200" />
                        </template>
                        <template x-if="editing !== '{{ $groupKey }}'">
                            <span>{{ $first->occurred_at?->format('H:i d/m/Y') ?? '—' }}</span>
                        </template>
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
                    <td class="px-3 py-3 text-center">
                        <template x-if="editing === '{{ $groupKey }}'">
                            <div class="flex items-center gap-1">
                                <button
                                    type="button"
                                    class="p-1.5 rounded-lg text-white bg-green-500 hover:bg-green-600 transition-colors"
                                    title="Lưu"
                                    @click="
                                        fetch('/trips/{{ $record->id }}/checkpoints/bulk-update', {
                                            method: 'POST',
                                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                                            body: JSON.stringify({ ids: {{ $idsJson }}, km_reading: km || null, occurred_at: time || null })
                                        }).then(r => { editing = null; location.reload(); })
                                    "
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                </button>
                                <button
                                    type="button"
                                    class="p-1.5 rounded-lg text-gray-500 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 dark:text-gray-400 transition-colors"
                                    title="Huỷ"
                                    @click="editing = null"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                        </template>
                        <template x-if="editing !== '{{ $groupKey }}'">
                            <button
                                type="button"
                                class="p-1.5 rounded-lg text-gray-400 hover:text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-500/10 dark:hover:text-primary-400 transition-colors"
                                title="Sửa"
                                @click="editing = '{{ $groupKey }}'"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
                        </template>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
