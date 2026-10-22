<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Gift } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { GIFT_CARD_NOTICE } from '@/lib/referrals';
import { index as ownerReferrals } from '@/routes/owner/referrals';

/**
 * The referral offer, always at the foot of an owner's sidebar: there is no
 * way to close it. Hidden while the sidebar is folded down to icons, where it
 * cannot fit.
 */
const page = usePage();
const reward = computed(() => page.props.client?.referralReward ?? null);
</script>

<template>
    <div
        v-if="reward"
        class="from-primary to-ring text-primary-foreground flex flex-col gap-2 rounded-lg bg-gradient-to-br p-3 shadow-sm group-data-[collapsible=icon]:hidden"
    >
        <div class="flex items-center gap-2 text-sm font-semibold">
            <Gift class="size-4 shrink-0" aria-hidden="true" />
            Refer a friend
        </div>
        <p
            class="text-primary-foreground/90 text-xs leading-relaxed whitespace-pre-line"
        >
            {{ reward }}
        </p>
        <p class="text-primary-foreground/75 text-[11px] leading-snug">
            {{ GIFT_CARD_NOTICE }}
        </p>
        <Button as-child variant="secondary" size="sm" class="w-full">
            <Link :href="ownerReferrals()">Refer an owner</Link>
        </Button>
    </div>
</template>
