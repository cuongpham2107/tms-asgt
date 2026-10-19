@props([
    'livewire' => null,
])

<x-filament-panels::layout.base :livewire="$livewire">
    <div class="relative min-h-screen w-full lg:grid lg:grid-cols-5 bg-slate-50 dark:bg-slate-950 font-sans antialiased">
        <!-- CỘT BÊN TRÁI: Hình ảnh dàn xe ASG (chiếm 3/5 màn hình desktop) -->
        <div class="relative hidden h-full min-h-screen w-full lg:col-span-3 flex-col justify-between overflow-hidden bg-slate-900 lg:flex select-none">
            <img
                src="{{ asset('images/login-banner.jpg') }}"
                alt="Đội xe ASG Logistics"
                class="absolute inset-0 h-full w-full object-cover object-center"
            />
            <!-- Gradient vignette overlay tạo chiều sâu & độ tương phản chuyên nghiệp -->
            <div class="absolute inset-0 bg-gradient-to-t from-slate-950/95 via-slate-950/35 to-slate-950/30"></div>

            <!-- Top branding badge -->
            <div class="relative z-10 p-8 xl:p-12">
                <div class="inline-flex items-center gap-3 px-3.5 py-2 rounded-full bg-slate-950/60 backdrop-blur-md border border-white/10 shadow-lg">
                    <div class="bg-white rounded-full px-2.5 py-1 flex items-center justify-center shadow-xs">
                        <img src="{{ asset('images/ASG.png') }}" alt="ASG Logo" class="h-4.5 w-auto object-contain" />
                    </div>
                    <div class="flex items-center gap-2 pr-2 text-xs font-medium text-slate-200">
                        <span class="text-white font-semibold tracking-tight">ASG Logistics</span>
                        <span class="text-slate-500 font-light">&bull;</span>
                        <span class="text-slate-300 text-[11px] font-normal">TMS v2.0</span>
                    </div>
                </div>
            </div>

            <!-- Bottom information card on image -->
            <div class="relative z-10 p-10 xl:p-14 space-y-4">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-sky-500/20 text-sky-300 border border-sky-400/30 backdrop-blur-md">
                    <span class="inline-block w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    Hệ thống Quản lý Vận tải & Điều phối Đội xe
                </div>
                <h2 class="text-2xl xl:text-3xl font-extrabold text-white tracking-tight leading-snug drop-shadow-md">
                    Kết nối toàn diện, điều phối thông minh & tối ưu lộ trình
                </h2>
                <p class="text-sm text-slate-200/90 max-w-lg leading-relaxed drop-shadow">
                    Giám sát hành trình GPS thời gian thực, tự động hoá quy trình điều hành chuyến xe, số hoá chứng từ vận chuyển và đồng bộ dữ liệu liên tục.
                </p>
                <div class="pt-3 flex flex-wrap items-center gap-6 text-xs font-medium text-slate-200 drop-shadow">
                    <div class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        Giám sát GPS thời gian thực
                    </div>
                    <div class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        Điều phối chuyến tự động
                    </div>
                    <div class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        Bảo mật cấp doanh nghiệp
                    </div>
                </div>
            </div>
        </div>

        <!-- CỘT BÊN PHẢI: Form Đăng nhập theo mẫu mockup shadcn (chiếm 2/5 màn hình desktop) -->
        <div class="flex min-h-screen w-full lg:col-span-2 flex-col justify-between p-6 sm:p-10 lg:p-12 xl:p-16">
            <!-- Brand header trên góc phải -->
            <div class="flex items-center justify-between w-full">
                <div class="flex items-center gap-3">
                    <img src="{{ asset('images/ASG.png') }}" alt="ASG Logo" class="h-9 w-auto object-contain" />
                    <!-- <div>
                        <span class="text-base font-bold text-slate-900 dark:text-white tracking-tight">TMS - ASGT</span>
                        <span class="hidden sm:inline-block ml-2 text-[10px] font-semibold px-2 py-0.5 rounded-full bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300 border border-sky-200 dark:border-sky-800">v2.0</span>
                    </div> -->
                </div>
            </div>

            <!-- Vùng form đăng nhập căn giữa -->
            <div class="mx-auto w-full max-w-sm sm:max-w-md my-auto py-8">
                <!-- Mobile banner (khi xem trên điện thoại ẩn cột trái) -->
                <div class="lg:hidden mb-6 relative rounded-2xl overflow-hidden shadow-sm border border-slate-200 dark:border-slate-800">
                    <img src="{{ asset('images/login-banner.jpg') }}" alt="Đội xe ASG" class="w-full h-40 object-cover object-center" />
                    <div class="absolute inset-0 bg-gradient-to-t from-slate-950/85 via-slate-950/30 to-transparent flex items-end p-4">
                        <span class="text-xs font-semibold text-white drop-shadow">Hệ thống Quản lý Vận tải & Điều phối ASG</span>
                    </div>
                </div>

                {{ $slot }}
            </div>

            <!-- Footer bản quyền -->
            <div class="text-center text-xs text-slate-500 dark:text-slate-400 py-2">
                © {{ date('Y') }} Tập đoàn ASG. Bản quyền thuộc về ASG Logistics.
            </div>
        </div>
    </div>
</x-filament-panels::layout.base>
