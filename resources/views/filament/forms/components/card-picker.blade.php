@php
    $cards = $getCards();
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        x-data="{
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

                if (! suggestions.length) {
                    return null;
                }

                return suggestions
                    .slice()
                    .sort((a, b) => (b.suggestionScore ?? 0) - (a.suggestionScore ?? 0))[0];
            },
            scopedCards() {
                if (this.hasSuggestionTab() && this.activeTab === 'suggested') {
                    const bestCard = this.bestSuggestedCard();

                    return bestCard ? [bestCard] : [];
                }

                return this.cards;
            },
            matches(card) {
                if (! this.search) {
                    return true;
                }

                return [card.title, card.subtitle, ...(card.meta ?? [])]
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
        }"
        class="space-y-4"
    >
        <div class="flex items-center justify-between gap-3">
            <div
                x-show="hasSuggestionTab()"
                x-cloak
                class="inline-flex rounded-xl border border-gray-200 bg-gray-50 p-1 dark:border-gray-700 dark:bg-gray-900"
            >
                <button
                    type="button"
                    x-on:click="setTab('suggested')"
                    class="rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                    :class="activeTab === 'suggested'
                        ? 'bg-[#008fd5]/10 text-[#008fd5]'
                        : 'text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-100'"
                >
                    Gợi ý xe phù hợp
                </button>
                <button
                    type="button"
                    x-on:click="setTab('all')"
                    class="rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                    :class="activeTab === 'all'
                        ? 'bg-[#008fd5]/10 text-[#008fd5]'
                        : 'text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-100'"
                >
                    Tất cả xe
                </button>
            </div>

            <div class="relative ml-auto w-1/2">
            <input
                type="search"
                x-model="search"
                placeholder="{{ $getSearchPlaceholder() }}"
                class="w-full rounded-xl border border-gray-200 bg-white px-4 py-2.5 pr-10 text-sm text-gray-900 shadow-sm outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
            />
            <div class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-gray-400">
                <x-filament::icon icon="heroicon-m-magnifying-glass" class="h-4 w-4" />
            </div>
            </div>
        </div>

        <div class="grid max-h-96 gap-3 overflow-y-auto pr-1 sm:grid-cols-2">
            <template x-for="card in visibleCards()" :key="card.value">
                <div
                    x-show="matches(card)"
                    x-cloak
                    :class="activeTab === 'suggested' ? 'col-span-full' : ''"
                >
                    <button
                        type="button"
                        x-on:click="select(card.value)"
                        class="group flex h-full w-full items-start gap-3 rounded-2xl border bg-white p-4 text-left transition"
                        :class="isSelected(card.value)
                            ? 'border-[#008fd5] bg-[#008fd5]/5 ring-2 ring-[#008fd5]/15 border-2'
                            : 'border-gray-200 hover:border-primary-300 border-2 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800'"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl text-sm font-bold"
                            :class="isSelected(card.value)
                                ? 'bg-primary-400 text-white'
                                : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-200'"
                        >
                            @if ($getLeadingIcon())
                                <x-filament::icon :icon="$getLeadingIcon()" class="h-5 w-5" />
                            @else
                                <span x-text="card.leading ?? '•'"></span>
                            @endif
                        </div>

                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="card.title"></div>
                                    <div class="truncate text-xs text-gray-500 dark:text-gray-400" x-text="card.subtitle"></div>
                                </div>

                                <template x-if="card.badge">
                                    <span
                                        class="shrink-0 rounded-full border px-2 py-0.5 text-[11px] font-semibold"
                                        :class="activeTab === 'suggested' && card.isSuggested
                                            ? (card.suggestedBadgeClasses ?? card.badgeClasses ?? 'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200')
                                            : (card.badgeClasses ?? 'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200')"
                                        x-text="activeTab === 'suggested' && card.isSuggested
                                            ? (card.suggestedBadge ?? card.badge)
                                            : card.badge"
                                    ></span>
                                </template>
                            </div>

                            <template x-if="Array.isArray(card.meta) && card.meta.length">
                                <div class="flex flex-wrap gap-1.5 pt-1">
                                    <template x-for="metaItem in card.meta" :key="metaItem">
                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300" x-text="metaItem"></span>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </button>
                </div>
            </template>
        </div>

        <div
            x-show="visibleCards().length === 0"
            x-cloak
            class="rounded-xl border border-dashed border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400"
        >
            Không tìm thấy kết quả phù hợp.
        </div>
    </div>
</x-dynamic-component>
