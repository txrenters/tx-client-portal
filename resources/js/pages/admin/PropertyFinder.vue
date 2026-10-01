<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Building2, MailPlus, Search } from '@lucide/vue';
import { refDebounced } from '@vueuse/core';
import { ref, watch } from 'vue';
import AppCard from '@/components/AppCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { dashboard as adminHome, properties } from '@/routes/admin';
import { index as invitationsRoute } from '@/routes/admin/invitations';
import { show as showUser } from '@/routes/admin/users';
import type { AdminPropertyMatch, AdminPropertyPerson } from '@/types';

/** The trail in the header beside the sidebar's collapse button. */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: adminHome() },
            { title: 'Find by property', href: properties() },
        ],
    },
});

const props = defineProps<{
    search: string;
    maxResults: number;
    /** Undefined while Propertyware is being searched; null before a search. */
    found?: {
        results: AdminPropertyMatch[];
        total: number;
        error: string | null;
    } | null;
}>();

const search = ref(props.search);
const debounced = refDebounced(search, 400);

watch(debounced, (value) => {
    router.get(
        properties().url,
        { search: value || undefined },
        { preserveState: true, replace: true },
    );
});

function inviteLink(person: AdminPropertyPerson) {
    return invitationsRoute({
        query: {
            invite: '1',
            email: person.email ?? '',
            name: person.name,
            role: person.role,
            contact: person.contactId,
        },
    });
}
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Find by property" />

        <PageHeader
            title="Find by property"
            description="Search an address to see its owners and current tenants, and whether each of them is on the portal."
        />

        <div class="relative w-full max-w-md">
            <Search
                class="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2"
            />
            <Input
                v-model="search"
                class="pl-9"
                placeholder="Street address, e.g. 12009 Tambourine"
                aria-label="Search properties"
            />
        </div>

        <AppCard v-if="search.trim().length < 3">
            <EmptyState
                :icon="Building2"
                title="Search for a property"
                description="Type at least three characters of the street address."
            />
        </AppCard>

        <div v-else-if="found === undefined" class="flex flex-col gap-4">
            <Skeleton
                v-for="row in 3"
                :key="row"
                class="h-32 w-full rounded-xl"
            />
        </div>

        <template v-else-if="found">
            <AppCard v-if="found.error">
                <p class="text-destructive text-sm">{{ found.error }}</p>
            </AppCard>

            <AppCard v-else-if="found.results.length === 0">
                <EmptyState
                    :icon="Building2"
                    title="No properties match"
                    description="Try fewer words, or just the house number and street."
                />
            </AppCard>

            <template v-else>
                <p
                    v-if="found.total > found.results.length"
                    class="text-muted-foreground text-sm"
                >
                    Showing the first {{ found.results.length }} of
                    {{ found.total }} matches. Add more of the address to narrow
                    it down.
                </p>

                <AppCard
                    v-for="property in found.results"
                    :key="property.id"
                    :title="
                        property.address ||
                        property.name ||
                        `Property ${property.id}`
                    "
                    :description="
                        property.name && property.address
                            ? property.name
                            : undefined
                    "
                >
                    <Badge
                        :variant="property.occupied ? 'secondary' : 'outline'"
                        class="mb-4"
                        >{{ property.occupied ? 'Leased' : 'Vacant' }}</Badge
                    >

                    <div class="grid gap-6 md:grid-cols-2">
                        <div
                            v-for="group in [
                                { label: 'Owners', people: property.owners },
                                {
                                    label: 'Tenants on current leases',
                                    people: property.residents,
                                },
                            ]"
                            :key="group.label"
                        >
                            <p
                                class="text-muted-foreground mb-2 text-xs font-medium tracking-wider uppercase"
                            >
                                {{ group.label }}
                            </p>
                            <p
                                v-if="group.people.length === 0"
                                class="text-muted-foreground text-sm"
                            >
                                None on file.
                            </p>
                            <ul v-else class="divide-border/60 divide-y">
                                <li
                                    v-for="person in group.people"
                                    :key="person.contactId"
                                    class="flex flex-wrap items-center justify-between gap-2 py-2"
                                >
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium">
                                            {{ person.name }}
                                        </p>
                                        <p
                                            class="text-muted-foreground text-xs"
                                        >
                                            {{ person.email ?? 'No email' }} ·
                                            #{{ person.contactId }}
                                        </p>
                                    </div>
                                    <Button
                                        v-if="person.linkedUserId"
                                        as-child
                                        variant="outline"
                                        size="sm"
                                    >
                                        <Link
                                            :href="
                                                showUser(person.linkedUserId)
                                            "
                                            >On the portal</Link
                                        >
                                    </Button>
                                    <Badge
                                        v-else-if="person.pendingInvitation"
                                        variant="secondary"
                                        >Invited</Badge
                                    >
                                    <Button
                                        v-else-if="person.email"
                                        as-child
                                        size="sm"
                                    >
                                        <Link :href="inviteLink(person)">
                                            <MailPlus class="size-4" />
                                            Invite
                                        </Link>
                                    </Button>
                                    <Badge v-else variant="outline"
                                        >Not on the portal</Badge
                                    >
                                </li>
                            </ul>
                        </div>
                    </div>
                </AppCard>
            </template>
        </template>
    </div>
</template>
