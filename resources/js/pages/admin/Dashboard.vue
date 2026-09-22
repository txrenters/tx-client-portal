<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CircleAlert,
    KeyRound,
    Plug,
    ScrollText,
    ShieldCheck,
    Smartphone,
    UserPlus,
    Users,
} from '@lucide/vue';
import AppCard from '@/components/AppCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import IntegrationStatusList from '@/components/admin/IntegrationStatusList.vue';
import ActivityFeed from '@/components/admin/ActivityFeed.vue';
import { Button } from '@/components/ui/button';
import { activity as activityRoute } from '@/routes/admin';
import { index as integrationsRoute } from '@/routes/admin/integrations';
import { index as usersRoute } from '@/routes/admin/users';
import type {
    AdminActivityEvent,
    AdminIntegration,
    AdminQueueHealth,
    AdminStats,
} from '@/types';

const props = defineProps<{
    stats: AdminStats;
    queue: AdminQueueHealth;
    /** Undefined while the services are being checked. */
    integrations?: AdminIntegration[];
    activity?: AdminActivityEvent[];
}>();

const busiestDay = Math.max(1, ...props.stats.signups.map((day) => day.count));

const totalSignups = props.stats.signups.reduce(
    (total, day) => total + day.count,
    0,
);

const tiles = [
    {
        label: 'Users',
        value: props.stats.users.total,
        detail: `${props.stats.users.verified} confirmed, ${props.stats.users.unverified} not yet`,
        icon: Users,
    },
    {
        label: 'Client accounts',
        value: props.stats.accounts.total,
        detail: `${props.stats.accounts.owners} owner, ${props.stats.accounts.residents} resident`,
        icon: KeyRound,
    },
    {
        label: 'App sessions',
        value: props.stats.sessions.mobileTokens,
        detail: `${props.stats.sessions.activeThisWeek} used this week`,
        icon: Smartphone,
    },
    {
        label: 'New this month',
        value: props.stats.users.joinedThisMonth,
        detail: `${props.stats.users.withTwoFactor} account(s) use two-factor`,
        icon: UserPlus,
    },
];
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Admin" />

        <PageHeader
            title="Admin"
            description="How the portal is being used, and whether everything it depends on is answering."
        >
            <template #actions>
                <Button as-child variant="outline" size="sm">
                    <Link :href="usersRoute()">
                        <Users class="size-4" />
                        Users
                    </Link>
                </Button>
                <Button as-child variant="outline" size="sm">
                    <Link :href="integrationsRoute()">
                        <Plug class="size-4" />
                        Integrations
                    </Link>
                </Button>
                <Button as-child variant="outline" size="sm">
                    <a href="/log-viewer">
                        <ScrollText class="size-4" />
                        Logs
                    </a>
                </Button>
            </template>
        </PageHeader>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <AppCard v-for="tile in tiles" :key="tile.label">
                <div class="flex items-start justify-between gap-3">
                    <div class="space-y-1">
                        <p
                            class="text-muted-foreground text-xs font-medium tracking-wider uppercase"
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

        <AppCard
            v-if="queue.failed > 0"
            class="border-destructive/40 bg-destructive/5"
        >
            <div class="flex items-start gap-3">
                <CircleAlert class="text-destructive mt-0.5 size-5 shrink-0" />
                <div class="space-y-1">
                    <p class="font-medium">
                        {{ queue.failed }} background job(s) have failed
                    </p>
                    <p class="text-muted-foreground text-sm">
                        Queued work that never finished. The log has the reason.
                        <span v-if="queue.lastFailureAt">
                            Most recent: {{ queue.lastFailureAt }}.
                        </span>
                    </p>
                    <Button as-child variant="outline" size="sm" class="mt-2">
                        <a href="/log-viewer">Open the log</a>
                    </Button>
                </div>
            </div>
        </AppCard>

        <div class="grid gap-4 lg:grid-cols-2">
            <AppCard
                title="Integrations"
                description="Checked when this page loaded."
            >
                <IntegrationStatusList :integrations="integrations" />
            </AppCard>

            <AppCard
                title="Recent activity"
                :href="activityRoute()"
                link-label="See all"
            >
                <ActivityFeed :events="activity" :limit="12" />
            </AppCard>
        </div>

        <AppCard
            title="Sign-ups"
            description="New portal accounts over the last 30 days."
        >
            <div class="flex h-32 items-end gap-1" aria-hidden="true">
                <div
                    v-for="day in stats.signups"
                    :key="day.date"
                    class="bg-primary/70 hover:bg-primary min-h-0.5 flex-1 rounded-t-sm transition-colors"
                    :style="{
                        height: `${Math.max(2, (day.count / busiestDay) * 100)}%`,
                    }"
                    :title="`${day.date}: ${day.count}`"
                />
            </div>
            <p class="text-muted-foreground mt-2 text-sm">
                {{ totalSignups }} sign-up(s) in this window.
                <ShieldCheck class="text-success inline size-3.5" />
                {{ stats.invitations.pending }} invitation(s) still pending.
            </p>
        </AppCard>
    </div>
</template>
