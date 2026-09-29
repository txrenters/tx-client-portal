<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { Gift, Search, Send } from '@lucide/vue';
import { refDebounced } from '@vueuse/core';
import { ref, watch } from 'vue';
import AppCard from '@/components/AppCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { appDateFormatter } from '@/lib/timezone';
import {
    index as referralsRoute,
    resendLead,
    reward as rewardRoute,
    update as updateRoute,
} from '@/routes/admin/referrals';
import type { AdminReferral, Paginated } from '@/types';
import { dashboard as adminHome } from '@/routes/admin';
import { index as referralsCrumb } from '@/routes/admin/referrals';

/** The trail in the header beside the sidebar's collapse button. */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: adminHome() },
            { title: 'Referrals', href: referralsCrumb() },
        ],
    },
});

const props = defineProps<{
    status: string | null;
    search: string;
    statuses: { value: AdminReferral['status']; label: string }[];
    reward: string;
    referrals: Paginated<AdminReferral>;
}>();

/** "all" stands in for no filter, since a select item cannot hold null. */
const statusFilter = ref(props.status ?? 'all');
const search = ref(props.search);
const debouncedSearch = refDebounced(search, 300);

function reload(page?: number): void {
    router.get(
        referralsRoute().url,
        {
            status:
                statusFilter.value === 'all' ? undefined : statusFilter.value,
            search: search.value || undefined,
            page,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch([statusFilter, debouncedSearch], () => reload());

function changeStatus(referral: AdminReferral, status: unknown): void {
    if (typeof status !== 'string' || status === referral.status) {
        return;
    }

    router.patch(
        updateRoute(referral.id).url,
        { status },
        { preserveScroll: true, only: ['referrals'] },
    );
}

const sent = appDateFormatter({
    year: 'numeric',
    month: 'short',
    day: 'numeric',
});
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Referrals" />

        <PageHeader
            title="Referrals"
            description="Property owners clients referred from the portal and the apps, and where we are with each."
        />

        <AppCard
            title="Referral reward"
            description="Shown to clients above the referral form, on the web and in the app. Leave it empty to use the printed card's wording."
        >
            <Form
                v-bind="rewardRoute.form()"
                :options="{ preserveScroll: true }"
                class="flex flex-col gap-3"
                v-slot="{ errors, processing }"
            >
                <Label for="reward" class="sr-only">Reward wording</Label>
                <Textarea
                    id="reward"
                    name="reward"
                    rows="2"
                    maxlength="500"
                    :default-value="reward"
                />
                <InputError :message="errors.reward" />
                <div>
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        <Gift v-else class="size-4" />
                        Save reward
                    </Button>
                </div>
            </Form>
        </AppCard>

        <div class="flex flex-wrap items-center gap-3">
            <div class="relative w-full max-w-sm">
                <Search
                    class="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2"
                />
                <Input
                    v-model="search"
                    class="pl-9"
                    placeholder="Search owner, address or referrer"
                    aria-label="Search referrals"
                />
            </div>
            <Select v-model="statusFilter">
                <SelectTrigger class="w-44" aria-label="Filter by status">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All statuses</SelectItem>
                    <SelectItem
                        v-for="option in statuses"
                        :key="option.value"
                        :value="option.value"
                        >{{ option.label }}</SelectItem
                    >
                </SelectContent>
            </Select>
        </div>

        <AppCard>
            <EmptyState
                v-if="referrals.data.length === 0"
                :icon="Gift"
                title="No referrals"
                :description="
                    status || search
                        ? 'Nothing matches this search or status.'
                        : 'Owners clients refer will show up here.'
                "
            />

            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>Property owner</TableHead>
                        <TableHead>Property</TableHead>
                        <TableHead>Referred by</TableHead>
                        <TableHead>Sent</TableHead>
                        <TableHead class="text-right">Status</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="referral in referrals.data"
                        :key="referral.id"
                        class="align-top"
                    >
                        <TableCell>
                            <p class="font-medium">{{ referral.name }}</p>
                            <a
                                :href="`mailto:${referral.email}`"
                                class="text-primary block text-sm hover:underline"
                                >{{ referral.email }}</a
                            >
                            <a
                                :href="`tel:${referral.phone}`"
                                class="text-muted-foreground block text-sm hover:underline"
                                >{{ referral.phone }}</a
                            >
                        </TableCell>
                        <TableCell class="max-w-72 whitespace-normal">
                            <p class="text-sm">
                                {{ referral.propertyAddress }}
                            </p>
                            <p
                                v-if="referral.propertyDetails"
                                class="text-muted-foreground mt-1 text-sm whitespace-pre-line"
                            >
                                {{ referral.propertyDetails }}
                            </p>
                        </TableCell>
                        <TableCell>
                            <p class="text-sm">{{ referral.referrerName }}</p>
                            <Badge variant="outline" class="mt-1 capitalize">{{
                                referral.referrerRole
                            }}</Badge>
                        </TableCell>
                        <TableCell class="text-sm">
                            {{ sent.format(new Date(referral.sentAt)) }}
                            <Badge
                                v-if="referral.leadSentAt"
                                variant="secondary"
                                class="mt-1 block w-fit"
                                >In leads</Badge
                            >
                            <Badge
                                v-else-if="referral.leadError"
                                variant="destructive"
                                class="mt-1 block w-fit"
                                :title="referral.leadError"
                                >Not in leads yet</Badge
                            >
                            <Form
                                v-if="!referral.leadSentAt && referral.leadError"
                                v-bind="resendLead.form(referral.id)"
                                :options="{ preserveScroll: true }"
                                class="mt-1"
                                #default="{ processing }"
                            >
                                <Button
                                    type="submit"
                                    variant="link"
                                    size="sm"
                                    class="h-auto p-0"
                                    :disabled="processing"
                                >
                                    <Spinner v-if="processing" class="size-3" />
                                    <Send v-else class="size-3" />
                                    Send again
                                </Button>
                            </Form>
                        </TableCell>
                        <TableCell class="text-right">
                            <Select
                                :model-value="referral.status"
                                @update:model-value="
                                    changeStatus(referral, $event)
                                "
                            >
                                <SelectTrigger
                                    class="ml-auto w-36"
                                    :aria-label="`Status for ${referral.name}`"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in statuses"
                                        :key="option.value"
                                        :value="option.value"
                                        >{{ option.label }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <div
                v-if="referrals.last_page > 1"
                class="flex items-center justify-between gap-3 pt-4 text-sm"
            >
                <span class="text-muted-foreground"
                    >{{ referrals.from }}–{{ referrals.to }} of
                    {{ referrals.total }}</span
                >
                <div class="flex gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="referrals.current_page <= 1"
                        @click="reload(referrals.current_page - 1)"
                        >Previous</Button
                    >
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="
                            referrals.current_page >= referrals.last_page
                        "
                        @click="reload(referrals.current_page + 1)"
                        >Next</Button
                    >
                </div>
            </div>
        </AppCard>
    </div>
</template>
