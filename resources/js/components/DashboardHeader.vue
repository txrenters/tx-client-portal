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
        class="bg-scrim text-scrim-foreground flex min-w-0 flex-col items-start gap-3 rounded-2xl p-5 sm:p-7"
    >
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
