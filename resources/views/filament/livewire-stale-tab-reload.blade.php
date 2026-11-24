<script>
    (() => {
        if (window.__tmsLivewireStaleTabBound) {
            return;
        }

        window.__tmsLivewireStaleTabBound = true;

        const reloadOnce = () => {
            const key = 'tms:livewire-404-reload';
            const lastReloadAt = Number(sessionStorage.getItem(key) || 0);

            if (Date.now() - lastReloadAt < 10000) {
                return;
            }

            sessionStorage.setItem(key, String(Date.now()));
            window.location.reload();
        };

        document.addEventListener('livewire:init', () => {
            if (!window.Livewire || typeof Livewire.interceptRequest !== 'function') {
                return;
            }

            Livewire.interceptRequest(({ onError }) => {
                onError(({ response, preventDefault }) => {
                    // 404 trên endpoint Livewire: snapshot/tab cũ sau deploy hoặc APP_KEY đổi.
                    // Tự tải lại trang (tối đa 1 lần / 10 giây) thay vì hiện toast lỗi.
                    if (response && response.status === 404) {
                        preventDefault();
                        reloadOnce();
                    }
                });
            });
        });
    })();
</script>
