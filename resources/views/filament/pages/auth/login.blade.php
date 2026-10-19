<div class="w-full space-y-6">
    <div class="space-y-1.5 text-left">
        <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white">
            Đăng nhập tài khoản
        </h1>
        <p class="text-sm text-slate-500 dark:text-slate-400">
            Nhập Email hoặc Mã nhân viên bên dưới để truy cập hệ thống
        </p>
    </div>

    <div class="login-form-container">
        {{ $this->content }}
    </div>

    <x-filament-actions::modals />
</div>
