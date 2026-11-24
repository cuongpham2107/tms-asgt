<?php

namespace App\Services\ActivityLog;

use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Bổ sung ngữ cảnh (xóa ở đâu, bằng công cụ/hành động nào) riêng cho các bản ghi Activity Log có event = 'deleted'.
 */
class DeletionContextRecorder
{
    public static function enrich(Activity $activity): void
    {
        if ($activity->event !== 'deleted') {
            return;
        }

        $context = self::captureContext();

        $properties = $activity->properties instanceof Collection
            ? $activity->properties->toArray()
            : (is_array($activity->properties) ? $activity->properties : []);

        if (! array_key_exists('deletion_context', $properties)) {
            $properties['deletion_context'] = $context;
            $activity->properties = collect($properties);
        }

        if (blank($activity->description) || $activity->description === 'deleted') {
            $activity->description = $context['note'];
        }
    }

    /**
     * @return array{
     *     note: string,
     *     channel: string,
     *     trigger: string,
     *     filament_action: ?string,
     *     page_url: ?string,
     *     request_path: ?string,
     *     livewire_component: ?string,
     *     livewire_calls: list<string>,
     *     ip: ?string,
     *     user_agent: ?string,
     *     device: ?string,
     *     app_trace: list<string>,
     * }
     */
    public static function captureContext(): array
    {
        $trace = self::inspectBacktrace();
        $filamentAction = $trace['filament_action'];
        $appTrace = $trace['app_trace'];

        $channel = 'system';
        $pageUrl = null;
        $requestPath = null;
        $livewireComponent = null;
        $livewireCalls = [];
        $ip = null;
        $userAgent = null;
        $device = null;

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            $channel = 'console';
            $argv = $_SERVER['argv'] ?? ['artisan'];
            $requestPath = Str::limit('CLI: '.implode(' ', array_map('strval', $argv)), 160);
        } elseif (app()->bound('request')) {
            /** @var Request $request */
            $request = request();
            $ip = $request->ip();
            $userAgent = filled($request->userAgent())
                ? Str::limit((string) $request->userAgent(), 160)
                : null;
            $device = self::summarizeUserAgent($userAgent);
            $requestPath = $request->method().' /'.ltrim($request->path(), '/');

            if ($request->hasHeader('X-Livewire')) {
                $channel = 'web_livewire';
                $pageUrl = self::extractRelativeUrl($request->header('Referer'));
                [$livewireComponent, $livewireCalls] = self::extractLivewirePayload($request);
            } else {
                $channel = $request->is('api/*') ? 'api' : 'web';
                $pageUrl = self::extractRelativeUrl($request->header('Referer'))
                    ?? urldecode($request->getPathInfo().($request->getQueryString() ? '?'.$request->getQueryString() : ''));
            }
        }

        $trigger = self::resolveTriggerLabel(
            channel: $channel,
            filamentAction: $filamentAction,
            appTrace: $appTrace,
            livewireCalls: $livewireCalls,
            requestPath: $requestPath,
        );

        $noteParts = [$trigger];

        if ($appTrace !== [] && $filamentAction !== null && ! str_contains($trigger, $appTrace[0])) {
            $primaryAppFrame = preg_replace('/\s*\(.*\)$/', '', $appTrace[0]);
            if (is_string($primaryAppFrame) && $primaryAppFrame !== '') {
                $noteParts[] = 'Qua '.$primaryAppFrame;
            }
        }

        if (filled($pageUrl)) {
            $noteParts[] = 'Trang '.Str::limit($pageUrl, 80);
        } elseif (filled($requestPath) && $requestPath !== $trigger) {
            $noteParts[] = Str::limit($requestPath, 80);
        }

        if (filled($ip)) {
            $noteParts[] = 'IP '.$ip;
        }

        return [
            'note' => implode(' · ', $noteParts),
            'channel' => $channel,
            'trigger' => $trigger,
            'filament_action' => $filamentAction,
            'page_url' => $pageUrl,
            'request_path' => $requestPath,
            'livewire_component' => $livewireComponent,
            'livewire_calls' => $livewireCalls,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'device' => $device,
            'app_trace' => $appTrace,
        ];
    }

    /**
     * @return array{filament_action: ?string, app_trace: list<string>}
     */
    private static function inspectBacktrace(): array
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 45);
        $basePath = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $appPrefix = 'app'.DIRECTORY_SEPARATOR;

        $filamentAction = null;
        $appTrace = [];

        $actionClasses = [
            DeleteBulkAction::class,
            ForceDeleteBulkAction::class,
            DeleteAction::class,
            ForceDeleteAction::class,
        ];

        $totalFrames = count($frames);

        for ($i = 0; $i < $totalFrames; $i++) {
            $frame = $frames[$i];
            $class = $frame['class'] ?? null;

            if ($filamentAction === null && is_string($class)) {
                foreach ($actionClasses as $actionClass) {
                    if ($class === $actionClass || is_subclass_of($class, $actionClass)) {
                        $filamentAction = class_basename($class);
                        break;
                    }
                }
            }

            $file = $frame['file'] ?? null;
            if (! is_string($file)) {
                continue;
            }

            $relativeFile = str_starts_with($file, $basePath)
                ? substr($file, strlen($basePath))
                : $file;

            if (! str_starts_with($relativeFile, $appPrefix)) {
                continue;
            }

            // Trong debug_backtrace(), file/line ở frame $i thuộc về hàm gọi ở frame $i + 1.
            $callerFrame = $frames[$i + 1] ?? [];
            $callerClass = $callerFrame['class'] ?? null;
            $callerFunction = $callerFrame['function'] ?? '';

            if ($callerClass === self::class || $callerClass === 'App\\Providers\\AppServiceProvider') {
                continue;
            }

            $line = $frame['line'] ?? null;
            $caller = is_string($callerClass)
                ? class_basename($callerClass).($callerFunction !== '' ? '::'.$callerFunction : '')
                : ($callerFunction !== '' ? $callerFunction : basename($relativeFile));
            $entry = $line !== null
                ? "{$caller} ({$relativeFile}:{$line})"
                : "{$caller} ({$relativeFile})";

            if (! in_array($entry, $appTrace, true)) {
                $appTrace[] = $entry;
            }

            if (count($appTrace) >= 5) {
                break;
            }
        }

        return [
            'filament_action' => $filamentAction,
            'app_trace' => $appTrace,
        ];
    }

    /**
     * @return array{0: ?string, 1: list<string>}
     */
    private static function extractLivewirePayload(Request $request): array
    {
        $firstComponent = $request->input('components.0');
        if (! is_array($firstComponent)) {
            return [null, []];
        }

        $componentName = null;
        $snapshotRaw = $firstComponent['snapshot'] ?? null;
        if (is_string($snapshotRaw)) {
            $decoded = json_decode($snapshotRaw, true);
            if (is_array($decoded)) {
                $componentName = is_string($decoded['memo']['name'] ?? null)
                    ? $decoded['memo']['name']
                    : null;
            }
        }

        $calls = [];
        $rawCalls = $firstComponent['calls'] ?? [];
        if (is_array($rawCalls)) {
            foreach (array_slice($rawCalls, 0, 5) as $call) {
                if (! is_array($call)) {
                    continue;
                }
                $method = $call['method'] ?? null;
                if (! is_string($method) || $method === '') {
                    continue;
                }

                $params = $call['params'] ?? [];
                $summaryParams = is_array($params) && $params !== []
                    ? collect($params)
                        ->take(2)
                        ->map(fn ($p) => is_scalar($p) ? (string) $p : json_encode($p, JSON_UNESCAPED_UNICODE))
                        ->implode(', ')
                    : '';

                $calls[] = $summaryParams !== ''
                    ? Str::limit("{$method}({$summaryParams})", 100)
                    : "{$method}()";
            }
        }

        return [$componentName, $calls];
    }

    private static function extractRelativeUrl(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.urldecode($parts['query']) : '';

        return urldecode($path).$query;
    }

    public static function summarizeUserAgent(?string $userAgent): ?string
    {
        if (blank($userAgent)) {
            return null;
        }

        if (str_contains($userAgent, 'ASGTVnTi') || str_contains($userAgent, 'okhttp/')) {
            $platform = str_contains($userAgent, 'Darwin') || str_contains($userAgent, 'CFNetwork') ? 'iOS' : 'Android';

            return "App Tài xế ({$platform})";
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };

        $os = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        if ($browser !== null && $os !== null) {
            return "{$browser} · {$os}";
        }

        return $browser ?? $os ?? Str::limit($userAgent, 60);
    }

    /**
     * @param  list<string>  $appTrace
     * @param  list<string>  $livewireCalls
     */
    private static function resolveTriggerLabel(
        string $channel,
        ?string $filamentAction,
        array $appTrace,
        array $livewireCalls,
        ?string $requestPath,
    ): string {
        if ($filamentAction !== null) {
            return match ($filamentAction) {
                'DeleteBulkAction' => 'Xóa hàng loạt (DeleteBulkAction)',
                'ForceDeleteBulkAction' => 'Xóa vĩnh viễn hàng loạt (ForceDeleteBulkAction)',
                'DeleteAction' => 'Nút Xóa (DeleteAction)',
                'ForceDeleteAction' => 'Nút Xóa vĩnh viễn (ForceDeleteAction)',
                default => "Action {$filamentAction}",
            };
        }

        if ($appTrace !== []) {
            $first = preg_replace('/\s*\(.*\)$/', '', $appTrace[0]);

            return 'Code '.($first ?: $appTrace[0]);
        }

        if ($livewireCalls !== []) {
            return 'Livewire '.$livewireCalls[0];
        }

        if ($channel === 'console' && filled($requestPath)) {
            return $requestPath;
        }

        if (filled($requestPath)) {
            return $requestPath;
        }

        return 'Hệ thống';
    }
}
