<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Ban, ShieldAlert, ShieldCheck, ShieldX, Unlock } from '@lucide/vue';
import { ref, watch } from 'vue';
import AppCard from '@/components/AppCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import ResultsPagination from '@/components/ResultsPagination.vue';
import ConfirmAction from '@/components/admin/ConfirmAction.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import {
    Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import { appDateFormatter } from '@/lib/timezone';
import { dashboard as adminHome, security } from '@/routes/admin';
import { destroy, store } from '@/routes/admin/security/blocks';
import { show as showUser } from '@/routes/admin/users';
import type {
    AdminBlockedIp,
    AdminSecurityEvent,
    AdminSuspiciousIp,
    Paginated,
} from '@/types';

/** The trail in the header beside the sidebar's collapse button. */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: adminHome() },
            { title: 'Security', href: security() },
        ],
    },
});

const props = defineProps<{
    window: 24 | 168;
    type: string | null;
    ip: string | null;
    yourIp: string | null;
    eventTypes: Record<string, string>;
    thresholds: {
        stuffingFailures: number;
        stuffingEmails: number;
        bruteForceFailures: number;
        probes: number;
        autoBlockHours: number;
    };
    suspicious: AdminSuspiciousIp[];
    blocks: AdminBlockedIp[];
    events: Paginated<AdminSecurityEvent>;
}>();

/** Which tab is open, kept in the address so a reload lands on it again. */
type SecurityTab = 'suspicious' | 'blocked' | 'events';

const tab = ref<SecurityTab>(
    (['suspicious', 'blocked', 'events'] as const).find(
        (name) =>
            typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).get('tab') === name,
    ) ?? 'suspicious',
);

const timeWindow = ref(String(props.window));
const eventType = ref(props.type ?? 'all');
const eventIp = ref(props.ip ?? '');

function reload(page?: number): void {
    router.get(
        security().url,
        {
            tab: tab.value === 'suspicious' ? undefined : tab.value,
            window: timeWindow.value === '24' ? undefined : timeWindow.value,
            type: eventType.value === 'all' ? undefined : eventType.value,
            ip: eventIp.value || undefined,
            events_page: page,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(tab, (name) => {
    const url = new URL(window.location.href);

    if (name === 'suspicious') {
        url.searchParams.delete('tab');
    } else {
        url.searchParams.set('tab', name);
    }

    window.history.replaceState(window.history.state, '', url);
});

watch([timeWindow, eventType], () => reload());

function eventsFor(ip: string): void {
    eventIp.value = ip;
    eventType.value = 'all';
    tab.value = 'events';
    reload();
}

function clearIp(): void {
    eventIp.value = '';
    reload();
}

/* Blocking an address. */
const dialogOpen = ref(false);
const form = useForm({ ip: '', reason: '', hours: '24' });

function openBlock(ip = '', reason = ''): void {
    form.reset();
    form.clearErrors();
    form.ip = ip;
    form.reason = reason;
    dialogOpen.value = true;
}

function block(): void {
    form.transform((data) => ({
        ...data,
        hours: data.hours === 'forever' ? null : Number(data.hours),
    })).post(store().url, {
        preserveScroll: true,
        onSuccess: () => (dialogOpen.value = false),
    });
}

const stamped = appDateFormatter({
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
});

function when(value: string | null): string {
    return value ? stamped.format(new Date(value)) : '—';
}

const activeBlocks = props.blocks.filter((item) => item.active).length;
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Security" />

        <PageHeader
            title="Security"
            description="Addresses attacking the portal, the ones it refuses, and everything suspicious it has seen."
        >
            <template #actions>
                <Button size="sm" variant="outline" @click="openBlock()">
                    <Ban class="size-4" />
                    Block an address
                </Button>
            </template>
        </PageHeader>

        <Tabs v-model="tab" class="gap-6">
            <TabsList>
                <TabsTrigger value="suspicious"
                    >Suspicious
                    <Badge
                        v-if="suspicious.length"
                        variant="destructive"
                        class="ml-1"
                        >{{ suspicious.length }}</Badge
                    ></TabsTrigger
                >
                <TabsTrigger value="blocked"
                    >Blocked
                    <Badge v-if="activeBlocks" variant="secondary" class="ml-1">{{
                        activeBlocks
                    }}</Badge></TabsTrigger
                >
                <TabsTrigger value="events">Event log</TabsTrigger>
            </TabsList>

            <TabsContent value="suspicious" class="flex flex-col gap-6">
                <AppCard
                    title="Suspicious addresses"
                    description="Three or more failed sign-ins, or any scanner probe, admin attempt, bad invitation link or bad webhook signature."
                >
                    <div class="mb-4">
                        <Select v-model="timeWindow">
                            <SelectTrigger class="w-44" aria-label="Time window">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="24">Last 24 hours</SelectItem>
                                <SelectItem value="168">Last 7 days</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <EmptyState
                        v-if="suspicious.length === 0"
                        :icon="ShieldCheck"
                        title="Nothing suspicious"
                        description="No address has done anything worth a look in this window."
                    />
                    <Table v-else>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Address</TableHead>
                                <TableHead>Failed sign-ins</TableHead>
                                <TableHead>Other signals</TableHead>
                                <TableHead>Last seen</TableHead>
                                <TableHead class="text-right">
                                    <span class="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="row in suspicious" :key="row.ip">
                                <TableCell>
                                    <p class="font-mono text-sm">{{ row.ip }}</p>
                                    <Badge
                                        v-if="row.blocked"
                                        variant="secondary"
                                        class="mt-1"
                                        >Blocked</Badge
                                    >
                                    <Badge
                                        v-else-if="row.ip === yourIp"
                                        variant="outline"
                                        class="mt-1"
                                        >Your address</Badge
                                    >
                                </TableCell>
                                <TableCell class="text-sm">
                                    {{ row.failures }}
                                    <p
                                        v-if="row.emails"
                                        class="text-muted-foreground max-w-64 truncate text-xs"
                                        :title="row.sampleEmails.join(', ')"
                                    >
                                        {{ row.emails }} email(s):
                                        {{ row.sampleEmails.join(', ') }}
                                    </p>
                                </TableCell>
                                <TableCell class="text-sm tabular-nums">
                                    {{ row.probes }}
                                </TableCell>
                                <TableCell class="text-sm">{{
                                    when(row.lastAt)
                                }}</TableCell>
                                <TableCell>
                                    <div class="flex justify-end gap-2">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            @click="eventsFor(row.ip)"
                                            >Events</Button
                                        >
                                        <Button
                                            v-if="!row.blocked && row.ip !== yourIp"
                                            variant="outline"
                                            size="sm"
                                            class="text-destructive"
                                            @click="
                                                openBlock(
                                                    row.ip,
                                                    `${row.failures} failed sign-ins, ${row.probes} other signals`,
                                                )
                                            "
                                        >
                                            <Ban class="size-4" />
                                            Block
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </AppCard>

                <AppCard title="How automatic blocking works">
                    <ul
                        class="text-muted-foreground list-disc space-y-1 pl-5 text-sm text-pretty"
                    >
                        <li>
                            {{ thresholds.stuffingFailures }}+ failed sign-ins
                            across {{ thresholds.stuffingEmails }}+ different
                            emails in an hour (someone trying a list of
                            passwords).
                        </li>
                        <li>
                            {{ thresholds.bruteForceFailures }}+ failed
                            sign-ins in an hour from one address.
                        </li>
                        <li>
                            {{ thresholds.probes }}+ requests for files only a
                            scanner looks for, such as /.env or
                            /wp-login.php.
                        </li>
                        <li>
                            Blocks last {{ thresholds.autoBlockHours }} hours,
                            and owners and admins are emailed. Addresses staff
                            have signed in from in the last 30 days, and
                            private network addresses, are never blocked
                            automatically.
                        </li>
                    </ul>
                </AppCard>
            </TabsContent>

            <TabsContent value="blocked" class="flex flex-col gap-6">
                <AppCard
                    title="Blocked addresses"
                    description="Every request from these is refused, on the web and in the app. Lifted blocks drop off after 30 days."
                >
                    <EmptyState
                        v-if="blocks.length === 0"
                        :icon="ShieldX"
                        title="Nothing blocked"
                        description="Addresses blocked automatically or by staff show up here."
                    />
                    <Table v-else>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Address</TableHead>
                                <TableHead>Why</TableHead>
                                <TableHead>Until</TableHead>
                                <TableHead>Refused</TableHead>
                                <TableHead class="text-right">
                                    <span class="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="item in blocks" :key="item.id">
                                <TableCell>
                                    <p class="font-mono text-sm">{{ item.ip }}</p>
                                    <Badge
                                        :variant="
                                            item.active ? 'destructive' : 'outline'
                                        "
                                        class="mt-1"
                                        >{{
                                            item.active ? 'Blocked' : 'Expired'
                                        }}</Badge
                                    >
                                </TableCell>
                                <TableCell class="max-w-72 text-sm whitespace-normal">
                                    {{ item.reason }}
                                    <p class="text-muted-foreground text-xs">
                                        {{
                                            item.source === 'automatic'
                                                ? 'Automatically'
                                                : `By ${item.blockedBy ?? 'staff'}`
                                        }}
                                        · {{ when(item.createdAt) }}
                                    </p>
                                </TableCell>
                                <TableCell class="text-sm">
                                    {{
                                        item.expiresAt
                                            ? when(item.expiresAt)
                                            : 'Until lifted'
                                    }}
                                </TableCell>
                                <TableCell class="text-sm">
                                    {{ item.hits }}
                                    <p
                                        v-if="item.lastHitAt"
                                        class="text-muted-foreground text-xs"
                                    >
                                        last {{ when(item.lastHitAt) }}
                                    </p>
                                </TableCell>
                                <TableCell class="text-right">
                                    <ConfirmAction
                                        :title="`Unblock ${item.ip}?`"
                                        description="Requests from it are let through again straight away. It can be blocked again automatically if it misbehaves."
                                        confirm-label="Unblock"
                                        :action="destroy(item.id)"
                                    >
                                        <Button variant="outline" size="sm">
                                            <Unlock class="size-4" />
                                            {{ item.active ? 'Unblock' : 'Remove' }}
                                        </Button>
                                    </ConfirmAction>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </AppCard>
            </TabsContent>

            <TabsContent value="events" class="flex flex-col gap-6">
                <AppCard
                    title="Event log"
                    description="Everything suspicious besides failed sign-ins, which are in Insights › Sign-in log. Kept for 180 days."
                >
                    <div class="mb-4 flex flex-wrap items-center gap-3">
                        <Select v-model="eventType">
                            <SelectTrigger class="w-64" aria-label="Event type">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Every kind</SelectItem>
                                <SelectItem
                                    v-for="(label, key) in eventTypes"
                                    :key="key"
                                    :value="key"
                                    >{{ label }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <Badge v-if="eventIp" variant="secondary" class="gap-1">
                            <span class="font-mono">{{ eventIp }}</span>
                            <button
                                type="button"
                                class="hover:text-foreground"
                                aria-label="Show every address"
                                @click="clearIp"
                            >
                                ×
                            </button>
                        </Badge>
                    </div>

                    <EmptyState
                        v-if="events.data.length === 0"
                        :icon="ShieldAlert"
                        title="No events"
                        description="Nothing suspicious has been recorded for this filter."
                    />
                    <Table v-else>
                        <TableHeader>
                            <TableRow>
                                <TableHead>When</TableHead>
                                <TableHead>What</TableHead>
                                <TableHead>Address</TableHead>
                                <TableHead>Details</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="event in events.data" :key="event.id">
                                <TableCell class="text-sm whitespace-nowrap">{{
                                    when(event.at)
                                }}</TableCell>
                                <TableCell class="text-sm">{{
                                    event.label
                                }}</TableCell>
                                <TableCell class="font-mono text-sm">{{
                                    event.ip ?? '—'
                                }}</TableCell>
                                <TableCell
                                    class="text-muted-foreground max-w-80 text-xs break-all whitespace-normal"
                                >
                                    <span v-if="event.reason">{{
                                        event.reason
                                    }}</span>
                                    <span v-else-if="event.path">{{
                                        event.path
                                    }}</span>
                                    <template v-if="event.user">
                                        ·
                                        <Link
                                            :href="showUser(event.user.id)"
                                            class="text-primary hover:underline"
                                            >{{ event.user.email }}</Link
                                        >
                                    </template>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>

                    <ResultsPagination
                        :results="events"
                        class="mt-4"
                        @change="(page) => reload(page)"
                    />
                </AppCard>
            </TabsContent>
        </Tabs>

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Block an address</DialogTitle>
                    <DialogDescription>
                        Every request from it is refused, on the web and in the
                        app. People sharing a network, like an office, share an
                        address too.
                    </DialogDescription>
                </DialogHeader>

                <form class="grid gap-4" @submit.prevent="block">
                    <div class="grid gap-2">
                        <Label for="block-ip">IP address</Label>
                        <Input
                            id="block-ip"
                            v-model="form.ip"
                            class="font-mono"
                            autocomplete="off"
                            required
                        />
                        <InputError :message="form.errors.ip" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="block-reason">Reason</Label>
                        <Input
                            id="block-reason"
                            v-model="form.reason"
                            maxlength="500"
                            required
                        />
                        <InputError :message="form.errors.reason" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="block-hours">For how long</Label>
                        <Select v-model="form.hours">
                            <SelectTrigger id="block-hours" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="1">1 hour</SelectItem>
                                <SelectItem value="24">24 hours</SelectItem>
                                <SelectItem value="168">7 days</SelectItem>
                                <SelectItem value="720">30 days</SelectItem>
                                <SelectItem value="forever"
                                    >Until someone lifts it</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="dialogOpen = false"
                            >Cancel</Button
                        >
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="form.processing"
                        >
                            <Spinner v-if="form.processing" />
                            <Ban v-else class="size-4" />
                            Block
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
