<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { KeyRound, Link2Off, Search, Smartphone, UserCheck } from '@lucide/vue';
import { ref, watch } from 'vue';
import { refDebounced } from '@vueuse/core';
import AppCard from '@/components/AppCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { appDateFormatter } from '@/lib/timezone';
import { index as usersRoute } from '@/routes/admin/users';
import {
    impersonate,
    passwordReset,
    relinkAccounts,
    revokeAppSessions,
} from '@/routes/admin/users';
import type { AdminUser, Paginated } from '@/types';

const props = defineProps<{
    search: string;
    users: Paginated<AdminUser>;
}>();

const search = ref(props.search);
const debounced = refDebounced(search, 300);

watch(debounced, (value) => {
    router.get(
        usersRoute().url,
        { search: value },
        { preserveState: true, replace: true, only: ['users', 'search'] },
    );
});

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
            description="Everyone with a portal account, and the support actions you can take on one."
        />

        <div class="relative max-w-sm">
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

        <AppCard>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Person</TableHead>
                        <TableHead>Accounts</TableHead>
                        <TableHead>Apps</TableHead>
                        <TableHead>Joined</TableHead>
                        <TableHead class="text-right">Support</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="user in users.data" :key="user.id">
                        <TableCell>
                            <p class="font-medium">{{ user.name }}</p>
                            <p class="text-muted-foreground text-sm">
                                {{ user.email }}
                            </p>
                            <div class="mt-1 flex flex-wrap gap-1">
                                <Badge
                                    :variant="
                                        user.verifiedAt
                                            ? 'secondary'
                                            : 'destructive'
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
                                <Form
                                    v-bind="passwordReset.form(user.id)"
                                    #default="{ processing }"
                                >
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="sm"
                                        :disabled="processing"
                                    >
                                        <Spinner
                                            v-if="processing"
                                            class="size-4"
                                        />
                                        <KeyRound v-else class="size-4" />
                                        Reset password
                                    </Button>
                                </Form>
                                <Form
                                    v-bind="revokeAppSessions.form(user.id)"
                                    #default="{ processing }"
                                >
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="sm"
                                        :disabled="
                                            processing || user.appSessions === 0
                                        "
                                    >
                                        <Spinner
                                            v-if="processing"
                                            class="size-4"
                                        />
                                        <Smartphone v-else class="size-4" />
                                        Sign out apps
                                    </Button>
                                </Form>
                                <Form
                                    v-bind="impersonate.form(user.id)"
                                    #default="{ processing }"
                                >
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="sm"
                                        :disabled="processing"
                                    >
                                        <Spinner
                                            v-if="processing"
                                            class="size-4"
                                        />
                                        <UserCheck v-else class="size-4" />
                                        Sign in as
                                    </Button>
                                </Form>
                                <Form
                                    v-bind="relinkAccounts.form(user.id)"
                                    #default="{ processing }"
                                >
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="sm"
                                        :disabled="processing"
                                    >
                                        <Spinner
                                            v-if="processing"
                                            class="size-4"
                                        />
                                        <Link2Off v-else class="size-4" />
                                        Re-link
                                    </Button>
                                </Form>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <p class="text-muted-foreground mt-4 text-sm text-pretty">
                Signing in as someone shows you exactly what they see, and
                everything you do is recorded against them, so leave it again as
                soon as you are done. Staff accounts cannot be signed in to.
                Resetting a password emails the same link a client would request
                themselves. Signing out of apps ends mobile sessions only, not
                the browser. Re-linking forgets which Propertyware contacts the
                person matched, and they are matched again on their next visit.
            </p>
        </AppCard>
    </div>
</template>
