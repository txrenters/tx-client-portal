<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronRight, Search, UserCheck, Users } from '@lucide/vue';
import { refDebounced } from '@vueuse/core';
import { ref, watch } from 'vue';
import AppCard from '@/components/AppCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import ResultsPagination from '@/components/ResultsPagination.vue';
import ConfirmAction from '@/components/admin/ConfirmAction.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { appDateFormatter } from '@/lib/timezone';
import {
    impersonate,
    index as usersRoute,
    show as showRoute,
} from '@/routes/admin/users';
import type { AdminUser, Paginated } from '@/types';
import { dashboard as adminHome } from '@/routes/admin';
import { index as usersCrumb } from '@/routes/admin/users';

/** The trail in the header beside the sidebar's collapse button. */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: adminHome() },
            { title: 'Users', href: usersCrumb() },
        ],
    },
});

const props = defineProps<{
    search: string;
    filter: 'suspended' | 'unverified' | null;
    users: Paginated<AdminUser>;
}>();

const search = ref(props.search);
const debounced = refDebounced(search, 300);
/** "all" stands in for no filter, since a select item cannot hold null. */
const filter = ref<string>(props.filter ?? 'all');

function reload(page?: number): void {
    router.get(
        usersRoute().url,
        {
            search: search.value || undefined,
            filter: filter.value === 'all' ? undefined : filter.value,
            page,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch([debounced, filter], () => reload());

const joined = appDateFormatter({
    year: 'numeric',
    month: 'short',
    day: 'numeric',
});
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Users" />

        <PageHeader
            title="Users"
            description="Everyone with a portal account. Open someone to help them with their login."
        />

        <div class="flex flex-wrap items-center gap-3">
            <div class="relative w-full max-w-sm">
                <Search
                    class="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2"
                />
                <Input
                    v-model="search"
                    class="pl-9"
                    placeholder="Search by name or email"
                    aria-label="Search users"
                />
            </div>
            <Select v-model="filter">
                <SelectTrigger class="w-44" aria-label="Filter users">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">Everyone</SelectItem>
                    <SelectItem value="unverified">Not confirmed</SelectItem>
                    <SelectItem value="suspended">Suspended</SelectItem>
                </SelectContent>
            </Select>
        </div>

        <AppCard>
            <EmptyState
                v-if="users.data.length === 0"
                :icon="Users"
                title="No users"
                :description="
                    search || filter !== 'all'
                        ? 'Nobody matches this search or filter.'
                        : 'People who sign up will show up here.'
                "
            />

            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>Person</TableHead>
                        <TableHead>Accounts</TableHead>
                        <TableHead>Apps</TableHead>
                        <TableHead>Joined</TableHead>
                        <TableHead class="text-right">
                            <span class="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="user in users.data" :key="user.id">
                        <TableCell>
                            <Link
                                :href="showRoute(user.id)"
                                class="font-medium hover:underline"
                                >{{ user.name }}</Link
                            >
                            <p class="text-muted-foreground text-sm">
                                {{ user.email }}
                            </p>
                            <div class="mt-1 flex flex-wrap gap-1">
                                <Badge
                                    v-if="user.suspendedAt"
                                    variant="destructive"
                                    >Suspended</Badge
                                >
                                <Badge
                                    :variant="
                                        user.verifiedAt
                                            ? 'secondary'
                                            : 'outline'
                                    "
                                >
                                    {{
                                        user.verifiedAt
                                            ? 'Confirmed'
                                            : 'Not confirmed'
                                    }}
                                </Badge>
                                <Badge v-if="user.twoFactor" variant="outline">
                                    Two-factor
                                </Badge>
                            </div>
                        </TableCell>
                        <TableCell>
                            <span
                                v-if="user.accounts.length === 0"
                                class="text-muted-foreground text-sm"
                            >
                                None linked
                            </span>
                            <div v-else class="flex flex-wrap gap-1">
                                <Badge
                                    v-for="account in user.accounts"
                                    :key="account.id"
                                    variant="outline"
                                    class="capitalize"
                                >
                                    {{ account.role }}
                                </Badge>
                            </div>
                        </TableCell>
                        <TableCell class="text-sm tabular-nums">
                            {{ user.appSessions }}
                        </TableCell>
                        <TableCell class="text-sm">
                            {{
                                user.joinedAt
                                    ? joined.format(new Date(user.joinedAt))
                                    : '—'
                            }}
                        </TableCell>
                        <TableCell>
                            <div class="flex flex-wrap justify-end gap-2">
                                <ConfirmAction
                                    v-if="!user.isStaff && !user.suspendedAt"
                                    :title="`Sign in as ${user.name}?`"
                                    description="You will see exactly what they see, and anything you do is recorded against them. Leave again from the banner as soon as you are done."
                                    confirm-label="Sign in as them"
                                    :action="impersonate(user.id)"
                                >
                                    <Button variant="outline" size="sm">
                                        <UserCheck class="size-4" />
                                        Sign in as
                                    </Button>
                                </ConfirmAction>
                                <Button as-child variant="ghost" size="sm">
                                    <Link :href="showRoute(user.id)">
                                        Manage
                                        <ChevronRight class="size-4" />
                                    </Link>
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <ResultsPagination
                :results="users"
                class="mt-4"
                @change="(page) => reload(page)"
            />
        </AppCard>
    </div>
</template>
