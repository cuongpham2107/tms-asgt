{{--
    Filament chỉ bật loadingSkeleton cho các target có sẵn của bảng (sort, phân trang, tableSearch...).
    Bộ lọc tuỳ biến của trang (ngày, ô tìm kiếm, pill) cập nhật thuộc tính riêng nên không kích hoạt.
    Partial này gắn class skeleton của Filament lên bảng khi request chạm vào các thuộc tính đó.

    @var array<int, string> $targets  Tên thuộc tính Livewire của bộ lọc.
--}}
@script
<script>
    const targets = @js($targets);
    const touches = (name) => targets.some((target) => name === target || name.startsWith(target + '.'));

    const off = Livewire.hook('commit', ({ component, commit, respond, fail }) => {
        if (! document.contains($wire.$el)) {
            off();

            return;
        }

        if (component.id !== $wire.$id) {
            return;
        }

        const isFilterRequest = Object.keys(commit.updates ?? {}).some(touches)
            || (commit.calls ?? []).some(({ method, params }) => method.startsWith('filter') || (method.startsWith('$') && touches(String(params?.[0] ?? ''))));

        if (! isFilterRequest) {
            return;
        }

        const table = $wire.$el.querySelector('.fi-ta-content-ctn');
        const stop = () => table?.classList.remove('fi-ta-content-loading');

        table?.classList.add('fi-ta-content-loading');
        respond(stop);
        fail(stop);
    });
</script>
@endscript
