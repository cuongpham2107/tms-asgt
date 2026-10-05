<div
    x-data="{
        syncing: false,
        speed: null,
        async doSync() {
            this.syncing = true;
            try {
                const response = await fetch('{{ route('gps.sync') }}');
                const data = await response.json();
                this.speed = data.speed ?? null;
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
    class="flex items-center"
>
    <x-filament::icon-button
        color="gray"
        icon="heroicon-o-arrow-path"
        icon-size="lg"
        label="Đồng bộ GPS"
        tooltip="Đồng bộ GPS"
        x-on:click="doSync"
        x-bind:disabled="syncing"
        x-bind:aria-busy="syncing ? 'true' : 'false'"
        x-bind:class="{ 'motion-safe:[&_svg]:animate-spin': syncing }"
    />
</div>
