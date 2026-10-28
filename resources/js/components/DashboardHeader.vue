<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { appHour } from '@/lib/timezone';

defineProps<{
    description?: string;
    portalLabel?: string;
}>();

const page = usePage();
const accountName = computed(
    () =>
        page.props.client?.accounts.find((account) => account.active)?.label ??
        page.props.auth.user.name.split(' ')[0],
);

const greeting = computed(() => {
    const hour = appHour();

    if (hour < 12) {
        return 'Good morning';
    }

    return hour < 18 ? 'Good afternoon' : 'Good evening';
});
</script>

<template>
    <header
        class="from-hero-from via-hero-via to-hero-to text-scrim-foreground relative isolate flex min-w-0 flex-col items-start gap-3 overflow-hidden rounded-2xl bg-linear-to-br p-5 shadow-lg sm:p-7"
    >
        <div
            class="pointer-events-none absolute inset-0 -z-10"
            aria-hidden="true"
        >
            <div
                class="bg-success/40 absolute -top-16 -right-10 size-56 rounded-full blur-3xl"
            />
            <div
                class="bg-tone-blue/40 absolute -bottom-24 left-1/3 size-64 rounded-full blur-3xl"
            />
            <div
                class="bg-scrim-foreground/10 absolute top-6 right-1/4 size-24 rounded-full blur-2xl"
            />
        </div>
        <div
            v-if="portalLabel || $slots.actions"
            class="flex flex-wrap items-center gap-3"
        >
            <Badge
                v-if="portalLabel"
                variant="outline"
                class="border-scrim-foreground/30 bg-scrim-foreground/10 text-scrim-foreground gap-1.5 py-1"
            >
                <span class="bg-success size-2 rounded-full" />
                {{ portalLabel }}
            </Badge>
            <slot name="actions" />
        </div>

        <h1
            class="font-heading text-3xl font-semibold tracking-tight text-balance wrap-anywhere"
        >
            {{ greeting }},
            <span class="opacity-90">{{ accountName }}</span>
        </h1>

        <p v-if="description" class="text-scrim-foreground/80 text-pretty">
            {{ description }}
        </p>
    </header>
</template>
