@php
    $cards = $getCards();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div x-data="{
        state: $wire.$entangle('{{ $getStatePath() }}'),
        activeTab: 'all',
        search: '',
        cards: @js($cards),
        init() {
            if (this.state) {
                this.activeTab = 'all';
            } else if (this.hasSuggestionTab()) {
                this.activeTab = 'suggested';
            }
        },
        hasSuggestionTab() {
            return this.cards.some(card => card.isSuggested === true);
        },
        setTab(tab) {
            this.activeTab = tab;
            this.search = '';
        },
        bestSuggestedCard() {
            const suggestions = this.cards.filter(card => card.isSuggested === true);
    
            if (!suggestions.length) {
                return null;
            }
    
            return suggestions
                .slice()
                .sort((a, b) => (b.suggestionScore ?? 0) - (a.suggestionScore ?? 0))[0];
        },
        suggestedCards() {
            return this.cards
                .filter(card => card.isSuggested === true)
                .slice()
                .sort((a, b) => (b.suggestionScore ?? 0) - (a.suggestionScore ?? 0))
                .slice(0, 3);
        },
        scopedCards() {
            if (this.hasSuggestionTab() && this.activeTab === 'suggested') {
                return this.suggestedCards();
            }
    
            return this.cards;
        },
        matches(card) {
            if (!this.search) {
                return true;
            }
    
            const searchable = [card.title, card.subtitle, ...(card.meta ?? [])];
    
            if (Array.isArray(card.details)) {
                card.details.forEach(d => {
                    if (d && d.value) searchable.push(d.value);
                });
            }
    
            return searchable
                .filter(Boolean)
                .join(' ')
                .toLowerCase()
                .includes(this.search.toLowerCase());
        },
        visibleCards() {
            return this.scopedCards().filter(card => this.matches(card));
        },
        select(value) {
            this.state = value;
        },
        isSelected(value) {
            return String(this.state ?? '') === String(value ?? '');
        },
        statusDotColor(dot) {
            const colors = {
                success: 'bg-emerald-500',
                warning: 'bg-amber-500',
                danger: 'bg-red-500',
                info: 'bg-sky-500',
                primary: 'bg-indigo-500',
            };
            return colors[dot] ?? 'bg-gray-400';
        },
    }" class="space-y-3">
        {{-- Tabs + Search --}}
        <div class="flex items-center justify-between gap-3">
            <div x-show="hasSuggestionTab()" x-cloak
                class="inline-flex rounded-xl border border-gray-200 bg-gray-50 p-1 dark:border-gray-700 dark:bg-gray-900">
                <button type="button" x-on:click="setTab('suggested')"
                    class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                    :class="activeTab === 'suggested'
                        ?
                        'bg-primary-500/10 text-primary-600 dark:text-primary-400' :
                        'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200'">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                    </svg>
                    Gợi ý
                </button>
                <button type="button" x-on:click="setTab('all')"
                    class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                    :class="activeTab === 'all'
                        ?
                        'bg-primary-500/10 text-primary-600 dark:text-primary-400' :
                        'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200'">
                    Tất cả
                    <span
                        class="rounded-full bg-gray-200/80 px-1.5 py-0.5 text-[10px] font-bold tabular-nums text-gray-600 dark:bg-gray-700 dark:text-gray-300"
                        x-text="cards.length"></span>
                </button>
            </div>

            <div class="relative ml-auto w-full max-w-xs">
                <div class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                    <x-filament::icon icon="heroicon-m-magnifying-glass" class="h-4 w-4" />
                </div>
                <input type="search" x-model.debounce.200ms="search" placeholder="{{ $getSearchPlaceholder() }}"
                    class="w-full rounded-xl border border-gray-200 bg-white py-2 pl-9 pr-4 text-sm text-gray-900 shadow-sm outline-none transition placeholder:text-gray-400 focus:border-primary-400 focus:ring-2 focus:ring-primary-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:placeholder:text-gray-500" />
            </div>
        </div>

        {{-- Cards Grid --}}
        <div class="grid max-h-[420px] gap-2.5 overflow-y-auto pr-0.5 custom-scrollbar"
            :class="{
                'sm:grid-cols-3': activeTab !== 'suggested',
                'sm:grid-cols-1 w-full': activeTab === 'suggested' && visibleCards().length === 1,
                'sm:grid-cols-2': activeTab === 'suggested' && visibleCards().length >= 2
            }">
            <template x-for="card in visibleCards()" :key="card.value">
                <div x-show="matches(card)" x-cloak class="h-full">
                    <button type="button" x-on:click="select(card.value)"
                        class="group relative flex h-full w-full flex-col rounded-xl border-2 bg-white text-left transition-all duration-200"
                        :class="isSelected(card.value) ?
                            'border-primary-500 bg-primary-50/50 shadow-md shadow-primary-500/10 dark:border-primary-400 dark:bg-primary-950/20' :
                            'border-gray-200 hover:border-gray-300 hover:shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:hover:border-gray-600'">
                        {{-- Selected check indicator --}}
                        <div x-show="isSelected(card.value)" x-cloak
                            class="absolute -right-1.5 -top-1.5 z-10 flex h-5 w-5 items-center justify-center rounded-full bg-primary-500 text-white shadow-sm">
                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="3"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                            </svg>
                        </div>

                        {{-- Card Header --}}
                        <div class="flex items-center gap-3 border-b border-gray-100 px-3.5 py-3 dark:border-gray-800">
                            {{-- Avatar / Icon --}}
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-sm"
                                :class="isSelected(card.value) ?
                                    'bg-primary-100 dark:bg-primary-900/50' :
                                    'bg-gray-100 dark:bg-gray-800'">
                                @if ($getLeadingIcon())
                                    <x-filament::icon :icon="$getLeadingIcon()" class="h-5 w-5"
                                        x-bind:class="isSelected(card.value) ?
                                            'text-primary-600 dark:text-primary-400' :
                                            'text-gray-500 dark:text-gray-400'" />
                                @else
                                    <span x-text="card.leading ?? '•'" class="text-base"></span>
                                @endif
                            </div>

                            {{-- Title + Subtitle --}}
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="truncate text-sm font-bold text-gray-900 dark:text-gray-100"
                                        x-text="card.title"></span>
                                    {{-- Status dot --}}
                                    <span x-show="card.statusDot" class="relative flex h-2 w-2 shrink-0">
                                        <span
                                            class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-75"
                                            :class="statusDotColor(card.statusDot)"></span>
                                        <span class="relative inline-flex h-2 w-2 rounded-full"
                                            :class="statusDotColor(card.statusDot)"></span>
                                    </span>
                                </div>
                                <div class="truncate text-xs text-gray-500 dark:text-gray-400" x-text="card.subtitle">
                                </div>
                            </div>

                            {{-- Badge --}}
                            <span x-show="card.badge"
                                class="shrink-0 rounded-lg px-2 py-1 text-[10px] font-bold uppercase tracking-wider"
                                :class="activeTab === 'suggested' && card.isSuggested ?
                                    (card.suggestedBadgeClasses ?? card.badgeClasses ??
                                        'border-gray-200 bg-gray-50 text-gray-600') :
                                    (card.badgeClasses ??
                                        'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200'
                                    )"
                                x-text="activeTab === 'suggested' && card.isSuggested
                                    ? (card.suggestedBadge ?? card.badge)
                                    : card.badge"></span>
                        </div>

                        {{-- Card Body: Details --}}
                        <div x-show="Array.isArray(card.details) && card.details.length" class="space-y-0 px-3.5 py-2">
                            <template x-for="(detail, idx) in card.details" :key="idx">
                                <div x-show="detail" class="flex items-center gap-2 py-1">
                                    <div x-show="detail && detail.icon" class="flex shrink-0">
                                        <x-filament::icon x-bind:icon="detail.icon"
                                            class="h-3.5 w-3.5 text-gray-400 dark:text-gray-500" />
                                    </div>
                                    <span class="w-16 shrink-0 text-[11px] font-medium text-gray-400 dark:text-gray-500"
                                        x-text="detail ? detail.label : ''"></span>
                                    <span class="truncate text-xs font-medium text-gray-700 dark:text-gray-300"
                                        x-text="detail ? detail.value : ''"></span>
                                </div>
                            </template>
                        </div>

                        {{-- Suggestion highlight bar --}}
                        <div x-show="activeTab === 'suggested' && card.isSuggested"
                            class="mt-auto flex items-center gap-2 rounded-b-[10px] bg-primary-50 px-3.5 py-2 dark:bg-primary-950/30">
                            <svg class="h-3.5 w-3.5 text-primary-500" fill="none" viewBox="0 0 24 24"
                                stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                            </svg>
                            <span class="text-[11px] font-semibold text-primary-600 dark:text-primary-400">Phù hợp
                                nhất cho đơn hàng này</span>
                        </div>
                    </button>
                </div>
            </template>
        </div>

        {{-- Empty state --}}
        <div x-show="visibleCards().length === 0" x-cloak
            class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-gray-200 bg-gray-50/50 px-6 py-8 dark:border-gray-700 dark:bg-gray-900/50">
            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                <x-filament::icon icon="heroicon-o-magnifying-glass" class="h-5 w-5 text-gray-400" />
            </div>
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Không tìm thấy kết quả phù hợp</p>
            <p class="text-xs text-gray-400 dark:text-gray-500">Thử từ khóa khác hoặc chuyển sang tab "Tất cả"</p>
        </div>
    </div>
</x-dynamic-component>
