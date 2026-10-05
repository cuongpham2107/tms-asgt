@php
    $badgeColor = in_array($color ?? null, ['primary', 'gray', 'success', 'warning', 'danger', 'info'], true) ? $color : 'gray';
@endphp
<x-filament::badge :color="$badgeColor">
    {{ $label }}
</x-filament::badge>
