<?php

namespace App\Providers;

use App\Filament\Actions\ActivityLogTimelineTableAction;
use App\Filament\Plugins\ActivitylogPlugin;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripKmReport;
use App\Observers\OrderObserver;
use App\Observers\TripKmReportObserver;
use App\Observers\TripObserver;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        Trip::observe(TripObserver::class);
        TripKmReport::observe(TripKmReportObserver::class);

        set_error_handler(function ($severity, $message, $file) {
            if (str_contains($message, 'touch(): Utime failed') || (str_contains($file, 'BladeCompiler.php') && str_contains($message, 'touch()'))) {
                return true;
            }

            return false;
        }, E_WARNING);

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->secure(
                    SecurityScheme::http('bearer')
                );
            });

        $registerSqliteFunctions = static function ($connection): void {
            if ($connection->getDriverName() === 'sqlite') {
                $pdo = $connection->getPdo();
                if (method_exists($pdo, 'sqliteCreateFunction')) {
                    $pdo->sqliteCreateFunction('LOWER', fn ($str) => $str !== null ? mb_strtolower((string) $str, 'UTF-8') : null);
                    $pdo->sqliteCreateFunction('UPPER', fn ($str) => $str !== null ? mb_strtoupper((string) $str, 'UTF-8') : null);
                }
            }
        };

        Event::listen(
            ConnectionEstablished::class,
            fn (ConnectionEstablished $event) => $registerSqliteFunctions($event->connection)
        );

        try {
            $registerSqliteFunctions(DB::connection());
        } catch (\Throwable) {
            // Ignore connection initialization exceptions during boot
        }
    }
}
