<x-filament::input.wrapper class="w-36">
    <x-filament::input.select wire:model.live="filter">
        @foreach ($filters as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
        @endforeach
    </x-filament::input.select>
</x-filament::input.wrapper>
