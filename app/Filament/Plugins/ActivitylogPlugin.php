<?php

namespace App\Filament\Plugins;

use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;

class ActivitylogPlugin implements Plugin
{
    protected ?string $navigationGroup = null;

    protected bool|Closure $navigationCountBadge = false;

    protected string|Closure|null $label = null;

    protected string|Closure|null $pluralLabel = null;

    protected ?Closure $translateSubject = null;

    protected int|Closure|null $navigationSort = null;

    protected string|Closure|null $navigationIcon = 'heroicon-o-book-open';

    protected ?Closure $authorize = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        return filament('activitylog');
    }

    public function getId(): string
    {
        return 'activitylog';
    }

    public function navigationGroup(string|Closure|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        return $this->navigationGroup;
    }

    public function navigationCountBadge(bool|Closure $countBadge = true): static
    {
        $this->navigationCountBadge = $countBadge;

        return $this;
    }

    public function getNavigationCountBadge(): bool
    {
        return (bool) value($this->navigationCountBadge);
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): ?string
    {
        return value($this->label);
    }

    public function pluralLabel(string|Closure|null $pluralLabel): static
    {
        $this->pluralLabel = $pluralLabel;

        return $this;
    }

    public function getPluralLabel(): ?string
    {
        return value($this->pluralLabel);
    }

    public function translateSubject(?Closure $translateSubject): static
    {
        $this->translateSubject = $translateSubject;

        return $this;
    }

    public function getTranslateSubjectCallback(): ?Closure
    {
        return $this->translateSubject;
    }

    public function navigationSort(int|Closure|null $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        return value($this->navigationSort);
    }

    public function navigationIcon(string|Closure|null $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    public function getNavigationIcon(): ?string
    {
        return value($this->navigationIcon);
    }

    public function authorize(?Closure $authorize): static
    {
        $this->authorize = $authorize;

        return $this;
    }

    public function isAuthorized(): bool
    {
        if ($this->authorize !== null) {
            return (bool) value($this->authorize);
        }

        $user = auth()->user();
        if (! $user) {
            return false;
        }

        return $user->hasRole('super_admin') || $user->can('view_any_activitylog') || $user->can('ViewAny:Activity');
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            ActivityLogResource::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
