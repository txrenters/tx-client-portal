<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    BellRing,
    ChevronRight,
    Clock,
    Eye,
    KeyRound,
    Lock,
    ShieldAlert,
    Link2Off,
    MailQuestion,
    MailX,
    Smartphone,
    Users,
} from '@lucide/vue';
import AppCard from '@/components/AppCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import ResultsPagination from '@/components/ResultsPagination.vue';
import ConfirmAction from '@/components/admin/ConfirmAction.vue';
import DailyBarChart from '@/components/admin/DailyBarChart.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { appDateFormatter } from '@/lib/timezone';
import { dashboard as adminHome, insights, properties } from '@/routes/admin';
import { index as invitationsRoute, resend } from '@/routes/admin/invitations';
import { show as showUser } from '@/routes/admin/users';
import { ref, watch } from 'vue';
import type { AdminInsightList, AdminSignInEvent, Paginated } from '@/types';

/** The trail in the header beside the sidebar's collapse button. */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: adminHome() },
            { title: 'Insights', href: insights() },
        ],
    },
});

const props = defineProps<{
    quietDays: number;
    adoption: {
        clients: number;
        onApp: number;
        withPush: number;
        activeThisMonth: number;
    };
    unconfirmed: AdminInsightList;
    unlinked: AdminInsightList;
    quiet: AdminInsightList;
    unaccepted: {
        id: number;
        name: string;
        email: string;
        role: string;
        expiredAt: string | null;
    }[];
    activityDays: number;
    visits: {
        total: number;
        visitors: number;
        web: number;
        app: number;
        daily: { date: string; web: number; app: number }[];
    };
    signIns: {
        successful: number;
        failed: number;
        lockedOut: number;
        blocked: number;
        repeated: {
            email: string;
            userId: number | null;
            attempts: number;
            addresses: number;
            lastAt: string;
        }[];
    };
    logFilter: 'all' | 'signed_in' | 'failed';
    logEmail: string;
    signInLog: Paginated<AdminSignInEvent>;
}>();

/** Which tab is open, kept in the address so a reload lands on it again. */
type InsightTab = 'activity' | 'log' | 'adoption';

const tab = ref<InsightTab>(
    (['activity', 'log', 'adoption'] as const).find(
        (name) =>
            typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).get('tab') === name,
    ) ?? 'activity',
);

watch(tab, (name) => {
    const url = new URL(window.location.href);

    if (name === 'activity') {
        url.searchParams.delete('tab');
    } else {
        url.searchParams.set('tab', name);
    }

    window.history.replaceState(window.history.state, '', url);
});

/* Sign-in log filters. */
const logFilter = ref(props.logFilter);
const logEmail = ref(props.logEmail);

function reloadLog(page?: number): void {
    router.get(
        insights().url,
        {
            tab: tab.value === 'activity' ? undefined : tab.value,
            log: logFilter.value === 'all' ? undefined : logFilter.value,
            email: logEmail.value || undefined,
            log_page: page,
        },
        {
            only: ['signInLog', 'logFilter', 'logEmail'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

watch(logFilter, () => reloadLog());

function showAddress(email: string): void {
    logEmail.value = email;
    logFilter.value = 'all';
    tab.value = 'log';
    reloadLog();
}

function clearAddress(): void {
    logEmail.value = '';
    reloadLog();
}

const eventLabels: Record<AdminSignInEvent['event'], string> = {
    signed_in: 'Signed in',
    failed: 'Wrong password',
    failed_two_factor: 'Wrong 2FA code',
    locked_out: 'Locked out',
    blocked: 'Suspended',
};

const methodLabels: Record<string, string> = {
    password: 'password',
    passkey: 'passkey',
    remembered: 'remembered device',
    two_factor: 'password + 2FA',
};

const stamped = appDateFormatter({
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
});

const activityTiles = [
    {
        label: 'Portal visits',
        value: props.visits.total,
        detail: `${props.visits.visitors} different people`,
        icon: Eye,
    },
    {
        label: 'Sign-ins',
        value: props.signIns.successful,
        detail: 'Web and app',
        icon: KeyRound,
    },
    {
        label: 'Failed sign-ins',
        value: props.signIns.failed,
        detail: 'Wrong password or 2FA code',
        icon: ShieldAlert,
    },
    {
        label: 'Locked out or blocked',
        value: props.signIns.lockedOut + props.signIns.blocked,
        detail: `${props.signIns.lockedOut} too many tries, ${props.signIns.blocked} suspended`,
        icon: Lock,
    },
];

function share(part: number): string {
    return props.adoption.clients === 0
        ? '0%'
        : `${Math.round((part / props.adoption.clients) * 100)}%`;
}

const tiles = [
    {
        label: 'Clients on the portal',
        value: props.adoption.clients,
        detail: 'Logins linked to a property',
        icon: Users,
    },
    {
        label: `Active in ${props.quietDays} days`,
        value: props.adoption.activeThisMonth,
        detail: `${share(props.adoption.activeThisMonth)} of clients`,
        icon: Clock,
    },
    {
        label: 'Using the app',
        value: props.adoption.onApp,
        detail: `${share(props.adoption.onApp)} of clients`,
        icon: Smartphone,
    },
    {
        label: 'App notifications on',
        value: props.adoption.withPush,
        detail: `${share(props.adoption.withPush)} of clients`,
        icon: BellRing,
    },
];

const dated = appDateFormatter({ dateStyle: 'medium' });

function when(value: string | null): string {
    return value ? dated.format(new Date(value)) : 'never';
}

const lists = [
    {
        key: 'unconfirmed',
        title: 'Never confirmed their email',
        description:
            'Signed up more than a day ago but never clicked the confirmation link. Open them to resend it or confirm it for them.',
        icon: MailQuestion,
        list: props.unconfirmed,
        dateLabel: 'Joined',
        date: (person: AdminInsightList['people'][number]) => person.joinedAt,
    },
    {
        key: 'unlinked',
        title: 'No property linked',
        description:
            'Confirmed, but their email matches no Propertyware tenant or owner, so their portal is empty. Usually a different email on file: find their property and invite the right address.',
        icon: Link2Off,
        list: props.unlinked,
        dateLabel: 'Joined',
        date: (person: AdminInsightList['people'][number]) => person.joinedAt,
    },
    {
        key: 'quiet',
        title: `Not seen in ${props.quietDays} days`,
        description:
            'Clients who have stopped using the portal and the app, longest-gone first. Worth a nudge, or a check that nothing is broken for them.',
        icon: Clock,
        list: props.quiet,
        dateLabel: 'Last seen',
        date: (person: AdminInsightList['people'][number]) => person.lastSeenAt,
    },
] as const;
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Insights" />

        <PageHeader
            title="Insights"
            description="Visits, sign-ins and failed attempts, how many clients use the app, and who needs a follow-up."
        />

        <Tabs v-model="tab" class="gap-6">
            <TabsList>
                <TabsTrigger value="activity">Activity</TabsTrigger>
                <TabsTrigger value="log"
                    >Sign-in log
                    <Badge
                        v-if="signIns.repeated.length > 0"
                        variant="destructive"
                        class="ml-1"
                        >{{ signIns.repeated.length }}</Badge
                    ></TabsTrigger
                >
                <TabsTrigger value="adoption"
                    >Adoption &amp; follow-ups</TabsTrigger
                >
            </TabsList>

            <TabsContent value="activity" class="flex flex-col gap-6">
                <p class="text-muted-foreground -mb-2 text-sm font-medium">
                    Last {{ activityDays }} days
                </p>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <AppCard v-for="tile in activityTiles" :key="tile.label">
                        <div class="flex items-start justify-between gap-3">
                            <div class="space-y-1">
                                <p
                                    class="text-muted-foreground text-sm font-medium"
                                >
                                    {{ tile.label }}
                                </p>
                                <p class="text-3xl font-semibold tabular-nums">
                                    {{ tile.value }}
                                </p>
                                <p class="text-muted-foreground text-sm">
                                    {{ tile.detail }}
                                </p>
                            </div>
                            <component
                                :is="tile.icon"
                                class="text-primary size-5 shrink-0"
                            />
                        </div>
                    </AppCard>
                </div>

                <div
                    class="grid gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]"
                >
                    <AppCard
                        title="Visits per day"
                        description="A visit is a signed-in client or staff member coming back after half an hour or more away. Hover a day for its numbers."
                    >
                        <DailyBarChart
                            :days="visits.daily"
                            :series="[
                                {
                                    key: 'web',
                                    label: 'Web',
                                    colorClass: 'bg-chart-1',
                                },
                                {
                                    key: 'app',
                                    label: 'App',
                                    colorClass: 'bg-chart-2',
                                },
                            ]"
                            unit="visit"
                        />
                        <p
                            class="text-muted-foreground mt-3 text-xs text-pretty"
                        >
                            Counts the portal and the app only. Visits to the
                            public texasrenters.com website are not tracked
                            here.
                        </p>
                    </AppCard>

                    <AppCard
                        title="Repeated failed sign-ins"
                        description="Addresses with three or more failures this week: someone who forgot their password, or someone guessing."
                    >
                        <p
                            v-if="signIns.repeated.length === 0"
                            class="text-muted-foreground text-sm"
                        >
                            Nothing unusual this week.
                        </p>
                        <ul v-else class="divide-border/60 divide-y">
                            <li
                                v-for="entry in signIns.repeated"
                                :key="entry.email"
                                class="flex items-center justify-between gap-3 py-2"
                            >
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium">
                                        {{ entry.email }}
                                    </p>
                                    <p class="text-muted-foreground text-xs">
                                        {{ entry.attempts }} failures from
                                        {{ entry.addresses }} IP address(es) ·
                                        last
                                        {{
                                            stamped.format(
                                                new Date(entry.lastAt),
                                            )
                                        }}
                                        <template v-if="!entry.userId">
                                            · no such login</template
                                        >
                                    </p>
                                </div>
                                <div class="flex shrink-0 gap-1">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="showAddress(entry.email)"
                                        >Log</Button
                                    >
                                    <Button
                                        v-if="entry.userId"
                                        as-child
                                        variant="outline"
                                        size="sm"
                                    >
                                        <Link :href="showUser(entry.userId)"
                                            >Open</Link
                                        >
                                    </Button>
                                </div>
                            </li>
                        </ul>
                    </AppCard>
                </div>
            </TabsContent>

            <TabsContent value="log" class="flex flex-col gap-6">
                <AppCard
                    id="sign-in-log"
                    title="Sign-in log"
                    description="Every sign-in and failed attempt on the web and in the app, newest first. Kept for 180 days."
                >
                    <div class="mb-4 flex flex-wrap items-center gap-3">
                        <Tabs v-model="logFilter">
                            <TabsList>
                                <TabsTrigger value="all">All</TabsTrigger>
                                <TabsTrigger value="signed_in"
                                    >Signed in</TabsTrigger
                                >
                                <TabsTrigger value="failed">Failed</TabsTrigger>
                            </TabsList>
                        </Tabs>
                        <Badge
                            v-if="logEmail"
                            variant="secondary"
                            class="gap-1"
                        >
                            {{ logEmail }}
                            <button
                                type="button"
                                class="hover:text-foreground"
                                aria-label="Show every address"
                                @click="clearAddress"
                            >
                                ×
                            </button>
                        </Badge>
                    </div>

                    <p
                        v-if="signInLog.data.length === 0"
                        class="text-muted-foreground text-sm"
                    >
                        No sign-ins recorded yet.
                    </p>
                    <Table v-else>
                        <TableHeader>
                            <TableRow>
                                <TableHead>When</TableHead>
                                <TableHead>Who</TableHead>
                                <TableHead>Result</TableHead>
                                <TableHead>Device and IP</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="event in signInLog.data"
                                :key="event.id"
                            >
                                <TableCell class="text-sm whitespace-nowrap">
                                    {{ stamped.format(new Date(event.at)) }}
                                </TableCell>
                                <TableCell>
                                    <Link
                                        v-if="event.user"
                                        :href="showUser(event.user.id)"
                                        class="text-sm font-medium hover:underline"
                                        >{{ event.user.name }}</Link
                                    >
                                    <p
                                        v-else
                                        class="text-muted-foreground text-sm font-medium"
                                    >
                                        No such login
                                    </p>
                                    <p class="text-muted-foreground text-xs">
                                        {{ event.email }}
                                    </p>
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        :variant="
                                            event.event === 'signed_in'
                                                ? 'secondary'
                                                : 'destructive'
                                        "
                                        >{{ eventLabels[event.event] }}</Badge
                                    >
                                    <p
                                        class="text-muted-foreground mt-1 text-xs"
                                    >
                                        {{
                                            event.channel === 'app'
                                                ? 'App'
                                                : 'Web'
                                        }}
                                        <template v-if="event.method">
                                            ·
                                            {{
                                                methodLabels[event.method] ??
                                                event.method
                                            }}</template
                                        >
                                    </p>
                                </TableCell>
                                <TableCell class="text-sm">
                                    {{ event.device ?? '—' }}
                                    <p class="text-muted-foreground text-xs">
                                        {{ event.ipAddress ?? '' }}
                                    </p>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>

                    <ResultsPagination
                        :results="signInLog"
                        class="mt-4"
                        @change="(page) => reloadLog(page)"
                    />
                </AppCard>
            </TabsContent>

            <TabsContent value="adoption" class="flex flex-col gap-6">
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <AppCard v-for="tile in tiles" :key="tile.label">
                        <div class="flex items-start justify-between gap-3">
                            <div class="space-y-1">
                                <p
                                    class="text-muted-foreground text-sm font-medium"
                                >
                                    {{ tile.label }}
                                </p>
                                <p class="text-3xl font-semibold tabular-nums">
                                    {{ tile.value }}
                                </p>
                                <p class="text-muted-foreground text-sm">
                                    {{ tile.detail }}
                                </p>
                            </div>
                            <component
                                :is="tile.icon"
                                class="text-primary size-5 shrink-0"
                            />
                        </div>
                    </AppCard>
                </div>

                <div class="grid gap-4 xl:grid-cols-2">
                    <AppCard
                        v-for="section in lists"
                        :key="section.key"
                        :title="`${section.title} (${section.list.total})`"
                        :description="section.description"
                    >
                        <p
                            v-if="section.list.total === 0"
                            class="text-muted-foreground text-sm"
                        >
                            Nobody. Nice.
                        </p>
                        <ul v-else class="divide-border/60 divide-y">
                            <li
                                v-for="person in section.list.people"
                                :key="person.id"
                                class="flex items-center justify-between gap-3 py-2"
                            >
                                <div class="min-w-0">
                                    <Link
                                        :href="showUser(person.id)"
                                        class="truncate text-sm font-medium hover:underline"
                                        >{{ person.name }}</Link
                                    >
                                    <p
                                        class="text-muted-foreground truncate text-xs"
                                    >
                                        {{ person.email }} ·
                                        {{ section.dateLabel }}
                                        {{ when(section.date(person)) }}
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-1">
                                    <Badge
                                        v-for="role in person.roles"
                                        :key="role"
                                        variant="outline"
                                        class="capitalize"
                                        >{{ role }}</Badge
                                    >
                                    <Button
                                        as-child
                                        variant="ghost"
                                        size="icon"
                                        class="size-7"
                                    >
                                        <Link
                                            :href="showUser(person.id)"
                                            :aria-label="`Open ${person.name}`"
                                        >
                                            <ChevronRight class="size-4" />
                                        </Link>
                                    </Button>
                                </div>
                            </li>
                        </ul>
                        <p
                            v-if="
                                section.list.total > section.list.people.length
                            "
                            class="text-muted-foreground mt-2 text-xs"
                        >
                            And
                            {{
                                section.list.total - section.list.people.length
                            }}
                            more.
                        </p>
                        <Button
                            v-if="
                                section.key === 'unlinked' &&
                                section.list.total > 0
                            "
                            as-child
                            variant="outline"
                            size="sm"
                            class="mt-3"
                        >
                            <Link :href="properties()">Find by property</Link>
                        </Button>
                    </AppCard>

                    <AppCard
                        :title="`Invitations never used (${unaccepted.length})`"
                        description="The link expired and they have not signed up another way. Send them a fresh one."
                    >
                        <p
                            v-if="unaccepted.length === 0"
                            class="text-muted-foreground text-sm"
                        >
                            Nobody. Nice.
                        </p>
                        <ul v-else class="divide-border/60 divide-y">
                            <li
                                v-for="invitation in unaccepted"
                                :key="invitation.id"
                                class="flex items-center justify-between gap-3 py-2"
                            >
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium">
                                        {{ invitation.name }}
                                    </p>
                                    <p
                                        class="text-muted-foreground truncate text-xs"
                                    >
                                        {{ invitation.email }} · expired
                                        {{ when(invitation.expiredAt) }}
                                    </p>
                                </div>
                                <ConfirmAction
                                    title="Send a new invitation link?"
                                    :description="`A fresh link goes to ${invitation.email}, valid for 7 days.`"
                                    confirm-label="Resend"
                                    :action="resend(invitation.id)"
                                >
                                    <Button variant="outline" size="sm">
                                        <MailX class="size-4" />
                                        Resend
                                    </Button>
                                </ConfirmAction>
                            </li>
                        </ul>
                        <Button
                            as-child
                            variant="outline"
                            size="sm"
                            class="mt-3"
                        >
                            <Link
                                :href="
                                    invitationsRoute({
                                        query: { status: 'expired' },
                                    })
                                "
                                >All expired invitations</Link
                            >
                        </Button>
                    </AppCard>
                </div>
            </TabsContent>
        </Tabs>
    </div>
</template>
