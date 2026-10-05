@php
    $cards = $getCards();
    $cardCollection = collect($cards);
    $detailIcons = $cardCollection->pluck('details')->flatten(1)->pluck('icon')->filter()->unique()->values();
    $badgeColors = ['primary', 'success', 'warning', 'danger', 'info', 'gray'];
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div x-data="{
        state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$getStatePath()}')") }},
        activeTab: 'all',
        search: '',
        cards: @js($cards),
        displayLimit: 24,
        batchSize: 24,
        init() {
            if (this.state) {
                if (!this.scopedCards().some(c => String(c.value) === String(this.state))) {
                    this.activeTab = 'all';
                }
            } else if (this.hasSuggestionTab()) {
                this.activeTab = 'suggested';
            }

            this.ensureSelectedCardVisible();

            this.$watch('state', (val) => {
                if (val && !this.scopedCards().some(c => String(c.value) === String(val))) {
                    this.activeTab = 'all';
                }
                this.ensureSelectedCardVisible();
            });

            this.$watch('search', (val) => {
                this.displayLimit = this.batchSize;
                if (val.length === 0 && this.hasSuggestionTab()) {
                    this.activeTab = 'suggested';
                }
                this.ensureSelectedCardVisible();
            });

            this.$watch('activeTab', () => {
                this.displayLimit = this.batchSize;
                this.ensureSelectedCardVisible();
            });
        },
        ensureSelectedCardVisible() {
            if (!this.state) return;

            if (!this.scopedCards().some(c => String(c.value) === String(this.state))) {
                this.activeTab = 'all';
            }

            const cards = this.allFilteredCards();
            const index = cards.findIndex(c => String(c.value) === String(this.state));

            if (index !== -1 && index >= this.displayLimit) {
                this.displayLimit = Math.ceil((index + 1) / this.batchSize) * this.batchSize;
            }
        },
        getNestedValue(obj, path) {
            return path.split('.').reduce((acc, part) => {
                if (acc === null || acc === undefined) return undefined;
                return acc[part];
            }, obj);
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
                return this.search ? this.cards : this.suggestedCards();
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
        allFilteredCards() {
            return this.scopedCards().filter(card => this.matches(card));
        },
        visibleCards() {
            return this.allFilteredCards().slice(0, this.displayLimit);
        },
        hasMoreCards() {
            return this.allFilteredCards().length > this.displayLimit;
        },
        loadMore() {
            this.displayLimit += this.batchSize;
        },
        select(value) {
            this.state = value;
        },
        isSelected(value) {
            return String(this.state ?? '') === String(value ?? '');
        },
        statusDotColor(dot) {
            const colors = {
                success: 'bg-success-500',
                warning: 'bg-warning-500',
                danger: 'bg-danger-500',
                info: 'bg-info-500',
                primary: 'bg-primary-500',
            };
            return colors[dot] ?? 'bg-gray-400';
        },
        badgeColor(card) {
            if (this.activeTab === 'suggested' && card.isSuggested) {
                return 'primary';
            }
            return ['primary', 'success', 'warning', 'danger', 'info'].includes(card.statusDot) ? card.statusDot : 'gray';
        },
        badgeLabel(card) {
            return this.activeTab === 'suggested' && card.isSuggested
                ? (card.suggestedBadge ?? card.badge)
                : card.badge;
        },
    }" class="space-y-3">
        {{-- Tabs + Search --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-filament::tabs contained x-show="hasSuggestionTab()" x-cloak>
                <x-filament::tabs.item alpine-active="activeTab === 'suggested'" icon="heroicon-m-sparkles"
                    x-on:click="setTab('suggested')">
                    Gợi ý
                </x-filament::tabs.item>
                <x-filament::tabs.item alpine-active="activeTab === 'all'" :badge="count($cards)"
                    x-on:click="setTab('all')">
                    Tất cả
                </x-filament::tabs.item>
            </x-filament::tabs>
            <div class="ms-auto flex w-full items-center gap-2 sm:w-auto">
                <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass" class="w-full sm:w-72">
                    <x-filament::input type="search" x-model="search"
                        placeholder="{{ $getSearchPlaceholder() }}" />
                </x-filament::input.wrapper>
            </div>
        </div>

        {{-- Cards Grid --}}
        <div class="custom-scrollbar grid max-h-162.5 grid-cols-1 gap-3 overflow-y-auto p-2 sm:grid-cols-2 md:grid-cols-3">
            <template x-for="card in visibleCards()" :key="card.value">
                <div x-show="matches(card)" x-cloak class="h-full">
                    <button type="button" x-on:click="select(card.value)"
                        x-effect="if (isSelected(card.value)) { $nextTick(function() { $el.scrollIntoView({ behavior: 'smooth', block: 'center' }) }) }"
                        x-bind:aria-pressed="isSelected(card.value) ? 'true' : 'false'"
                        class="relative flex h-full w-full cursor-pointer flex-col rounded-xl text-start shadow-sm transition duration-75 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 motion-reduce:transition-none dark:focus-visible:outline-primary-500"
                        x-bind:class="isSelected(card.value) ?
                            'bg-primary-50 ring-2 ring-primary-600 dark:bg-primary-400/10 dark:ring-primary-500' :
                            'bg-white ring-1 ring-gray-950/10 hover:bg-gray-50 dark:bg-gray-900 dark:ring-white/10 dark:hover:bg-white/5'">
                        {{-- Selected check indicator --}}
                        <span x-show="isSelected(card.value)" x-cloak
                            class="absolute -end-1.5 -top-1.5 z-10 flex rounded-full bg-white text-primary-600 dark:bg-gray-900 dark:text-primary-400">
                            <x-filament::icon icon="heroicon-s-check-circle" class="h-5 w-5" />
                        </span>

                        {{-- Card Header --}}
                        <div class="flex items-center gap-3 p-3">
                            {{-- Avatar / Icon --}}
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg"
                                x-bind:class="isSelected(card.value) ?
                                    'bg-primary-100 text-primary-600 dark:bg-primary-400/20 dark:text-primary-400' :
                                    'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400'">
                                @if ($getLeadingIcon())
                                    <x-filament::icon :icon="$getLeadingIcon()" class="h-5 w-5" />
                                @else
                                    <span x-text="card.leading ?? '•'" class="text-base"></span>
                                @endif
                            </div>

                            {{-- Title + Subtitle --}}
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="truncate text-sm font-semibold text-gray-950 dark:text-white"
                                        x-text="card.title" x-bind:title="card.title"></span>
                                    {{-- Status dot --}}
                                    <span x-show="card.statusDot" class="inline-flex h-2 w-2 shrink-0 rounded-full"
                                        x-bind:class="statusDotColor(card.statusDot)"></span>
                                </div>
                                <div class="truncate text-xs text-gray-500 dark:text-gray-400" x-text="card.subtitle"
                                    x-bind:title="card.subtitle"></div>
                            </div>

                            {{-- Badge --}}
                            <div x-show="card.badge" class="shrink-0">
                                @foreach ($badgeColors as $badgeColor)
                                    <template x-if="badgeColor(card) === '{{ $badgeColor }}'">
                                        <x-filament::badge :color="$badgeColor" size="sm">
                                            <span x-text="badgeLabel(card)"></span>
                                        </x-filament::badge>
                                    </template>
                                @endforeach
                            </div>
                        </div>

                        {{-- Card Body: Details --}}
                        <div x-show="Array.isArray(card.details) && card.details.length"
                            class="grid grid-cols-2 gap-x-3 gap-y-2 border-t border-gray-200 p-3 dark:border-white/10">
                            <template x-for="(detail, idx) in card.details" :key="idx">
                                <div x-show="detail" class="flex min-w-0 items-center gap-2">
                                    <div x-show="detail && detail.icon"
                                        class="flex shrink-0 items-center justify-center rounded-md bg-gray-100 p-1 text-gray-500 dark:bg-white/5 dark:text-gray-400">
                                        @foreach ($detailIcons as $detailIcon)
                                            <template x-if="detail && detail.icon === '{{ $detailIcon }}'">
                                                <x-filament::icon :icon="$detailIcon" class="h-3.5 w-3.5" />
                                            </template>
                                        @endforeach
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <span class="block truncate text-xs leading-tight text-gray-500 dark:text-gray-400"
                                            x-text="detail ? detail.label : ''"></span>
                                        <span class="block truncate text-xs font-medium leading-tight text-gray-950 dark:text-white"
                                            x-text="detail ? detail.value : ''"
                                            x-bind:title="detail ? detail.value : ''"></span>
                                    </div>
                                </div>
                            </template>
                        </div>

                        {{-- Suggestion highlight bar --}}
                        <div x-show="activeTab === 'suggested' && card.isSuggested"
                            class="mt-auto flex items-center gap-2 rounded-b-xl border-t border-primary-600/10 bg-primary-50 px-3 py-2 text-primary-600 dark:border-primary-400/20 dark:bg-primary-400/10 dark:text-primary-400">
                            <x-filament::icon icon="heroicon-m-sparkles" class="h-4 w-4 shrink-0" />
                            <span class="text-xs font-medium">Phù hợp nhất cho đơn hàng này</span>
                        </div>
                    </button>
                </div>
            </template>

            {{-- Load more sentinel --}}
            <div x-show="hasMoreCards()" x-intersect.margin.200px="loadMore()"
                class="col-span-full flex items-center justify-center py-4">
                <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                    <x-filament::loading-indicator class="h-4 w-4" />
                    <span class="tabular-nums"
                        x-text="'Đang tải thêm... (' + visibleCards().length + '/' + allFilteredCards().length + ')'"></span>
                </div>
            </div>
        </div>

        {{-- Empty state --}}
        <div x-show="allFilteredCards().length === 0" x-cloak
            class="flex flex-col items-center justify-center gap-3 rounded-xl bg-white px-6 py-10 text-center ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="rounded-full bg-gray-100 p-3 text-gray-500 dark:bg-gray-500/20 dark:text-gray-400">
                <x-filament::icon icon="heroicon-o-magnifying-glass" class="h-6 w-6" />
            </div>
            <p class="text-base font-semibold text-gray-950 dark:text-white">Không tìm thấy kết quả phù hợp</p>
            <p class="text-sm text-gray-500 dark:text-gray-400">Thử từ khóa khác để tìm kiếm</p>
        </div>
    </div>

</x-dynamic-component>
