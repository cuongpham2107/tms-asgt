@php
    $renderMarkdown = static fn (string $content): string => app(\App\Ai\AnswerRenderer::class)->render($content);

    // 消息时间：今天显示 H:i，今年显示 m-d H:i，更早显示完整日期
    $renderTime = static function (mixed $value): ?array {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $time = \Illuminate\Support\Carbon::parse($value)
            ->setTimezone(\Filament\Support\Facades\FilamentTimezone::get());

        return [
            'label' => match (true) {
                $time->isToday() => $time->format('H:i'),
                $time->isCurrentYear() => $time->format('m-d H:i'),
                default => $time->format('Y-m-d H:i'),
            },
            'title' => $time->format('Y-m-d H:i:s'),
            'datetime' => $time->toIso8601String(),
        ];
    };

    $defaultWidth = (int) config('chat-assistant.ui.width', 384);
    $defaultHeight = (int) config('chat-assistant.ui.height', 544);
    $allowFullscreen = (bool) config('chat-assistant.ui.fullscreen', true);
    $showNewConversation = (bool) config('chat-assistant.ui.new_conversation', true);
    $toggleIcon = trim((string) config('chat-assistant.ui.toggle_icon'));
    $toggleIconUrl = $toggleIcon !== '' ? asset($toggleIcon) : null;
    $assistantName = (string) ($agentName ?: __('filament-chat-assistant::chat-assistant.title'));
@endphp

<div
    class="tk-assistant"
    x-data="{
        width: Number(localStorage.getItem('tk-assistant-width')) || {{ $defaultWidth }},
        height: Number(localStorage.getItem('tk-assistant-height')) || {{ $defaultHeight }},
        maximized: {{ $allowFullscreen ? "localStorage.getItem('tk-assistant-maximized') === '1'" : 'false' }},
        isMobile: false,
        init() {
            this.isMobile = window.matchMedia('(max-width: 640px)').matches;

            window.matchMedia('(max-width: 640px)').addEventListener('change', (event) => {
                this.isMobile = event.matches;
            });

            @if (! $allowFullscreen)
                this.maximized = false;
                localStorage.removeItem('tk-assistant-maximized');
            @endif

            this.$watch('width', (value) => localStorage.setItem('tk-assistant-width', value));
            this.$watch('height', (value) => localStorage.setItem('tk-assistant-height', value));
        },
        clamp(value, min, max) {
            return Math.min(Math.max(value, min), max);
        },
        startResize(event, axis) {
            event.preventDefault();

            const panel = this.$refs.panel;
            const startX = event.clientX;
            const startY = event.clientY;
            const startWidth = panel.offsetWidth;
            const startHeight = panel.offsetHeight;

            const move = (moveEvent) => {
                if (axis !== 'y') {
                    this.width = this.clamp(startWidth + (startX - moveEvent.clientX), 320, window.innerWidth - 32);
                }

                if (axis !== 'x') {
                    this.height = this.clamp(startHeight + (startY - moveEvent.clientY), 360, window.innerHeight - 120);
                }
            };

            const stop = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', stop);
                document.body.style.userSelect = '';
            };

            document.body.style.userSelect = 'none';
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', stop);
        },
        toggleMaximized() {
            this.maximized = ! this.maximized;
            localStorage.setItem('tk-assistant-maximized', this.maximized ? '1' : '0');
        },
        resetSize() {
            this.maximized = false;
            localStorage.setItem('tk-assistant-maximized', '0');

            this.width = {{ $defaultWidth }};
            this.height = {{ $defaultHeight }};
        },
        askSample(text) {
            this.$wire.set('question', text).then(() => {
                this.$wire.sendMessage();
            });
        },
    }"
    x-on:chat-reply-requested.window="$wire.requestReply()"
>
    <button
        type="button"
        class="tk-assistant__toggle"
        wire:click="toggle"
        x-show="! maximized || ! $wire.open"
        aria-label="{{ $assistantName }}"
        aria-expanded="{{ $open ? 'true' : 'false' }}"
        title="{{ $open ? __('filament-chat-assistant::chat-assistant.close', ['name' => $assistantName]) : __('filament-chat-assistant::chat-assistant.open', ['name' => $assistantName]) }}"
    >
        @if ($open)
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
        @elseif ($toggleIconUrl)
            <img
                src="{{ $toggleIconUrl }}"
                alt="{{ $assistantName }}"
                class="tk-assistant__toggle-icon"
            >
        @else
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8V4H8" />
                <rect x="4" y="8" width="16" height="12" rx="2" stroke-linecap="round" stroke-linejoin="round" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M2 14h2M20 14h2M15 13v2M9 13v2" />
            </svg>
        @endif
    </button>

    @if ($open)
        <section
            x-ref="panel"
            class="tk-assistant__panel"
            :class="{ 'tk-assistant__panel--maximized': maximized }"
            :style="(maximized || isMobile) ? '' : ('width: ' + width + 'px; height: ' + height + 'px')"
            role="dialog"
            aria-label="{{ $assistantName }}"
        >
            <div class="tk-assistant__resize tk-assistant__resize--top" x-on:pointerdown="startResize($event, 'y')" aria-hidden="true"></div>
            <div class="tk-assistant__resize tk-assistant__resize--left" x-on:pointerdown="startResize($event, 'x')" aria-hidden="true"></div>
            <div class="tk-assistant__resize tk-assistant__resize--corner" x-on:pointerdown="startResize($event, 'both')" aria-hidden="true"></div>

            <header class="tk-assistant__header">
                <div class="tk-assistant__title" x-data="{ menu: false }" x-on:click.outside="menu = false">
                    @if (count($agents) > 1)
                        <button
                            type="button"
                            class="tk-assistant__agent-toggle"
                            x-on:click="menu = ! menu"
                            aria-haspopup="listbox"
                            :aria-expanded="menu"
                            title="{{ __('filament-chat-assistant::chat-assistant.switch_agent') }}"
                        >
                            @if ($agentAvatar)
                                <img src="{{ $agentAvatar }}" alt="{{ $assistantName }}" class="tk-assistant__avatar">
                            @else
                                <span class="tk-assistant__avatar tk-assistant__avatar--fallback">{{ mb_substr($assistantName, 0, 1) }}</span>
                            @endif

                            <span class="tk-assistant__agent-name">{{ $assistantName }}</span>

                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                            </svg>
                        </button>

                        <div class="tk-assistant__agent-menu" x-show="menu" x-cloak role="listbox">
                            @foreach ($agents as $index => $agent)
                                <button
                                    type="button"
                                    role="option"
                                    class="tk-assistant__agent-option {{ $agent['class'] === $agentClass ? 'tk-assistant__agent-option--active' : '' }}"
                                    wire:click="selectAgent({{ $index }})"
                                    x-on:click="menu = false"
                                    aria-selected="{{ $agent['class'] === $agentClass ? 'true' : 'false' }}"
                                >
                                    @if ($agent['avatar'])
                                        <img src="{{ $agent['avatar'] }}" alt="{{ $agent['name'] }}" class="tk-assistant__avatar">
                                    @else
                                        <span class="tk-assistant__avatar tk-assistant__avatar--fallback">{{ mb_substr($agent['name'], 0, 1) }}</span>
                                    @endif

                                    <span>{{ $agent['name'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    @else
                        @if ($agentAvatar)
                            <img src="{{ $agentAvatar }}" alt="{{ $assistantName }}" class="tk-assistant__avatar">
                        @else
                            <span class="tk-assistant__avatar tk-assistant__avatar--fallback">{{ mb_substr($assistantName, 0, 1) }}</span>
                        @endif

                        <span>{{ $assistantName }}</span>
                    @endif

                    <span
                        class="tk-assistant__status{{ $hasError ? ' tk-assistant__status--error' : '' }}"
                        wire:loading.class="tk-assistant__status--busy"
                        wire:target="requestReply"
                        role="status"
                        title="{{ $hasError ? __('filament-chat-assistant::chat-assistant.status_error') : __('filament-chat-assistant::chat-assistant.status_idle') }}"
                        aria-label="{{ $hasError ? __('filament-chat-assistant::chat-assistant.status_error') : __('filament-chat-assistant::chat-assistant.status_idle') }}"
                    ></span>
                </div>

                <div class="tk-assistant__actions">
                    @if ($allowFullscreen)
                        <button
                            type="button"
                            class="tk-assistant__icon"
                            x-show="! maximized"
                            x-on:click="toggleMaximized()"
                            title="{{ __('filament-chat-assistant::chat-assistant.maximize') }}"
                            aria-label="{{ __('filament-chat-assistant::chat-assistant.maximize') }}"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" />
                            </svg>
                        </button>

                        <button
                            type="button"
                            class="tk-assistant__icon"
                            x-show="maximized"
                            x-on:click="toggleMaximized()"
                            title="{{ __('filament-chat-assistant::chat-assistant.restore') }}"
                            aria-label="{{ __('filament-chat-assistant::chat-assistant.restore') }}"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 9V4.5M9 9H4.5M9 9 3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5 5.25 5.25" />
                            </svg>
                        </button>
                    @endif

                    <button
                        type="button"
                        class="tk-assistant__icon"
                        x-on:click="resetSize()"
                        title="{{ __('filament-chat-assistant::chat-assistant.reset_size') }}"
                        aria-label="{{ __('filament-chat-assistant::chat-assistant.reset_size') }}"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
                        </svg>
                    </button>

                    @if ($showNewConversation)
                        <button
                            type="button"
                            class="tk-assistant__icon"
                            wire:click="resetConversation"
                            title="{{ __('filament-chat-assistant::chat-assistant.new_conversation') }}"
                            aria-label="{{ __('filament-chat-assistant::chat-assistant.new_conversation') }}"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356m0 4.992-3.181-3.183a8.25 8.25 0 0 0-13.803 3.7M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7" />
                            </svg>
                        </button>
                    @endif

                    <button
                        type="button"
                        class="tk-assistant__icon"
                        wire:click="toggle"
                        title="{{ __('filament-chat-assistant::chat-assistant.collapse') }}"
                        aria-label="{{ __('filament-chat-assistant::chat-assistant.collapse') }}"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14" />
                        </svg>
                    </button>
                </div>
            </header>

            @if (! $agentConfigured)
                <div class="tk-assistant__notice">
                    {{ __('filament-chat-assistant::chat-assistant.agent_missing') }}
                </div>
            @endif

            <div
                class="tk-assistant__messages-wrap"
                x-data="{ atBottom: true }"
                x-init="
                    const container = $el.querySelector('.tk-assistant__messages');
                    const button = $el.querySelector('.tk-assistant__scroll-bottom');
                    const threshold = 32;

                    const isNearBottom = () => container.scrollHeight - container.scrollTop - container.clientHeight <= threshold;
                    const sync = () => { atBottom = isNearBottom(); };
                    const scrollToBottom = () => {
                        const behavior = container.style.scrollBehavior;

                        container.style.scrollBehavior = 'auto';
                        container.scrollTop = container.scrollHeight;
                        container.style.scrollBehavior = behavior;
                    };

                    container.addEventListener('scroll', sync, { passive: true });

                    new MutationObserver(() => {
                        if (atBottom) {
                            scrollToBottom();
                        }
                    }).observe(container, {
                        childList: true,
                        subtree: true,
                        characterData: true,
                    });

                    button.addEventListener('click', () => {
                        atBottom = true;
                        scrollToBottom();
                    });

                    scrollToBottom();
                    sync();
                "
                x-on:livewire:navigating.window="
                    const container = $refs.messages;

                    container.dataset.scrollTop = container.scrollTop;
                "
                x-on:livewire:navigated.window="
                    const container = $refs.messages;
                    const scrollTop = Number(container.dataset.scrollTop);

                    if (! Number.isNaN(scrollTop)) {
                        container.style.scrollBehavior = 'auto';
                        container.scrollTop = scrollTop;
                        container.style.scrollBehavior = '';

                        atBottom = container.scrollHeight - container.scrollTop - container.clientHeight <= 32;
                    }
                "
                x-on:chat-reply-requested.window="
                    atBottom = true;

                    const container = $refs.messages;
                    const behavior = container.style.scrollBehavior;

                    container.style.scrollBehavior = 'auto';
                    container.scrollTop = container.scrollHeight;
                    container.style.scrollBehavior = behavior;
                "
            >
                <div
                    class="tk-assistant__messages"
                    x-ref="messages"
                >
                @forelse ($messages as $index => $message)
                    <div
                        class="tk-assistant__row tk-assistant__row--{{ $message['role'] }}"
                        wire:key="chat-message-{{ $index }}"
                    >
                        @if ($message['role'] === 'assistant')
                            @if ($agentAvatar)
                                <img src="{{ $agentAvatar }}" alt="{{ $assistantName }}" class="tk-assistant__message-avatar">
                            @else
                                <span class="tk-assistant__message-avatar tk-assistant__avatar--fallback">{{ mb_substr($assistantName, 0, 1) }}</span>
                            @endif
                        @endif

                        <div class="tk-assistant__message">
                            <div class="tk-assistant__message-name">
                                <span>{{ $message['role'] === 'assistant' ? $assistantName : $userName }}</span>

                                @if ($time = $renderTime($message['created_at'] ?? null))
                                    <time class="tk-assistant__message-time" datetime="{{ $time['datetime'] }}" title="{{ $time['title'] }}">{{ $time['label'] }}</time>
                                @endif
                            </div>

                            <div class="tk-assistant__bubble">
                                @if ($message['role'] === 'assistant')
                                    @php
                                        ['body' => $body, 'suggestions' => $suggestions] = \App\Ai\Agents\DataAssistant::splitSuggestions($message['content']);
                                    @endphp
                                    {!! $renderMarkdown($body) !!}

                                    @if (!empty($suggestions) && $loop->last && !$replying)
                                        <div class="da-suggestions">
                                            <span class="da-suggestions-label">Gợi ý hỏi tiếp:</span>
                                            <div class="da-suggestions-chips">
                                                @foreach ($suggestions as $suggestion)
                                                    <button
                                                        type="button"
                                                        class="da-suggestion-chip"
                                                        x-on:click="askSample(@js($suggestion))"
                                                    >
                                                        {{ $suggestion }}
                                                    </button>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                @else
                                    {!! nl2br(e($message['content'])) !!}
                                @endif
                            </div>

                            <div class="tk-assistant__message-actions">
                                @if ($message['role'] === 'user')
                                    <button
                                        type="button"
                                        class="tk-assistant__edit"
                                        wire:click="editMessage({{ $index }})"
                                        title="{{ __('filament-chat-assistant::chat-assistant.edit') }}"
                                        aria-label="{{ __('filament-chat-assistant::chat-assistant.edit') }}"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 7.125v11.25A2.625 2.625 0 0 1 16.875 21H4.125A2.625 2.625 0 0 1 1.5 18.375V5.625A2.625 2.625 0 0 1 4.125 3h11.25" />
                                        </svg>

                                        <span>{{ __('filament-chat-assistant::chat-assistant.edit') }}</span>
                                    </button>
                                @endif

                                <button
                                    type="button"
                                    class="tk-assistant__copy"
                                    data-copy="{{ $message['content'] }}"
                                    data-copy-label="{{ __('filament-chat-assistant::chat-assistant.copy') }}"
                                    data-copied-label="{{ __('filament-chat-assistant::chat-assistant.copied') }}"
                                    x-data="{ copied: false }"
                                    x-on:click="
                                        const text = $el.dataset.copy;
                                        const done = () => {
                                            copied = true;
                                            setTimeout(() => copied = false, 1500);
                                        };
                                        const fallback = () => {
                                            const area = document.createElement('textarea');
                                            area.value = text;
                                            area.style.position = 'fixed';
                                            area.style.opacity = '0';
                                            document.body.appendChild(area);
                                            area.select();
                                            document.execCommand('copy');
                                            document.body.removeChild(area);
                                            done();
                                        };

                                        if (navigator.clipboard && window.isSecureContext) {
                                            navigator.clipboard.writeText(text).then(done).catch(fallback);
                                        } else {
                                            fallback();
                                        }
                                    "
                                    :title="copied ? $root.dataset.copiedLabel : $root.dataset.copyLabel"
                                    :aria-label="copied ? $root.dataset.copiedLabel : $root.dataset.copyLabel"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                        <rect x="9" y="9" width="11" height="11" rx="2" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 15V5a2 2 0 0 1 2-2h8" />
                                    </svg>

                                    <span x-text="copied ? $root.dataset.copiedLabel : $root.dataset.copyLabel"></span>
                                </button>

                                @if ($message['role'] === 'assistant' && $loop->last)
                                    <button
                                        type="button"
                                        class="tk-assistant__regenerate"
                                        wire:click="regenerateReply"
                                        title="{{ __('filament-chat-assistant::chat-assistant.regenerate') }}"
                                        aria-label="{{ __('filament-chat-assistant::chat-assistant.regenerate') }}"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15" />
                                        </svg>

                                        <span>{{ __('filament-chat-assistant::chat-assistant.regenerate') }}</span>
                                    </button>
                                @endif

                                <button
                                    type="button"
                                    class="tk-assistant__retract"
                                    wire:click="retractMessage({{ $index }})"
                                    wire:confirm="{{ __('filament-chat-assistant::chat-assistant.retract_confirm') }}"
                                    title="{{ __('filament-chat-assistant::chat-assistant.retract') }}"
                                    aria-label="{{ __('filament-chat-assistant::chat-assistant.retract') }}"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                    </svg>

                                    <span>{{ __('filament-chat-assistant::chat-assistant.retract') }}</span>
                                </button>
                            </div>
                        </div>

                        @if ($message['role'] === 'user')
                            @if ($userAvatar)
                                <img src="{{ $userAvatar }}" alt="{{ $userName }}" class="tk-assistant__message-avatar">
                            @else
                                <span class="tk-assistant__message-avatar tk-assistant__avatar--fallback">{{ mb_substr($userName, 0, 1) }}</span>
                            @endif
                        @endif
                    </div>
                @empty
                    <div class="da-welcome">
                        <div class="da-welcome-header">
                            <div class="da-welcome-avatar">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="width: 1.5rem; height: 1.5rem;">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" />
                                </svg>
                            </div>
                            <h3 class="da-welcome-title">Xin chào! Tôi là Trợ lý Dữ liệu TMS.</h3>
                            <p class="da-welcome-desc">Tra cứu số liệu vận hành, đơn hàng, chuyến xe, cước phí và ca trực nhanh chóng.</p>
                        </div>

                        <div class="da-welcome-section-title">Câu hỏi gợi ý nhanh:</div>
                        <div class="da-samples-grid">
                            @foreach (\App\Ai\Agents\DataAssistant::SAMPLE_QUESTIONS as $sample)
                                <button
                                    type="button"
                                    class="da-sample-card"
                                    x-on:click="askSample(@js($sample['question']))"
                                >
                                    <span class="da-sample-topic">{{ $sample['topic'] }}</span>
                                    <span class="da-sample-text">{{ $sample['question'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforelse

                {{-- 等待与流式输出共用一行：头像、名称始终可见，首片到达后只隐藏 "..." --}}
                <div
                    class="tk-assistant__row tk-assistant__row--assistant tk-assistant__typing"
                    wire:loading.flex
                    wire:target="requestReply"
                >
                    @if ($agentAvatar)
                        <img src="{{ $agentAvatar }}" alt="{{ $assistantName }}" class="tk-assistant__message-avatar">
                    @else
                        <span class="tk-assistant__message-avatar tk-assistant__avatar--fallback">{{ mb_substr($assistantName, 0, 1) }}</span>
                    @endif

                    <div class="tk-assistant__message">
                        <div class="tk-assistant__message-name">{{ $assistantName }}</div>

                        <div class="tk-assistant__bubble tk-assistant__typing-bubble" aria-label="{{ __('filament-chat-assistant::chat-assistant.thinking') }}">
                            <span></span><span></span><span></span>
                        </div>

                        {{-- 流式输出目标：结束后由最终渲染替换 --}}
                        <div class="tk-assistant__bubble tk-assistant__bubble--stream" wire:stream="chat-stream"></div>
                    </div>
                </div>
                </div>

                <button
                    type="button"
                    class="tk-assistant__scroll-bottom"
                    x-show="! atBottom"
                    x-cloak
                    title="{{ __('filament-chat-assistant::chat-assistant.scroll_to_bottom') }}"
                    aria-label="{{ __('filament-chat-assistant::chat-assistant.scroll_to_bottom') }}"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 13.5 12 21m0 0-7.5-7.5M12 21V3" />
                    </svg>
                </button>
            </div>

            <form class="tk-assistant__composer" wire:submit="sendMessage">
                <div class="tk-assistant__field">
                    <textarea
                        class="tk-assistant__input"
                        wire:model="question"
                        rows="1"
                        maxlength="2000"
                        placeholder="{{ __('filament-chat-assistant::chat-assistant.placeholder') }}"
                        aria-label="{{ __('filament-chat-assistant::chat-assistant.message_aria') }}"
                        @disabled(! $agentConfigured)
                        x-data="{
                            resize() {
                                const el = $el;
                                const borders = el.offsetHeight - el.clientHeight;

                                el.style.height = 'auto';

                                const height = el.scrollHeight + borders;

                                el.style.height = Math.min(height, 120) + 'px';
                                el.style.overflowY = height > 120 ? 'auto' : 'hidden';
                            },
                        }"
                        x-init="resize()"
                        x-on:input="resize()"
                        x-on:chat-reply-requested.window="resize()"
                        x-on:chat-edit-message.window="$nextTick(() => { resize(); $el.focus(); })"
                        x-on:chat-input-cleared.window="$nextTick(() => { resize(); $el.focus(); })"
                        x-on:keydown.enter="if (! $event.shiftKey && ! $event.isComposing) { $event.preventDefault(); $wire.sendMessage(); }"
                    ></textarea>

                    <button
                        type="button"
                        class="tk-assistant__clear"
                        wire:click="clearQuestion"
                        title="{{ __('filament-chat-assistant::chat-assistant.clear') }}"
                        aria-label="{{ __('filament-chat-assistant::chat-assistant.clear') }}"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>

                    <button
                        type="submit"
                        class="tk-assistant__send"
                        title="{{ __('filament-chat-assistant::chat-assistant.send') }}"
                        aria-label="{{ __('filament-chat-assistant::chat-assistant.send') }}"
                        @disabled(! $agentConfigured)
                        wire:loading.attr="disabled"
                        wire:target="sendMessage,requestReply"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                        </svg>
                    </button>
                </div>
            </form>

            @error('question')
                <p class="tk-assistant__error">{{ $message }}</p>
            @enderror
        </section>
    @endif

    <style>
        :root {
            --da-accent: #008fd5;
            --da-accent-soft: rgba(0, 143, 213, .12);
            --da-surface: #ffffff;
            --da-bot: #f1f5f9;
            --da-line: #e2e8f0;
            --da-muted: #64748b;
            --da-ink: #0f172a;
        }
        .dark {
            --da-accent: #38bdf8;
            --da-accent-soft: rgba(56, 189, 248, .15);
            --da-surface: #0f172a;
            --da-bot: #1e293b;
            --da-line: #334155;
            --da-muted: #94a3b8;
            --da-ink: #f8fafc;
        }

        /* Welcome & Quick Samples */
        .da-welcome {
            display: flex;
            flex-direction: column;
            gap: 0.875rem;
            padding: 1.25rem 0.5rem;
        }
        .da-welcome-header {
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.35rem;
        }
        .da-welcome-avatar {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.75rem;
            height: 2.75rem;
            border-radius: 9999px;
            background: var(--da-accent-soft);
            color: var(--da-accent);
            margin-bottom: 0.25rem;
        }
        .da-welcome-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: inherit;
            margin: 0;
        }
        .da-welcome-desc {
            font-size: 0.8125rem;
            color: var(--da-muted);
            margin: 0;
            max-width: 24rem;
            line-height: 1.4;
        }
        .da-welcome-section-title {
            font-size: 0.6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--da-muted);
            margin-top: 0.25rem;
        }
        .da-samples-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.5rem;
        }
        @media (max-width: 500px) {
            .da-samples-grid {
                grid-template-columns: 1fr;
            }
        }
        .da-sample-card {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.2rem;
            padding: 0.625rem 0.75rem;
            border-radius: 0.625rem;
            border: 1px solid var(--da-line);
            background: rgba(127, 127, 127, 0.04);
            text-align: left;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .da-sample-card:hover {
            border-color: var(--da-accent);
            background: var(--da-accent-soft);
            transform: translateY(-1px);
        }
        .da-sample-topic {
            font-size: 0.6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--da-accent);
        }
        .da-sample-text {
            font-size: 0.75rem;
            line-height: 1.35;
            color: inherit;
            opacity: 0.85;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        /* Follow-up suggestions */
        .da-suggestions {
            margin-top: 0.75rem;
            padding-top: 0.625rem;
            border-top: 1px dashed var(--da-line);
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
        }
        .da-suggestions-label {
            font-size: 0.6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: var(--da-muted);
        }
        .da-suggestions-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 0.375rem;
        }
        .da-suggestion-chip {
            display: inline-flex;
            align-items: center;
            padding: 0.3rem 0.625rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            border: 1px solid var(--da-line);
            background: var(--da-bot);
            color: inherit;
            cursor: pointer;
            transition: all 0.15s ease;
            text-align: left;
        }
        .da-suggestion-chip:hover {
            border-color: var(--da-accent);
            background: var(--da-accent-soft);
            color: var(--da-accent);
            transform: translateY(-1px);
        }

        /* Data formatting: Tables & Chips & Badges */
        .da-table { overflow-x: auto; border: 1px solid var(--da-line); border-radius: 10px; margin: 8px 0; }
        .tk-assistant__bubble table { width: 100%; border-collapse: collapse; font-size: 12.5px; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .tk-assistant__bubble thead th { position: sticky; top: 0; background: var(--da-bot); padding: 7px 10px; text-align: left; font-size: 11px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; color: var(--da-muted); }
        .tk-assistant__bubble td { padding: 6px 10px; border-top: 1px solid var(--da-line); }
        .tk-assistant__bubble tbody tr:nth-child(even) td { background: rgba(148, 163, 184, .06); }
        .tk-assistant__bubble tbody tr:hover td { background: var(--da-accent-soft); }
        .tk-assistant__bubble td.is-num, .tk-assistant__bubble th.is-num { text-align: right; }
        .tk-assistant__bubble td.is-num { font-weight: 600; }
        .da-chip-link { display: inline-block; padding: 1px 7px; border-radius: 6px; background: var(--da-accent-soft); color: var(--da-accent); font-weight: 600; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; text-decoration: none; transition: background .15s, color .15s; }
        .da-chip-link:hover { background: var(--da-accent); color: #fff; }
        .da-chart { margin: 10px 0 0; padding: 12px 12px 6px; border: 1px solid var(--da-line); border-radius: 12px; background: var(--da-surface); }
        .da-chart figcaption { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: 2px 10px; margin-bottom: 6px; font-size: 12.5px; font-weight: 700; }
        .da-chart-meta { font-size: 11.5px; font-weight: 500; color: var(--da-muted); }
        .da-plot { display: block; width: 100%; height: auto; color: var(--da-accent); overflow: visible; font-variant-numeric: tabular-nums; }
        .da-plot .da-gridline { stroke: var(--da-line); stroke-width: 1; stroke-dasharray: 3 4; }
        .da-plot .da-baseline { stroke: #cbd5e1; stroke-width: 1; }
        .dark .da-plot .da-baseline { stroke: #334155; }
        .da-plot .da-axis { font-size: 10px; fill: var(--da-muted); }
        .da-plot .da-curve { filter: drop-shadow(0 2px 3px rgba(0, 143, 213, .25)); }
        .da-plot .da-dot { fill: var(--da-surface); stroke: currentColor; stroke-width: 2; opacity: 0; transition: opacity .15s; cursor: default; }
        .da-plot .da-dot:hover, .da-plot .da-dot.is-marked { opacity: 1; }
        .da-plot .da-dot.is-marked { fill: currentColor; stroke: var(--da-surface); }
        .da-plot .da-peak { font-size: 10.5px; font-weight: 700; fill: var(--da-ink); paint-order: stroke; stroke: var(--da-surface); stroke-width: 3px; }
        .dark .da-plot .da-peak, .dark .da-plot .da-col-value { fill: #f8fafc; }
        .da-plot .da-col path { opacity: .78; transition: opacity .15s; }
        .da-plot .da-col.is-peak path, .da-plot .da-col:hover path { opacity: 1; }
        .da-plot .da-col-value { font-size: 10px; font-weight: 700; fill: var(--da-ink); }
        .da-bars { display: grid; gap: 7px; padding: 2px 0 6px; }
        .da-bar-row { display: grid; grid-template-columns: 18px minmax(60px, 28%) 1fr auto; align-items: center; gap: 8px; font-size: 12.5px; }
        .da-bar-rank { display: grid; place-items: center; width: 18px; height: 18px; border-radius: 6px; background: var(--da-bot); color: var(--da-muted); font-size: 10.5px; font-weight: 700; }
        .da-bar-row:nth-child(-n+3) .da-bar-rank { background: var(--da-accent-soft); color: var(--da-accent); }
        .da-bar-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; }
        .da-bar-track { height: 8px; border-radius: 999px; background: var(--da-bot); overflow: hidden; }
        .da-bar-fill { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, rgba(0, 143, 213, .55), var(--da-accent)); transform-origin: left; animation: da-grow .6s cubic-bezier(.16, 1, .3, 1); }
        .da-bar-row:first-child .da-bar-fill { background: linear-gradient(90deg, var(--da-accent), #1e3a5f); }
        .da-bar-value { min-width: 52px; text-align: right; font-weight: 700; font-variant-numeric: tabular-nums; }
        @keyframes da-grow { from { transform: scaleX(0); } to { transform: scaleX(1); } }
        .da-badge { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 600; }
        .da-badge.is-success { background: #dcfce7; color: #15803d; }
        .da-badge.is-warning { background: #fef3c7; color: #b45309; }
        .da-badge.is-danger { background: #fee2e2; color: #b91c1c; }
        .dark .da-badge.is-success { background: rgba(34, 197, 94, .15); color: #86efac; }
        .dark .da-badge.is-warning { background: rgba(245, 158, 11, .15); color: #fcd34d; }
        .dark .da-badge.is-danger { background: rgba(239, 68, 68, .15); color: #fca5a5; }

        .tk-assistant {
            position: fixed;
            right: 1.25rem;
            bottom: 1.25rem;
            z-index: 40;
            font-size: .875rem;
        }

        .tk-assistant__toggle {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 3rem;
            height: 3rem;
            margin-left: auto;
            border: 0;
            border-radius: 9999px;
            background: var(--primary-600, #4f46e5);
            color: #fff;
            box-shadow: 0 8px 24px rgba(0, 0, 0, .24);
            cursor: pointer;
            transition: transform .15s ease;
        }

        .tk-assistant__toggle:hover {
            transform: translateY(-1px);
        }

        .tk-assistant__toggle svg {
            width: 1.4rem;
            height: 1.4rem;
        }

        .tk-assistant__toggle-icon {
            width: 1.75rem;
            height: 1.75rem;
            object-fit: contain;
        }

        .tk-assistant__panel {
            position: fixed;
            right: 1.25rem;
            bottom: 4.75rem;
            display: flex;
            flex-direction: column;
            width: min(24rem, calc(100vw - 2.5rem));
            height: min(34rem, calc(100vh - 8rem));
            max-width: calc(100vw - 1rem);
            max-height: calc(100vh - 1rem);
            overflow: hidden;
            border: 1px solid rgba(127, 127, 127, .22);
            border-radius: 1rem;
            background: #fff;
            color: #18181b;
            box-shadow: 0 20px 45px rgba(0, 0, 0, .22);
        }

        .tk-assistant__panel--maximized {
            top: .75rem;
            right: .75rem;
            bottom: .75rem;
            left: .75rem;
            width: auto;
            height: auto;
            max-width: none;
            max-height: none;
        }

        .tk-assistant__resize {
            position: absolute;
            z-index: 1;
            touch-action: none;
        }

        .tk-assistant__resize--top {
            top: 0;
            right: 14px;
            left: 14px;
            height: 8px;
            cursor: ns-resize;
        }

        .tk-assistant__resize--left {
            top: 14px;
            bottom: 14px;
            left: 0;
            width: 8px;
            cursor: ew-resize;
        }

        .tk-assistant__resize--corner {
            top: 0;
            left: 0;
            width: 16px;
            height: 16px;
            cursor: nwse-resize;
        }

        .dark .tk-assistant__panel {
            border-color: rgba(255, 255, 255, .12);
            background: #18181b;
            color: #f4f4f5;
        }

        .tk-assistant__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: .75rem 1rem;
            border-bottom: 1px solid rgba(127, 127, 127, .18);
        }

        .tk-assistant__title {
            position: relative;
            display: flex;
            align-items: center;
            gap: .5rem;
            min-width: 0;
            font-weight: 600;
        }

        .tk-assistant__agent-toggle {
            display: flex;
            align-items: center;
            gap: .4rem;
            max-width: 14rem;
            min-width: 0;
            padding: .15rem .35rem;
            border: 0;
            border-radius: .5rem;
            background: transparent;
            color: inherit;
            font: inherit;
            cursor: pointer;
        }

        .tk-assistant__agent-toggle:hover {
            background: rgba(127, 127, 127, .14);
        }

        .tk-assistant__agent-toggle svg {
            width: .85rem;
            height: .85rem;
            flex: none;
            opacity: .6;
        }

        .tk-assistant__agent-name {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .tk-assistant__agent-menu {
            position: absolute;
            top: calc(100% + .35rem);
            left: 0;
            z-index: 20;
            display: flex;
            flex-direction: column;
            gap: .1rem;
            min-width: 12rem;
            max-width: 18rem;
            padding: .25rem;
            border: 1px solid rgba(127, 127, 127, .22);
            border-radius: .6rem;
            background: #fff;
            box-shadow: 0 12px 28px rgba(0, 0, 0, .18);
        }

        .dark .tk-assistant__agent-menu {
            border-color: rgba(255, 255, 255, .12);
            background: #18181b;
        }

        .tk-assistant__agent-menu[x-cloak] {
            display: none !important;
        }

        .tk-assistant__agent-option {
            display: flex;
            align-items: center;
            gap: .45rem;
            width: 100%;
            padding: .35rem .45rem;
            border: 0;
            border-radius: .45rem;
            background: transparent;
            color: inherit;
            font: inherit;
            font-weight: 500;
            text-align: left;
            cursor: pointer;
        }

        .tk-assistant__agent-option:hover {
            background: rgba(127, 127, 127, .14);
        }

        .tk-assistant__agent-option--active {
            background: rgba(79, 70, 229, .12);
        }

        .tk-assistant__status {
            width: .5rem;
            height: .5rem;
            border-radius: 9999px;
            background: #22c55e;
            transition: background-color .15s ease;
        }

        /* 上次回复失败 */
        .tk-assistant__status--error {
            background: #ef4444;
        }

        /* 正在生成（wire:loading 客户端切换），放在 error 之后以优先生效 */
        .tk-assistant__status--busy {
            background: #f59e0b;
            animation: tk-assistant-status-pulse 1s infinite ease-in-out;
        }

        @keyframes tk-assistant-status-pulse {
            0%, 100% {
                opacity: 1;
            }

            50% {
                opacity: .3;
            }
        }

        .tk-assistant__avatar {
            width: 1.5rem;
            height: 1.5rem;
            flex: none;
            border-radius: 9999px;
            object-fit: cover;
        }

        .tk-assistant__avatar--fallback {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-600, #4f46e5);
            color: #fff;
            font-size: .65rem;
            font-weight: 600;
        }

        .tk-assistant__message-avatar {
            width: 1.6rem;
            height: 1.6rem;
            flex: none;
            border-radius: 9999px;
            object-fit: cover;
        }

        .tk-assistant__actions {
            display: flex;
            align-items: center;
            gap: .25rem;
        }

        .tk-assistant__icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 1.75rem;
            height: 1.75rem;
            border: 0;
            border-radius: .5rem;
            background: transparent;
            color: inherit;
            opacity: .7;
            cursor: pointer;
        }

        .tk-assistant__icon:hover {
            background: rgba(127, 127, 127, .14);
            opacity: 1;
        }

        .tk-assistant__icon svg {
            width: 1rem;
            height: 1rem;
        }

        .tk-assistant__notice {
            margin: .75rem 1rem 0;
            padding: .5rem .7rem;
            border: 1px solid rgba(245, 158, 11, .35);
            border-radius: .5rem;
            background: rgba(245, 158, 11, .12);
            color: #b45309;
            font-size: .75rem;
            line-height: 1.5;
        }

        .dark .tk-assistant__notice {
            color: #fbbf24;
            border-color: rgba(251, 191, 36, .35);
            background: rgba(251, 191, 36, .12);
        }

        .tk-assistant__messages-wrap {
            position: relative;
            display: flex;
            flex: 1;
            flex-direction: column;
            min-height: 0;
        }

        .tk-assistant__messages {
            display: flex;
            flex: 1;
            flex-direction: column;
            gap: .625rem;
            min-height: 0;
            overflow-y: auto;
            padding: .875rem 1rem;
            scroll-behavior: smooth;
        }

        .tk-assistant__scroll-bottom {
            position: absolute;
            right: .75rem;
            bottom: .75rem;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 1.9rem;
            height: 1.9rem;
            border: 1px solid rgba(127, 127, 127, .25);
            border-radius: 9999px;
            background: #fff;
            color: #18181b;
            box-shadow: 0 6px 16px rgba(0, 0, 0, .18);
            cursor: pointer;
            transition: opacity .15s ease, transform .15s ease;
        }

        .dark .tk-assistant__scroll-bottom {
            border-color: rgba(255, 255, 255, .14);
            background: #27272a;
            color: #f4f4f5;
        }

        .tk-assistant__scroll-bottom:hover {
            transform: translateY(-1px);
        }

        .tk-assistant__scroll-bottom svg {
            width: 1rem;
            height: 1rem;
        }

        .tk-assistant__scroll-bottom[x-cloak] {
            display: none !important;
        }

        .tk-assistant__row {
            display: flex;
            align-items: flex-start;
            gap: .375rem;
        }

        .tk-assistant__row--user {
            justify-content: flex-end;
        }

        .tk-assistant__message {
            display: flex;
            flex-direction: column;
            gap: .2rem;
            max-width: 85%;
            min-width: 0;
        }

        .tk-assistant__row--user .tk-assistant__message {
            align-items: flex-end;
        }

        .tk-assistant__message-name {
            display: flex;
            align-items: baseline;
            gap: .35rem;
            padding-inline: .2rem;
            font-size: .7rem;
            line-height: 1.4;
            opacity: .6;
        }

        .tk-assistant__message-time {
            font-size: .65rem;
            opacity: .8;
            font-variant-numeric: tabular-nums;
        }

        .tk-assistant__message-actions {
            display: flex;
            gap: .15rem;
            padding-inline: .2rem;
        }

        .tk-assistant__copy,
        .tk-assistant__edit,
        .tk-assistant__retract,
        .tk-assistant__regenerate {
            display: inline-flex;
            align-items: center;
            gap: .25rem;
            padding: .15rem .35rem;
            border: 0;
            border-radius: .35rem;
            background: transparent;
            color: inherit;
            font-size: .65rem;
            line-height: 1.4;
            opacity: .45;
            cursor: pointer;
            transition: opacity .15s ease, background-color .15s ease;
        }

        .tk-assistant__copy:hover,
        .tk-assistant__copy:focus-visible,
        .tk-assistant__edit:hover,
        .tk-assistant__edit:focus-visible,
        .tk-assistant__retract:hover,
        .tk-assistant__retract:focus-visible,
        .tk-assistant__regenerate:hover,
        .tk-assistant__regenerate:focus-visible {
            background: rgba(127, 127, 127, .14);
            opacity: 1;
            outline: none;
        }

        .tk-assistant__copy svg,
        .tk-assistant__edit svg,
        .tk-assistant__retract svg,
        .tk-assistant__regenerate svg {
            width: .8rem;
            height: .8rem;
        }

        .tk-assistant__bubble {
            max-width: 100%;
            padding: .55rem .8rem;
            border-radius: 1rem;
            border-top-left-radius: .25rem;
            background: rgba(127, 127, 127, .14);
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        .tk-assistant__row--user .tk-assistant__bubble {
            border-top-left-radius: 1rem;
            border-top-right-radius: .25rem;
            background: var(--primary-600, #4f46e5);
            color: #fff;
        }

        .tk-assistant__bubble > :first-child {
            margin-top: 0;
        }

        .tk-assistant__bubble > :last-child {
            margin-bottom: 0;
        }

        .tk-assistant__bubble p {
            margin: 0 0 .5rem;
        }

        .tk-assistant__bubble ul,
        .tk-assistant__bubble ol {
            margin: .25rem 0 .5rem;
            padding-left: 1.25rem;
        }

        .tk-assistant__bubble ul {
            list-style: disc;
        }

        .tk-assistant__bubble ol {
            list-style: decimal;
        }

        .tk-assistant__bubble li + li {
            margin-top: .15rem;
        }

        .tk-assistant__bubble h1,
        .tk-assistant__bubble h2,
        .tk-assistant__bubble h3,
        .tk-assistant__bubble h4 {
            margin: .6rem 0 .3rem;
            font-size: .9375rem;
            font-weight: 600;
        }

        .tk-assistant__bubble code {
            padding: .1rem .3rem;
            border-radius: .25rem;
            background: rgba(127, 127, 127, .22);
            font-size: .8125rem;
        }

        .tk-assistant__bubble pre {
            margin: .5rem 0;
            padding: .5rem .6rem;
            overflow-x: auto;
            border-radius: .5rem;
            background: rgba(127, 127, 127, .16);
        }

        .tk-assistant__bubble pre code {
            padding: 0;
            background: none;
        }

        .tk-assistant__bubble table {
            display: block;
            width: 100%;
            margin: .5rem 0;
            overflow-x: auto;
            border-collapse: collapse;
            font-size: .8125rem;
        }

        .tk-assistant__bubble th,
        .tk-assistant__bubble td {
            padding: .3rem .45rem;
            border: 1px solid rgba(127, 127, 127, .3);
            text-align: left;
        }

        .tk-assistant__bubble th {
            background: rgba(127, 127, 127, .12);
            font-weight: 600;
        }

        .tk-assistant__bubble blockquote {
            margin: .5rem 0;
            padding-left: .6rem;
            border-left: 3px solid rgba(127, 127, 127, .4);
            opacity: .85;
        }

        .tk-assistant__bubble a {
            color: var(--primary-600, #4f46e5);
            text-decoration: underline;
        }

        .tk-assistant__row--user .tk-assistant__bubble a {
            color: inherit;
        }

        .tk-assistant__bubble hr {
            margin: .6rem 0;
            border: 0;
            border-top: 1px solid rgba(127, 127, 127, .25);
        }

        /* 尚未收到首个分片时隐藏流式气泡，避免出现空气泡 */
        .tk-assistant__bubble--stream:empty {
            display: none;
        }

        /* 首个分片到达后隐藏 "..."，头像与名称保持可见 */
        .tk-assistant__messages:has(.tk-assistant__bubble--stream:not(:empty)) .tk-assistant__typing-bubble {
            display: none;
        }

        .tk-assistant__typing {
            display: none;
        }

        .tk-assistant__typing-bubble {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            padding: .7rem .85rem;
        }

        .tk-assistant__typing-bubble span {
            width: .4rem;
            height: .4rem;
            border-radius: 9999px;
            background: currentColor;
            opacity: .35;
            animation: tk-assistant-typing 1.1s infinite ease-in-out;
        }

        .tk-assistant__typing-bubble span:nth-child(2) {
            animation-delay: .15s;
        }

        .tk-assistant__typing-bubble span:nth-child(3) {
            animation-delay: .3s;
        }

        @keyframes tk-assistant-typing {
            0%, 80%, 100% {
                transform: translateY(0);
                opacity: .35;
            }

            40% {
                transform: translateY(-3px);
                opacity: 1;
            }
        }

        .tk-assistant__empty {
            padding: 1rem 0;
            text-align: center;
            opacity: .6;
        }

        .tk-assistant__composer {
            display: flex;
            padding: .75rem;
            border-top: 1px solid rgba(127, 127, 127, .18);
        }

        .tk-assistant__field {
            display: flex;
            flex: 1;
            align-items: flex-end;
            gap: .2rem;
            min-width: 0;
            padding: .25rem;
            border: 1px solid rgba(127, 127, 127, .28);
            border-radius: .75rem;
        }

        .tk-assistant__field:focus-within {
            border-color: var(--primary-600, #4f46e5);
            box-shadow: 0 0 0 1px var(--primary-600, #4f46e5);
        }

        .tk-assistant__input {
            flex: 1;
            min-width: 0;
            min-height: 2.25rem;
            max-height: 7.5rem;
            padding: .4rem .45rem;
            border: 0;
            background: transparent;
            color: inherit;
            font: inherit;
            line-height: 1.5;
            outline: none;
            overflow-y: hidden;
            resize: none;
        }

        .tk-assistant__send {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.25rem;
            height: 2.25rem;
            flex: none;
            border: 0;
            border-radius: .6rem;
            background: var(--primary-600, #4f46e5);
            color: #fff;
            cursor: pointer;
        }

        .tk-assistant__send[disabled] {
            opacity: .5;
            cursor: not-allowed;
        }

        .tk-assistant__send svg {
            width: 1.1rem;
            height: 1.1rem;
        }

        .tk-assistant__clear {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.25rem;
            height: 2.25rem;
            flex: none;
            border: 0;
            border-radius: .6rem;
            background: transparent;
            color: inherit;
            opacity: .55;
            cursor: pointer;
        }

        .tk-assistant__clear:hover,
        .tk-assistant__clear:focus-visible {
            background: rgba(127, 127, 127, .14);
            opacity: 1;
            outline: none;
        }

        .tk-assistant__clear svg {
            width: 1rem;
            height: 1rem;
        }

        .tk-assistant__error {
            margin: 0;
            padding: 0 .75rem .5rem;
            color: #dc2626;
            font-size: .75rem;
        }

        @media (max-width: 640px) {
            .tk-assistant {
                right: .75rem;
                bottom: .75rem;
            }

            .tk-assistant__panel {
                top: .75rem;
                right: .75rem;
                bottom: 4.5rem;
                left: .75rem;
                width: auto;
                height: auto;
                max-width: none;
                max-height: none;
            }

            .tk-assistant__resize {
                display: none;
            }
        }
    </style>
</div>
