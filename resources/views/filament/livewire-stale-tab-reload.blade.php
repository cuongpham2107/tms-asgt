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
                    // 404/419 trên endpoint Livewire: snapshot của tab cũ sau deploy không còn hợp lệ.
                    // preventDefault() chặn toast 404 và hộp thoại "This page has expired" của Livewire,
                    // sau đó tự tải lại trang (tối đa 1 lần / 10 giây).
                    if (response && (response.status === 404 || response.status === 419)) {
                        preventDefault();
                        reloadOnce();
                    }
                });
            });
        });
    })();
</script>
