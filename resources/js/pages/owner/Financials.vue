<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, onUnmounted, ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useFormattedDate } from '@/composables/useFormattedDate';
import { PERIOD_PRESETS, presetDates } from '@/lib/dateRange';
import { dashboard, financials as financialsRoute } from '@/routes/owner';
import OwnerLedgerPanel from '@/components/OwnerLedgerPanel.vue';
import { Spinner } from '@/components/ui/spinner';
import type { OwnerFinancials, OwnerLedgerData, OwnerRevenue } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Home', href: dashboard() },
            { title: 'My Ledger', href: financialsRoute() },
        ],
    },
});

const props = defineProps<{
    filters: {
        portfolios: number[];
        properties: number[];
        status: 'all' | 'paid' | 'unpaid';
        range: string | null;
        from: string | null;
        to: string | null;
    };
    /** Undefined while loading, null when it couldn't be read. */
    financials?: OwnerFinancials | null;
    revenue?: OwnerRevenue | null;
    ledger?: OwnerLedgerData | null;
    portfolios?: { id: number; name: string; properties: string[] | null }[];
    properties?: { id: number; name: string; portfolioId: number | null }[];
}>();

const { formatDate } = useFormattedDate();

/** The portfolio the ledger is showing, named as Propertyware names it. */

/** The period on screen, written the way Propertyware writes it. */
const ledgerPeriod = computed<string | null>(() =>
    props.ledger
        ? `${formatDate(props.ledger.from)} – ${formatDate(props.ledger.to)}`
        : null,
);

/** The portfolios ticked in the menu; empty means all of them. */
const chosen = ref<number[]>([...props.filters.portfolios]);

/** Which bills the list shows: all of them, or only those still owing. */
const status = ref(props.filters.status ?? 'all');

const isLoading = ref(false);

function toggle(id: number): void {
    chosen.value = chosen.value.includes(id)
        ? chosen.value.filter((chosenId) => chosenId !== id)
        : [...chosen.value, id];
}

type Filters = {
    portfolios: number[];
    properties: number[];
    status: 'all' | 'paid' | 'unpaid';
    range: string | null;
    from: string | null;
    to: string | null;
};

/**
 * The query a visit carries. Every visit builds it from the same place, so a
 * background refresh asks for exactly what the page is already showing rather
 * than quietly dropping back to everything.
 */
function query(
    filters: Filters,
    page = 1,
): Record<string, string | number | number[]> {
    return {
        ...(filters.portfolios.length === 0
            ? {}
            : { portfolios: filters.portfolios }),
        ...(filters.properties.length === 0
            ? {}
            : { properties: filters.properties }),
        ...(filters.status === 'all' ? {} : { status: filters.status }),
        ...(filters.from ? { from: filters.from } : {}),
        ...(filters.to ? { to: filters.to } : {}),
        ...(filters.range ? { range: filters.range } : {}),
        ...(page > 1 ? { page } : {}),
    };
}

/** The filters as the controls currently read, before they are applied. */
function picked(): Filters {
    const dates = presetDates(range.value, from.value, to.value);

    return {
        portfolios: chosen.value,
        properties: chosenProperties.value,
        status: status.value,
        range: range.value === 'custom' ? null : range.value,
        from: dates.from ?? null,
        to: dates.to ?? null,
    };
}

function apply(): void {
    isLoading.value = true;

    router.get(financialsRoute().url, query(picked()), {
        preserveState: true,
        preserveScroll: true,
        only: ['ledger', 'filters'],
        onFinish: () => {
            isLoading.value = false;
        },
    });
}

function clear(): void {
    chosen.value = [];
    chosenProperties.value = [];
    status.value = 'all';
    range.value = 'this-month';
    from.value = '';
    to.value = '';
    apply();
}

/** The properties ticked in the menu; empty means all of them. */
const chosenProperties = ref<number[]>([...props.filters.properties]);

function toggleProperty(id: number): void {
    chosenProperties.value = chosenProperties.value.includes(id)
        ? chosenProperties.value.filter((chosenId) => chosenId !== id)
        : [...chosenProperties.value, id];
}

const propertySearch = ref('');

/**
 * The properties the menu offers: only those in the ticked portfolios, since a
 * portfolio can hold several buildings and picking one should narrow the rest.
 */
const inChosenPortfolios = computed(() =>
    chosen.value.length === 0
        ? (props.properties ?? [])
        : (props.properties ?? []).filter(
              (item) =>
                  item.portfolioId !== null &&
                  chosen.value.includes(item.portfolioId),
          ),
);

const shownProperties = computed(() => {
    const term = propertySearch.value.trim().toLocaleLowerCase();
    const all = inChosenPortfolios.value;

    return term === ''
        ? all
        : all.filter((item) => item.name.toLocaleLowerCase().includes(term));
});

// A property ticked before its portfolio was unticked would keep filtering the
// page from a menu that no longer shows it, so it is dropped with the portfolio.
// Nothing is dropped while the list is still loading, or a tick made early
// would be thrown away before we know which portfolio it belongs to.
watch(chosen, () => {
    if (props.properties === undefined) {
        return;
    }

    const offered = inChosenPortfolios.value.map((item) => item.id);

    chosenProperties.value = chosenProperties.value.filter((id) =>
        offered.includes(id),
    );
});

const propertyLabel = computed(() => {
    if (chosenProperties.value.length === 0) {
        return chosen.value.length === 0
            ? 'All properties'
            : 'All in these portfolios';
    }

    const picked = (props.properties ?? []).filter((item) =>
        chosenProperties.value.includes(item.id),
    );

    if (chosenProperties.value.length === 1) {
        return picked.length === 1 ? picked[0].name : '1 property';
    }

    return `${chosenProperties.value.length} properties`;
});

const chosenLabel = computed(() => {
    if (chosen.value.length === 0) {
        return 'All portfolios';
    }

    // The names load after the page, so count what is ticked, not what we can
    // yet name: a freshly opened link would otherwise read "0 portfolios".
    const picked = (props.portfolios ?? []).filter((item) =>
        chosen.value.includes(item.id),
    );

    if (chosen.value.length === 1) {
        return picked.length === 1 ? portfolioLabel(picked[0]) : '1 portfolio';
    }

    return `${chosen.value.length} portfolios`;
});

/** "BX - Fu, Zhaohui · 19319 Hays Spring Dr", so the owner knows which one. */
function portfolioLabel(portfolio: {
    name: string;
    properties: string[] | null;
}): string {
    // Null means we haven't read them, which is not the same as having none.
    if (portfolio.properties === null) {
        return portfolio.name;
    }

    if (portfolio.properties.length === 0) {
        return `${portfolio.name} · no property yet`;
    }

    return portfolio.properties.length > 2
        ? `${portfolio.name} · ${portfolio.properties.length} properties`
        : `${portfolio.name} · ${portfolio.properties.join(', ')}`;
}

/**
 * Which preset the period control shows. Dates carried on their own — a link
 * someone kept, or a range picked by hand — are a custom period, not the month
 * the page opens on.
 */
function appliedRange(applied: {
    range: string | null;
    from: string | null;
}): string {
    if (applied.range !== null) {
        return applied.range;
    }

    return applied.from === null ? 'this-month' : 'custom';
}

const range = ref(appliedRange(props.filters));
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');

/** Whether anything is narrowing the ledger from its default view. */
const isFiltered = computed(
    () =>
        chosen.value.length > 0 ||
        chosenProperties.value.length > 0 ||
        (range.value !== 'this-month' && range.value !== ''),
);

/**
 * Whether the controls already match what the server has applied.
 *
 * The bar is kept in step with the server's answer, so without this a reply
 * would move the controls, the watcher would fire, and the page would ask
 * again forever.
 */
function matchesApplied(): boolean {
    const picked_ = picked();
    const applied = props.filters;

    return (
        picked_.portfolios.join() === applied.portfolios.join() &&
        picked_.properties.join() === applied.properties.join() &&
        (picked_.range ?? '') === (applied.range ?? '') &&
        (picked_.from ?? '') === (applied.from ?? '') &&
        (picked_.to ?? '') === (applied.to ?? '')
    );
}

/**
 * Whether the ledger on screen is about to be replaced: from the moment a
 * filter is picked, through the pause before it is sent, until the new one
 * arrives.
 */
const isRefreshing = computed(() => isLoading.value || !matchesApplied());

// Filtering applies itself, as it does on the properties page: picking a
// period or a property asks the server straight away rather than waiting for
// a button. Typing into the custom dates pauses first so a half-typed year
// never gets sent.
watchDebounced(
    [chosen, chosenProperties, range, from, to],
    () => {
        if (matchesApplied()) {
            return;
        }

        apply();
    },
    { debounce: 300, deep: true },
);

/**
 * Follow the filters the server actually applied.
 *
 * The controls are the client's own state so they can be changed without a
 * round trip, but a visit that carries different filters — turning a page,
 * coming back to the page, a link with a period in it — has the last word,
 * so the bar never disagrees with the figures under it.
 */
watch(
    () => props.filters,
    (applied) => {
        chosen.value = [...applied.portfolios];
        chosenProperties.value = [...applied.properties];
        status.value = applied.status ?? 'all';
        range.value = appliedRange(applied);
        from.value = applied.from ?? '';
        to.value = applied.to ?? '';
    },
);

const pollTimer = ref<ReturnType<typeof setInterval> | null>(null);

/**
 * While the import runs, check back for it without a page reload.
 *
 * The refresh asks for the filters that are applied and leaves the address bar
 * alone, so one landing late can neither undo a filter the client just set nor
 * put them back on the whole account.
 */
function refresh(): void {
    if (isLoading.value) {
        return;
    }

    router.get(
        financialsRoute().url,
        query(props.filters, props.financials?.results.current_page ?? 1),
        {
            only: ['revenue', 'financials'],
            preserveState: true,
            preserveScroll: true,
            preserveUrl: true,
            async: true,
            showProgress: false,
        },
    );
}

watch(
    () => props.revenue?.importing,
    (importing) => {
        if (importing && pollTimer.value === null) {
            pollTimer.value = setInterval(refresh, 5000);

            return;
        }

        if (!importing && pollTimer.value !== null) {
            clearInterval(pollTimer.value);
            pollTimer.value = null;
        }
    },
    { immediate: true },
);

onUnmounted(() => {
    if (pollTimer.value !== null) {
        clearInterval(pollTimer.value);
    }
});

const portfolioSearch = ref('');

/** The portfolios on offer, narrowed by whatever was typed. */
const shownPortfolios = computed(() => {
    const term = portfolioSearch.value.trim().toLocaleLowerCase();
    const all = props.portfolios ?? [];

    return term === ''
        ? all
        : all.filter((item) =>
              portfolioLabel(item).toLocaleLowerCase().includes(term),
          );
});

const hasSeveralPortfolios = computed(
    () => (props.portfolios?.length ?? 0) > 1,
);
</script>

<template>
    <div class="flex min-w-0 flex-col gap-6 p-4 md:p-6">
        <Head title="My Ledger" />

        <PageHeader
            title="My Ledger"
            :description="
                ledgerPeriod ?? 'What came in, what went out, and what it left.'
            "
        />

        <div
            class="flex flex-col items-stretch gap-2 sm:flex-row sm:flex-wrap sm:items-end"
        >
            <div
                v-if="hasSeveralPortfolios"
                class="grid w-full gap-1.5 sm:w-auto"
            >
                <span class="text-sm font-medium">Portfolios</span>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="outline"
                            class="w-full justify-between sm:w-96"
                        >
                            <span class="truncate">{{ chosenLabel }}</span>
                            <ChevronDown class="size-4 shrink-0 opacity-60" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        class="max-h-80 w-(--reka-dropdown-menu-trigger-width) overflow-y-auto"
                    >
                        <!-- Ninety portfolios is a lot to scroll past. -->
                        <div
                            v-if="(portfolios?.length ?? 0) > 8"
                            class="bg-popover sticky top-0 z-10 p-1"
                            @keydown.stop
                        >
                            <Input
                                v-model="portfolioSearch"
                                type="search"
                                placeholder="Search portfolios"
                                class="h-8"
                            />
                        </div>

                        <DropdownMenuCheckboxItem
                            v-for="item in shownPortfolios"
                            :key="item.id"
                            :model-value="chosen.includes(item.id)"
                            @select="(event: Event) => event.preventDefault()"
                            @update:model-value="() => toggle(item.id)"
                        >
                            {{ portfolioLabel(item) }}
                        </DropdownMenuCheckboxItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
            <div class="grid w-full gap-1.5 sm:w-auto">
                <span class="text-sm font-medium">Properties</span>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="outline"
                            class="w-full justify-between sm:w-72"
                        >
                            <span class="truncate">{{ propertyLabel }}</span>
                            <ChevronDown class="size-4 shrink-0 opacity-60" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        class="max-h-80 w-(--reka-dropdown-menu-trigger-width) overflow-y-auto"
                    >
                        <div
                            v-if="inChosenPortfolios.length > 8"
                            class="bg-popover sticky top-0 z-10 p-1"
                            @keydown.stop
                        >
                            <Input
                                v-model="propertySearch"
                                type="search"
                                placeholder="Search properties"
                                class="h-8"
                            />
                        </div>

                        <DropdownMenuCheckboxItem
                            v-for="item in shownProperties"
                            :key="item.id"
                            :model-value="chosenProperties.includes(item.id)"
                            @select="(event: Event) => event.preventDefault()"
                            @update:model-value="() => toggleProperty(item.id)"
                        >
                            {{ item.name }}
                        </DropdownMenuCheckboxItem>

                        <p
                            v-if="shownProperties.length === 0"
                            class="text-muted-foreground px-2 py-3 text-sm"
                        >
                            {{
                                properties === undefined
                                    ? 'Loading properties…'
                                    : 'No property matches.'
                            }}
                        </p>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <div class="grid w-full gap-1.5 sm:w-auto">
                <span class="text-sm font-medium">Period</span>
                <Select v-model="range">
                    <SelectTrigger class="w-full sm:w-48">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent class="max-h-96">
                        <SelectGroup
                            v-for="group in PERIOD_PRESETS"
                            :key="group.label"
                        >
                            <SelectLabel>{{ group.label }}</SelectLabel>
                            <SelectItem
                                v-for="option in group.options"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectGroup>
                    </SelectContent>
                </Select>
            </div>

            <div
                v-if="range === 'custom'"
                class="grid w-full gap-1.5 sm:w-auto"
            >
                <span class="text-sm font-medium">From</span>
                <Input v-model="from" type="date" :max="to || undefined" />
            </div>

            <div
                v-if="range === 'custom'"
                class="grid w-full gap-1.5 sm:w-auto"
            >
                <span class="text-sm font-medium">To</span>
                <Input v-model="to" type="date" :min="from || undefined" />
            </div>

            <Button
                v-if="isFiltered"
                variant="ghost"
                :disabled="isLoading"
                @click="clear"
            >
                Clear filters
            </Button>

            <p
                v-if="isRefreshing"
                class="text-muted-foreground flex h-9 items-center gap-2 text-sm"
                role="status"
            >
                <Spinner class="size-4" />
                Updating…
            </p>
        </div>

        <OwnerLedgerPanel
            :ledger="ledger"
            class="transition-opacity"
            :class="{ 'pointer-events-none opacity-50': isRefreshing }"
            :aria-busy="isRefreshing"
        />
    </div>
</template>
