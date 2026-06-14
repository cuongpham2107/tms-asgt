<div
    x-data="{
        syncing: false,
        async doSync() {
            this.syncing = true;
            try {
                const response = await fetch('{{ route('gps.sync') }}');
                const data = await response.json();
                $dispatch('filament-notification', {
                    type: data.success ? 'success' : 'danger',
                    title: data.success ? 'Đồng bộ GPS thành công' : 'Đồng bộ GPS thất bại',
                    body: data.message,
                });
            } catch (e) {
                $dispatch('filament-notification', {
                    type: 'danger',
                    title: 'Lỗi kết nối',
                    body: 'Không thể kết nối đến server',
                });
            } finally {
                this.syncing = false;
            }
        }
    }"
    class="flex items-center gap-2 px-2"
>
    <x-filament::button
        size="sm"
        color="gray"
        icon="heroicon-o-arrow-path"
        x-on:click="doSync"
        x-bind:disabled="syncing"
    >
        <span x-show="!syncing">GPS</span>
        <span x-show="syncing" x-cloak>
            <x-filament::loading-indicator class="h-4 w-4" />
        </span>
    </x-filament::button>
</div>
