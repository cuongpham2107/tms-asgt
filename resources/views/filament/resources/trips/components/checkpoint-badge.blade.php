@php
$colorMap = [
    'info'    => 'border-blue-300 bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
    'warning' => 'border-amber-300 bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    'success' => 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    'danger'  => 'border-red-300 bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300',
    'primary' => 'border-purple-300 bg-purple-50 text-purple-700 dark:bg-purple-950 dark:text-purple-300',
    'gray'    => 'border-gray-300 bg-gray-50 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
];
$class = $colorMap[$color] ?? $colorMap['gray'];
@endphp
<span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border {{ $class }}">
    {{ $label }}
</span>
