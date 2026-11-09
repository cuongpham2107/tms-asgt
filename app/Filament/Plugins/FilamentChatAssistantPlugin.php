<?php

namespace App\Filament\Plugins;

use Jason\FilamentChatAssistant\FilamentChatAssistantPlugin as BasePlugin;

class FilamentChatAssistantPlugin extends BasePlugin
{
    public const PERMISSION = 'Use:DataAssistant';

    protected function shouldRender(): bool
    {
        if (! auth()->user()?->can(self::PERMISSION)) {
            return false;
        }

        return parent::shouldRender();
    }
}
