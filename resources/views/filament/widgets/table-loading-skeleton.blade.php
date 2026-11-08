{{--
    Khung chờ cho widget bảng React tải sau trang (defer).
    Dùng đúng class bảng của Filament + fi-ta-content-loading để CSS skeleton của Filament tự vẽ vạch xám.

    @var array<int, string> $columns  Tiêu đề cột
    @var int $rows                    Số dòng giả
--}}
<div class="fi-ta-ctn">
    <div class="fi-ta-content-ctn fi-ta-content-loading" role="status" aria-busy="true">
        <table class="fi-ta-table">
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th class="fi-ta-header-cell">{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>

            <tbody class="fi-ta-records">
                @for ($row = 0; $row < $rows; $row++)
                    <tr class="fi-ta-row">
                        @foreach ($columns as $index => $column)
                            <td class="fi-ta-cell">
                                <div class="fi-ta-col">
                                    {{-- Độ dài chữ khác nhau để vạch skeleton so le như bảng thật --}}
                                    <div class="fi-ta-text-item fi-size-sm fi-ta-text">{{ str_repeat('x', 6 + (($row * 7 + $index * 5) % 12)) }}</div>
                                </div>
                            </td>
                        @endforeach
                    </tr>
                @endfor
            </tbody>
        </table>

        <span class="fi-sr-only">Đang tải dữ liệu…</span>
    </div>
</div>
