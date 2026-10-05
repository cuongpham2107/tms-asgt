<?php

namespace App\Providers;

use App\Filament\Actions\ActivityLogTimelineTableAction;
use App\Filament\Plugins\ActivitylogPlugin;
use App\Models\Order;
use App\Models\Trip;
use App\Observers\OrderObserver;
use App\Observers\TripObserver;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if (! class_exists('RmsRamos\Activitylog\ActivitylogPlugin', false)) {
            class_alias(ActivitylogPlugin::class, 'RmsRamos\Activitylog\ActivitylogPlugin');
        }

        if (! class_exists('RmsRamos\Activitylog\Actions\ActivityLogTimelineTableAction', false)) {
            class_alias(ActivityLogTimelineTableAction::class, 'RmsRamos\Activitylog\Actions\ActivityLogTimelineTableAction');
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (str_starts_with(config('app.url', ''), 'https')) {
            URL::forceScheme('https');
        }

        // Plugin date-range nạp utility Tailwind không nằm trong @layer nên đè các class dark: của toàn panel.
        // Bỏ thẻ <link> mặc định và nạp lại file đó trong một cascade layer ưu tiên thấp nhất (trước theme).
        $this->app->booted(function (): void {
            $styles = FilamentAsset::getStyles(['codewithkyrian/filament-date-range']);

            foreach ($styles as $style) {
                $style->loadedOnRequest();
            }

            FilamentView::registerRenderHook(
                PanelsRenderHook::STYLES_BEFORE,
                // Thứ tự layer: trên preflight (base) để giữ style của date picker, dưới utilities để không đè class của trang.
                fn (): string => '<style>@layer theme, base, vendor-date-range, components, utilities;</style>'
                    .collect($styles)
                        ->map(fn ($style): string => '<style>@import url("'.e($style->getHref()).'") layer(vendor-date-range);</style>')
                        ->implode(''),
            );
        });

        Order::observe(OrderObserver::class);
        Trip::observe(TripObserver::class);

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->secure(
                    SecurityScheme::http('bearer')
                );
            });
    }
}
