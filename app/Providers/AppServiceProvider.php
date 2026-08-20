<?php

namespace App\Providers;

use App\Filament\Actions\ActivityLogTimelineTableAction;
use App\Filament\Plugins\ActivitylogPlugin;
use App\Models\Order;
use App\Observers\OrderObserver;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
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

        Order::observe(OrderObserver::class);

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->secure(
                    SecurityScheme::http('bearer')
                );
            });
    }
}
