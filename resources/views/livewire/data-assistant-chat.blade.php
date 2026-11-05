<div>
@if ($canUse)
<div
    class="da-root"
    x-data="{
        open: false,
        showHistory: false,
        draft: '',
        pending: null,
        size: null,
        resizing: false,
        get panelStyle() {
            if (! this.size || window.innerWidth < 640) { return '' }
            return `width: ${this.size.w}px; height: ${this.size.h}px`
        },
        clampSize(w, h) {
            return {
                w: Math.round(Math.min(Math.max(w, 340), window.innerWidth - 32)),
                h: Math.round(Math.min(Math.max(h, 380), window.innerHeight - 40)),
            }
        },
        saveSize() {
            try { localStorage.setItem('da-size', JSON.stringify(this.size)) } catch (e) {}
        },
        startResize(event) {
            const panel = this.$refs.panel
            const start = { x: event.clientX, y: event.clientY, w: panel.offsetWidth, h: panel.offsetHeight }
            this.resizing = true
            const move = (e) => { this.size = this.clampSize(start.w + start.x - e.clientX, start.h + start.y - e.clientY) }
            const stop = () => {
                this.resizing = false
                window.removeEventListener('pointermove', move)
                window.removeEventListener('pointerup', stop)
                this.saveSize()
            }
            window.addEventListener('pointermove', move)
            window.addEventListener('pointerup', stop)
        },
        toggleMaximize() {
            const isBig = this.size && this.size.w >= window.innerWidth - 40
            this.size = isBig ? null : this.clampSize(window.innerWidth, window.innerHeight)
            this.saveSize()
            this.$nextTick(() => this.scrollDown())
        },
        init() {
            try { this.open = localStorage.getItem('da-open') === '1' } catch (e) {}
            try { const saved = JSON.parse(localStorage.getItem('da-size') || 'null'); if (saved) { this.size = this.clampSize(saved.w, saved.h) } } catch (e) {}
            this.$watch('open', (value) => {
                try { localStorage.setItem('da-open', value ? '1' : '0') } catch (e) {}
                if (value) { this.$nextTick(() => { this.scrollDown(); this.$refs.input?.focus() }) }
            })
            this.$nextTick(() => this.scrollDown())
        },
        scrollDown() {
            const box = this.$refs.messages
            if (box) { box.scrollTop = box.scrollHeight }
        },
        send(text) {
            const question = (text ?? this.draft).trim()
            if (! question || this.pending) { return }
            this.pending = question
            this.draft = ''
            this.showHistory = false
            this.$nextTick(() => this.scrollDown())
            $wire.ask(question).finally(() => {
                this.pending = null
                this.$nextTick(() => { this.scrollDown(); this.$refs.input?.focus() })
            })
        },
        followLink(event) {
            const link = event.target.closest('a[href]')
            if (! link) { return }
            const url = new URL(link.href, window.location.origin)
            event.preventDefault()
            if (url.origin !== window.location.origin) {
                window.open(url.href, '_blank', 'noopener')
                return
            }
            if (window.innerWidth < 640) { this.open = false }
            window.Livewire ? window.Livewire.navigate(url.href) : (window.location.href = url.href)
        },
    }"
    x-on:keydown.escape.window="open = false"
>
    {{-- Nút nổi --}}
    <button
        type="button"
        class="da-fab"
        x-show="! open"
        x-on:click="open = true"
        aria-label="Mở trợ lý dữ liệu"
        title="Trợ lý dữ liệu"
    >
        <x-filament::icon icon="heroicon-o-sparkles" class="da-fab-icon" />
        <span class="da-fab-label">Hỏi dữ liệu</span>
    </button>

    {{-- Khung chat --}}
    <section
        class="da-panel"
        x-show="open"
        x-cloak
        x-transition:enter="da-enter"
        x-transition:enter-start="da-enter-start"
        x-transition:leave="da-leave"
        x-transition:leave-end="da-enter-start"
        role="dialog"
        aria-label="Trợ lý dữ liệu"
        x-ref="panel"
        x-bind:style="panelStyle"
        x-bind:class="{ 'is-resizing': resizing }"
    >
        <div
            class="da-resize"
            x-on:pointerdown.prevent="startResize($event)"
            x-on:dblclick="size = null; saveSize()"
            title="Kéo để đổi cỡ (nhấp đúp để về cỡ mặc định)"
            aria-hidden="true"
        ></div>
        <header class="da-header">
            <div class="da-title">
                <x-filament::icon icon="heroicon-s-sparkles" class="da-title-icon" />
                <div>
                    <p class="da-title-main">Trợ lý dữ liệu</p>
                    <p class="da-title-sub">Hỏi về chuyến, đơn, cước, xe, tài xế</p>
                </div>
            </div>
            <div class="da-header-actions">
                <button type="button" class="da-icon-btn" x-on:click="showHistory = ! showHistory" title="Hội thoại cũ" aria-label="Hội thoại cũ">
                    <x-filament::icon icon="heroicon-o-clock" class="da-icon" />
                </button>
                <button type="button" class="da-icon-btn" wire:click="newConversation" x-on:click="showHistory = false" title="Hội thoại mới" aria-label="Hội thoại mới">
                    <x-filament::icon icon="heroicon-o-plus" class="da-icon" />
                </button>
                <button type="button" class="da-icon-btn da-desktop-only" x-on:click="toggleMaximize()" title="Phóng to / về cỡ cũ" aria-label="Phóng to hoặc về cỡ cũ">
                    <x-filament::icon icon="heroicon-o-arrows-pointing-out" class="da-icon" />
                </button>
                <button type="button" class="da-icon-btn" x-on:click="open = false" title="Thu nhỏ" aria-label="Thu nhỏ">
                    <x-filament::icon icon="heroicon-o-minus" class="da-icon" />
                </button>
            </div>
        </header>

        {{-- Lịch sử hội thoại --}}
        <nav class="da-history" x-show="showHistory" x-cloak aria-label="Hội thoại cũ">
            @forelse ($this->conversations as $conversation)
                <button
                    type="button"
                    wire:key="conv-{{ $conversation->id }}"
                    wire:click="openConversation('{{ $conversation->id }}')"
                    x-on:click="showHistory = false"
                    @class(['da-history-item', 'is-active' => $conversation->id === $conversationId])
                >
                    <span class="da-history-title">{{ $conversation->title }}</span>
                    <span class="da-history-time">{{ $conversation->updated_at?->format('H:i d/m') }}</span>
                </button>
            @empty
                <p class="da-muted">Chưa có hội thoại nào.</p>
            @endforelse
        </nav>

        {{-- Tin nhắn --}}
        <div class="da-messages" x-ref="messages" x-on:click="followLink($event)">
            @if ($this->messages->isEmpty())
                <div class="da-welcome" x-show="! pending">
                    <p class="da-welcome-title">Xin chào {{ auth()->user()->name }}!</p>
                    <p class="da-muted">Hỏi bằng tiếng Việt như nói chuyện. Kết quả có link, bấm vào để mở thẳng chuyến, đơn, xe hoặc bảng kê.</p>
                    <div class="da-samples">
                        @foreach (\App\Livewire\DataAssistantChat::SAMPLE_QUESTIONS as $sample)
                            <button type="button" class="da-sample" x-on:click="send(@js($sample['question']))">
                                <span class="da-sample-topic">
                                    <x-filament::icon :icon="$sample['icon']" class="da-sample-icon" />
                                    {{ $sample['topic'] }}
                                </span>
                                <span class="da-sample-text">{{ $sample['question'] }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            @foreach ($this->messages as $index => $message)
                <article wire:key="msg-{{ $conversationId }}-{{ $index }}" @class(['da-msg', 'is-user' => $message['role'] === 'user', 'is-bot' => $message['role'] !== 'user'])>
                    <div class="da-bubble {{ $message['role'] === 'user' ? '' : 'da-md' }}">{!! $message['html'] !!}</div>
                    <span class="da-time">{{ $message['time'] }}</span>
                </article>
            @endforeach

            @php($followUps = $this->messages->last()['suggestions'] ?? [])
            @if ($followUps !== [] && ! $error)
                <div class="da-followups" x-show="! pending" wire:key="followups-{{ $conversationId }}-{{ $this->messages->count() }}">
                    <span class="da-followups-label">Hỏi tiếp</span>
                    @foreach ($followUps as $followUp)
                        <button type="button" class="da-chip" x-on:click="send(@js($followUp))">{{ $followUp }}</button>
                    @endforeach
                </div>
            @endif

            <template x-if="pending">
                <div class="da-pending">
                    <article class="da-msg is-user">
                        <div class="da-bubble" x-text="pending"></div>
                    </article>
                    <article class="da-msg is-bot">
                        <div class="da-bubble da-typing" aria-label="Đang tra dữ liệu">
                            <span></span><span></span><span></span>
                            <em>Đang tra dữ liệu…</em>
                        </div>
                    </article>
                </div>
            </template>

            @if ($error)
                <p class="da-error" role="alert">{{ $error }}</p>
            @endif
        </div>

        <form class="da-input" x-on:submit.prevent="send()">
            <textarea
                x-ref="input"
                x-model="draft"
                rows="1"
                maxlength="{{ \App\Livewire\DataAssistantChat::MAX_QUESTION_LENGTH }}"
                placeholder="Ví dụ: Tổng cước tháng 10 của khách VFC?"
                x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); send() }"
                x-bind:disabled="pending !== null"
                aria-label="Câu hỏi"
            ></textarea>
            <button type="submit" class="da-send" x-bind:disabled="pending !== null || draft.trim() === ''" aria-label="Gửi">
                <x-filament::icon icon="heroicon-s-paper-airplane" class="da-icon" />
            </button>
        </form>
        <p class="da-footnote">Trợ lý chỉ xem dữ liệu, không sửa được gì. Nên đối chiếu trước khi dùng số liệu chính thức.</p>
    </section>

    <style>
        .da-root { --da-ink: #0f1623; --da-accent: #008fd5; --da-accent-soft: rgba(0, 143, 213, .1); --da-surface: #ffffff; --da-muted: #64748b; --da-line: #e2e8f0; --da-bot: #f1f5f9; font-size: 14px; }
        .dark .da-root { --da-surface: #111827; --da-muted: #94a3b8; --da-line: #1f2937; --da-bot: #1f2937; --da-accent-soft: rgba(0, 143, 213, .18); }
        [x-cloak] { display: none !important; }
        .da-fab { position: fixed; right: 20px; bottom: 20px; z-index: 40; display: inline-flex; align-items: center; gap: 8px; padding: 12px 18px 12px 14px; border-radius: 999px; background: linear-gradient(135deg, var(--da-ink), #1e3a5f); color: #fff; font-weight: 600; box-shadow: 0 10px 30px -8px rgba(15, 22, 35, .55), 0 0 0 1px rgba(255, 255, 255, .06) inset; transition: transform .18s ease, box-shadow .18s ease; }
        .da-fab:hover { transform: translateY(-2px); box-shadow: 0 16px 36px -10px rgba(0, 143, 213, .6); }
        .da-fab:focus-visible, .da-icon-btn:focus-visible, .da-sample:focus-visible, .da-send:focus-visible, .da-history-item:focus-visible { outline: 2px solid var(--da-accent); outline-offset: 2px; }
        .da-fab-icon { width: 20px; height: 20px; color: #7dd3fc; }
        .da-panel { position: fixed; right: 20px; bottom: 20px; z-index: 40; width: min(440px, calc(100vw - 32px)); height: min(640px, calc(100vh - 40px)); display: flex; flex-direction: column; overflow: hidden; border-radius: 18px; background: var(--da-surface); box-shadow: 0 24px 60px -12px rgba(15, 22, 35, .45), 0 0 0 1px var(--da-line); }
        .da-panel.is-resizing { user-select: none; }
        .da-panel.is-resizing, .da-panel.is-resizing * { cursor: nwse-resize !important; }
        .da-resize { position: absolute; top: 0; left: 0; z-index: 2; width: 18px; height: 18px; cursor: nwse-resize; touch-action: none; }
        .da-resize::after { content: ''; position: absolute; top: 5px; left: 5px; width: 8px; height: 8px; border-top: 2px solid rgba(255, 255, 255, .55); border-left: 2px solid rgba(255, 255, 255, .55); border-top-left-radius: 3px; transition: border-color .15s; }
        .da-resize:hover::after { border-color: #7dd3fc; }
        .da-enter, .da-leave { transition: opacity .18s ease, transform .18s ease; transform-origin: bottom right; }
        .da-enter-start { opacity: 0; transform: translateY(12px) scale(.97); }
        .da-header { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 12px 12px 12px 16px; background: linear-gradient(135deg, var(--da-ink), #1e3a5f); color: #fff; }
        .da-title { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .da-title-icon { width: 22px; height: 22px; color: #7dd3fc; flex: none; }
        .da-title-main { font-weight: 700; line-height: 1.2; }
        .da-title-sub { font-size: 12px; color: #cbd5e1; }
        .da-header-actions { display: flex; gap: 2px; }
        .da-icon-btn { display: grid; place-items: center; width: 32px; height: 32px; border-radius: 8px; color: #e2e8f0; transition: background .15s; }
        .da-icon-btn:hover { background: rgba(255, 255, 255, .12); }
        .da-icon { width: 18px; height: 18px; }
        .da-history { max-height: 45%; overflow-y: auto; padding: 6px; border-bottom: 1px solid var(--da-line); background: var(--da-surface); }
        .da-history-item { display: flex; width: 100%; justify-content: space-between; gap: 12px; padding: 8px 10px; border-radius: 8px; text-align: left; }
        .da-history-item:hover, .da-history-item.is-active { background: var(--da-accent-soft); }
        .da-history-title { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .da-history-time { color: var(--da-muted); font-size: 12px; flex: none; }
        .da-messages { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 12px; scroll-behavior: smooth; }
        .da-welcome-title { font-size: 18px; font-weight: 700; margin-bottom: 4px; }
        .da-muted { color: var(--da-muted); font-size: 13px; }
        .da-samples { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 8px; margin-top: 14px; }
        .da-sample { display: flex; flex-direction: column; gap: 6px; text-align: left; padding: 10px 12px; border: 1px solid var(--da-line); border-radius: 12px; background: var(--da-surface); transition: border-color .15s, background .15s, transform .15s, box-shadow .15s; }
        .da-sample:hover { border-color: var(--da-accent); background: var(--da-accent-soft); transform: translateY(-2px); box-shadow: 0 6px 14px -8px rgba(0, 143, 213, .5); }
        .da-sample-topic { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--da-accent); }
        .da-sample-icon { width: 15px; height: 15px; }
        .da-sample-text { font-size: 13px; line-height: 1.4; }
        .da-followups { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: -4px; }
        .da-followups-label { width: 100%; font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--da-muted); }
        .da-chip { padding: 6px 11px; border-radius: 999px; border: 1px solid var(--da-accent); color: var(--da-accent); background: var(--da-surface); font-size: 13px; text-align: left; line-height: 1.35; transition: background .15s, color .15s, transform .15s; }
        .da-chip:hover { background: var(--da-accent); color: #fff; transform: translateY(-1px); }
        .da-chip:focus-visible { outline: 2px solid var(--da-accent); outline-offset: 2px; }
        .da-msg { display: flex; flex-direction: column; max-width: 92%; }
        .da-msg.is-user { align-self: flex-end; align-items: flex-end; }
        .da-msg.is-bot { align-self: flex-start; }
        .da-bubble { padding: 9px 12px; border-radius: 14px; line-height: 1.5; overflow-wrap: anywhere; }
        .da-msg.is-user .da-bubble { background: var(--da-accent); color: #fff; border-bottom-right-radius: 4px; white-space: pre-wrap; }
        .da-msg.is-bot .da-bubble { background: var(--da-bot); border-bottom-left-radius: 4px; max-width: 100%; overflow-x: auto; }
        .da-time { font-size: 11px; color: var(--da-muted); margin-top: 2px; }
        .da-msg.is-bot:has(.da-md) { max-width: 100%; width: 100%; }
        .da-pending { display: flex; flex-direction: column; gap: 12px; }
        .da-msg.is-bot .da-bubble.da-md { background: var(--da-surface); border: 1px solid var(--da-line); border-left: 3px solid var(--da-accent); border-radius: 4px 14px 14px 14px; padding: 12px 14px; box-shadow: 0 1px 2px rgba(15, 22, 35, .04); }
        .da-md > * + * { margin-top: 10px; }
        .da-md > p:first-child { font-size: 15px; line-height: 1.45; }
        .da-md > p:first-child strong { color: var(--da-ink); }
        .dark .da-md > p:first-child strong { color: #f8fafc; }
        .da-md h3, .da-md h4 { font-size: 12px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--da-muted); margin-top: 14px; }
        .da-md ul { list-style: disc; padding-left: 18px; }
        .da-md ol { list-style: decimal; padding-left: 18px; }
        .da-md li + li { margin-top: 2px; }
        .da-md strong { font-weight: 700; }
        .da-md p:last-child:not(:first-child) { color: var(--da-muted); font-size: 13px; }
        .da-link { color: var(--da-accent); font-weight: 600; text-decoration: underline; text-underline-offset: 2px; }
        .da-link:hover { text-decoration-thickness: 2px; }
        .da-table { overflow-x: auto; border: 1px solid var(--da-line); border-radius: 10px; }
        .da-md table { width: 100%; border-collapse: collapse; font-size: 12.5px; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .da-md thead th { position: sticky; top: 0; background: var(--da-bot); padding: 7px 10px; text-align: left; font-size: 11px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; color: var(--da-muted); }
        .da-md td { padding: 6px 10px; border-top: 1px solid var(--da-line); }
        .da-md tbody tr:nth-child(even) td { background: rgba(148, 163, 184, .06); }
        .da-md tbody tr:hover td { background: var(--da-accent-soft); }
        .da-md td.is-num, .da-md th.is-num { text-align: right; }
        .da-md td.is-num { font-weight: 600; }
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
        .da-typing { display: inline-flex; align-items: center; gap: 4px; }
        .da-typing span { width: 6px; height: 6px; border-radius: 50%; background: var(--da-accent); animation: da-bounce 1s infinite ease-in-out; }
        .da-typing span:nth-child(2) { animation-delay: .15s; }
        .da-typing span:nth-child(3) { animation-delay: .3s; }
        .da-typing em { margin-left: 6px; font-style: normal; color: var(--da-muted); font-size: 12px; }
        @keyframes da-bounce { 0%, 80%, 100% { transform: translateY(0); opacity: .5; } 40% { transform: translateY(-4px); opacity: 1; } }
        @media (prefers-reduced-motion: reduce) { .da-bar-fill, .da-typing span, .da-fab, .da-sample, .da-enter, .da-leave { animation: none; transition: none; } }
        .da-error { padding: 8px 12px; border-radius: 10px; background: #fef2f2; color: #b91c1c; font-size: 13px; }
        .dark .da-error { background: rgba(185, 28, 28, .15); color: #fca5a5; }
        .da-input { display: flex; align-items: flex-end; gap: 8px; padding: 10px 12px 6px; border-top: 1px solid var(--da-line); }
        .da-input textarea { flex: 1; resize: none; max-height: 120px; padding: 9px 12px; border-radius: 12px; border: 1px solid var(--da-line); background: transparent; font-size: 14px; field-sizing: content; }
        .da-input textarea:focus { outline: none; border-color: var(--da-accent); box-shadow: 0 0 0 3px var(--da-accent-soft); }
        .da-send { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 12px; background: var(--da-accent); color: #fff; transition: opacity .15s, transform .15s; }
        .da-send:hover:not(:disabled) { transform: translateY(-1px); }
        .da-send:disabled { opacity: .4; cursor: not-allowed; }
        .da-footnote { padding: 0 12px 10px; font-size: 11px; color: var(--da-muted); }
        @media (max-width: 639px) { .da-resize, .da-desktop-only { display: none; } .da-panel { right: 0; bottom: 0; width: 100vw; height: 100dvh; border-radius: 0; } .da-fab { right: 16px; bottom: 16px; } .da-fab-label { display: none; } .da-fab { padding: 14px; } }
    </style>
</div>
@endif
</div>
