<script setup lang="ts">
import { Form, Head, InfiniteScroll } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import { Gift, UserPlus } from '@lucide/vue';
import AppCard from '@/components/AppCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { GIFT_CARD_NOTICE } from '@/lib/referrals';
import { appDateFormatter } from '@/lib/timezone';
import { dashboard as ownerDashboard } from '@/routes/owner';
import {
    index as ownerReferrals,
    store as ownerStore,
} from '@/routes/owner/referrals';
import { dashboard as residentDashboard } from '@/routes/resident';
import {
    index as residentReferrals,
    store as residentStore,
} from '@/routes/resident/referrals';
import type { Referral } from '@/types';

/**
 * Refer a property owner to TexasRenters.com. The same page for owners and
 * residents; the apps use /api/v1/accounts/{account}/referrals.
 */
defineOptions({
    layout: ({ portal }: { portal: 'owner' | 'resident' }) => ({
        breadcrumbs: [
            {
                title: 'Home',
                href:
                    portal === 'owner' ? ownerDashboard() : residentDashboard(),
            },
            {
                title: 'Refer a friend',
                href:
                    portal === 'owner' ? ownerReferrals() : residentReferrals(),
            },
        ],
    }),
});

const props = defineProps<{
    portal: 'owner' | 'resident';
    /** What staff offer for a referral; they edit it in the admin area. */
    reward: string;
    /** Loaded page by page as the list scrolls. */
    referrals?: { data: Referral[] };
}>();

const store = props.portal === 'owner' ? ownerStore : residentStore;

const sentFormatter = appDateFormatter({ dateStyle: 'medium' });

/**
 * The referral form opens over the page. The sidebar's Refer an owner button
 * lands here with ?refer=1, which opens it straight away.
 */
const referOpen = ref(false);

onMounted(() => {
    referOpen.value =
        new URLSearchParams(window.location.search).get('refer') === '1';
});

/** Signed is the good news; not a fit is quiet rather than alarming. */
function statusVariant(
    status: Referral['status'],
): 'default' | 'secondary' | 'outline' {
    if (status === 'signed') {
        return 'default';
    }

    return status === 'not_a_fit' ? 'outline' : 'secondary';
}
</script>

<template>
    <Head title="Refer a friend" />

    <div class="flex flex-col gap-6 p-4">
        <PageHeader
            title="Refer a friend"
            description="Know a property owner who needs a property manager? Tell us who they are and we'll take it from there."
        >
            <template #actions>
                <Button @click="referOpen = true">
                    <UserPlus class="size-4" aria-hidden="true" />
                    Refer an owner
                </Button>
            </template>
        </PageHeader>

        <!-- The same blue gradient as the dashboard header. -->
        <Alert
            class="from-primary to-ring text-primary-foreground border-0 bg-gradient-to-br p-5 shadow-sm"
        >
            <Gift class="text-primary-foreground" />
            <AlertTitle class="text-base font-semibold"
                >Referral reward</AlertTitle
            >
            <AlertDescription
                class="text-primary-foreground/90 flex flex-col gap-1 text-sm"
            >
                <span class="whitespace-pre-line">{{ reward }}</span>
                <span class="text-primary-foreground/75 text-xs">{{
                    GIFT_CARD_NOTICE
                }}</span>
            </AlertDescription>
        </Alert>

        <Dialog v-model:open="referOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Refer a property owner</DialogTitle>
                    <DialogDescription>
                        Their details, so our team can reach out. We'll mention
                        you referred them.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    :action="store()"
                    reset-on-success
                    @success="referOpen = false"
                    :options="{
                        preserveScroll: true,
                        only: ['referrals'],
                        reset: ['referrals'],
                    }"
                    class="grid gap-4 sm:grid-cols-2"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-2">
                        <Label for="first_name">First name</Label>
                        <Input
                            id="first_name"
                            name="first_name"
                            autocomplete="off"
                            placeholder="Jane"
                            required
                        />
                        <InputError :message="errors.first_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="last_name">Last name</Label>
                        <Input
                            id="last_name"
                            name="last_name"
                            autocomplete="off"
                            placeholder="Doe"
                            required
                        />
                        <InputError :message="errors.last_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">Email address</Label>
                        <Input
                            id="email"
                            name="email"
                            type="email"
                            autocomplete="off"
                            placeholder="owner@email.com"
                            required
                        />
                        <InputError :message="errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="phone">Phone number</Label>
                        <Input
                            id="phone"
                            name="phone"
                            type="tel"
                            autocomplete="off"
                            placeholder="(281) 555-0123"
                            required
                        />
                        <InputError :message="errors.phone" />
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="property_address"
                            >Rental property address</Label
                        >
                        <Input
                            id="property_address"
                            name="property_address"
                            autocomplete="off"
                            placeholder="123 Main St, Houston, TX 77007"
                            required
                        />
                        <InputError :message="errors.property_address" />
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="property_details">
                            Property details
                            <span class="text-muted-foreground font-normal"
                                >(optional)</span
                            >
                        </Label>
                        <Textarea
                            id="property_details"
                            name="property_details"
                            rows="3"
                            maxlength="2000"
                            placeholder="Beds and baths, when it's available, anything that helps"
                        />
                        <InputError :message="errors.property_details" />
                    </div>

                    <div class="sm:col-span-2">
                        <Button type="submit" :disabled="processing">
                            <Spinner v-if="processing" />
                            <UserPlus v-else class="size-4" />
                            Send referral
                        </Button>
                    </div>
                </Form>
            </DialogContent>
        </Dialog>

        <AppCard
            title="Your referrals"
            description="Where our team is with each owner you've sent."
        >
            <div
                v-if="referrals === undefined"
                class="flex flex-col gap-2"
                role="status"
                aria-label="Loading referrals"
            >
                <Skeleton
                    v-for="item in 3"
                    :key="item"
                    class="h-14 w-full rounded-xl"
                />
            </div>

            <EmptyState
                v-else-if="referrals.data.length === 0"
                :icon="UserPlus"
                title="No referrals yet"
                description="Owners you refer will show up here."
            >
                <Button variant="outline" @click="referOpen = true">
                    <UserPlus class="size-4" aria-hidden="true" />
                    Refer an owner
                </Button>
            </EmptyState>

            <InfiniteScroll
                v-else
                data="referrals"
                as="ul"
                class="divide-border/60 divide-y"
            >
                <li
                    v-for="referral in referrals.data"
                    :key="referral.id"
                    class="flex items-start justify-between gap-3 py-3"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">
                            {{ referral.name }}
                        </p>
                        <p class="text-muted-foreground truncate text-sm">
                            {{ referral.propertyAddress }}
                        </p>
                        <p class="text-muted-foreground text-xs">
                            Sent
                            {{
                                sentFormatter.format(new Date(referral.sentAt))
                            }}
                        </p>
                    </div>
                    <Badge :variant="statusVariant(referral.status)">{{
                        referral.statusLabel
                    }}</Badge>
                </li>
            </InfiniteScroll>
        </AppCard>
    </div>
</template>
