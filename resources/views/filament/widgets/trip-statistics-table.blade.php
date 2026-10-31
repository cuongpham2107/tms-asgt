@php
    $renderer = $this->getRenderer();
    $rendererConfiguration = $this->getRendererConfiguration();
@endphp

{{-- Host cho renderer React: Filament chỉ mount một lần, cấu hình mới được đẩy qua update(). --}}
<div
    wire:key="{{ $this->getId() }}.renderer.{{ md5((string) $renderer) }}"
    x-load
    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('js-widget', 'filament/widgets') }}"
    x-data="jsWidgetComponent({
        renderer: {{ (string) \Illuminate\Support\Js::from($renderer) }},
        rendererConfiguration: JSON.parse($el.querySelector('[data-renderer-configuration]').textContent),
    })"
>
    <span
        hidden
        wire:key="{{ $this->getId() }}.renderer-configuration.{{ md5(json_encode($rendererConfiguration)) }}"
        x-init="updateRendererConfiguration(JSON.parse($el.textContent))"
    >
        {{-- JSON trong thẻ script: không bị escape thành &quot; (attribute làm phình gấp đôi dung lượng),
             JSON_HEX_TAG để dữ liệu người dùng không đóng được thẻ. --}}
        <script type="application/json" data-renderer-configuration>{!! json_encode($rendererConfiguration, JSON_HEX_TAG) !!}</script>
    </span>

    <div wire:ignore x-ref="host"></div>
</div>
