<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Ban,
    KeyRound,
    LifeBuoy,
    Link2Off,
    MailCheck,
    MailPlus,
    ShieldOff,
    Smartphone,
    UserCheck,
    UserRoundCheck,
} from '@lucide/vue';
import AppCard from '@/components/AppCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import ActivityFeed from '@/components/admin/ActivityFeed.vue';
import ConfirmAction from '@/components/admin/ConfirmAction.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { appDateFormatter } from '@/lib/timezone';
import { index as helpRequestsRoute } from '@/routes/admin/help-requests';
import {
    impersonate,
    index as usersRoute,
    markVerified,
    show as showRoute,
    passwordReset,
    relinkAccounts,
    restore,
    revokeAppSessions,
    suspend,
    twoFactorReset,
} from '@/routes/admin/users';
import { dashboard as adminHome } from '@/routes/admin';
import { resend as resendVerification } from '@/routes/admin/users/verification';
import type { AdminActivityEvent, AdminUserDetail } from '@/types';

const props = defineProps<{
    user: AdminUserDetail;
    /** Staff logins are shown but not managed from here. */
    isStaff: boolean;
    history: AdminActivityEvent[];
}>();

const dated = appDateFormatter({ dateStyle: 'medium' });
const stamped = appDateFormatter({ dateStyle: 'medium', timeStyle: 'short' });

function when(value: string | null, formatter = dated): string {
    return value ? formatter.format(new Date(value)) : '—';
}

const name = props.user.name;

/** The trail in the header beside the sidebar's collapse button. */
setLayoutProps({
    breadcrumbs: [
        { title: 'Admin', href: adminHome() },
        { title: 'Users', href: usersRoute() },
        { title: name, href: showRoute(props.user.id) },
    ],
});
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head :title="user.name" />

        <div>
            <Button as-child variant="ghost" size="sm" class="-ml-2">
                <Link :href="usersRoute()">
                    <ArrowLeft class="size-4" />
                    Users
                </Link>
            </Button>
        </div>

        <PageHeader :title="user.name" :description="user.email">
            <template #actions>
                <ConfirmAction
                    v-if="!isStaff && !user.suspendedAt"
                    :title="`Sign in as ${name}?`"
                    description="You will see exactly what they see, and anything you do is recorded against them. Leave again from the banner as soon as you are done."
                    confirm-label="Sign in as them"
                    :action="impersonate(user.id)"
                >
                    <Button size="sm">
                        <UserCheck class="size-4" />
                        Sign in as
                    </Button>
                </ConfirmAction>
            </template>
        </PageHeader>

        <div class="flex flex-wrap gap-1">
            <Badge v-if="isStaff">Staff</Badge>
            <Badge v-if="user.suspendedAt" variant="destructive"
                >Suspended</Badge
            >
            <Badge :variant="user.verifiedAt ? 'secondary' : 'outline'">{{
                user.verifiedAt ? 'Email confirmed' : 'Email not confirmed'
            }}</Badge>
            <Badge v-if="user.twoFactor" variant="outline">Two-factor on</Badge>
        </div>

        <Alert v-if="user.suspendedAt" variant="destructive">
            <Ban />
            <AlertTitle>Suspended {{ when(user.suspendedAt, stamped) }}</AlertTitle>
            <AlertDescription>
                They cannot sign in to the portal or the apps until the
                suspension is lifted.
            </AlertDescription>
        </Alert>

        <Alert v-if="user.openHelpRequests > 0">
            <LifeBuoy />
            <AlertTitle
                >{{ user.openHelpRequests }} open help request(s)</AlertTitle
            >
            <AlertDescription>
                <Link
                    :href="helpRequestsRoute({ query: { search: user.email } })"
                    class="text-primary hover:underline"
                    >See what they asked</Link
                >
            </AlertDescription>
        </Alert>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div class="flex flex-col gap-4">
                <AppCard
                    title="Linked accounts"
                    description="The Propertyware records this login was matched to."
                >
                    <p
                        v-if="user.accounts.length === 0"
                        class="text-muted-foreground text-sm"
                    >
                        None linked. They are matched to Propertyware on their
                        next visit.
                    </p>
                    <ul v-else class="divide-border/60 divide-y">
                        <li
                            v-for="account in user.accounts"
                            :key="account.id"
                            class="flex items-center justify-between gap-3 py-2"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">
                                    {{ account.label ?? 'Unnamed account' }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    Propertyware
                                    {{ account.propertywareId ?? '—' }} ·
                                    linked {{ when(account.linkedAt) }}
                                </p>
                            </div>
                            <Badge variant="outline" class="capitalize">{{
                                account.role
                            }}</Badge>
                        </li>
                    </ul>
                </AppCard>

                <AppCard
                    title="App sessions"
                    :description="`${user.sessions.length} signed-in app session(s), ${user.devices} phone(s) registered for notifications.`"
                >
                    <p
                        v-if="user.sessions.length === 0"
                        class="text-muted-foreground text-sm"
                    >
                        Not signed in to the apps.
                    </p>
                    <ul v-else class="divide-border/60 divide-y">
                        <li
                            v-for="session in user.sessions"
                            :key="session.id"
                            class="flex items-center gap-3 py-2"
                        >
                            <Smartphone
                                class="text-muted-foreground size-4 shrink-0"
                            />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">
                                    {{ session.name }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    Last used
                                    {{ when(session.lastUsedAt, stamped) }} ·
                                    signed in {{ when(session.createdAt) }}
                                </p>
                            </div>
                        </li>
                    </ul>
                </AppCard>

                <AppCard
                    title="Staff history"
                    description="What staff have done to this login, newest first."
                >
                    <ActivityFeed
                        v-if="history.length > 0"
                        :events="history"
                    />
                    <p v-else class="text-muted-foreground text-sm">
                        Nothing yet.
                    </p>
                </AppCard>
            </div>

            <AppCard
                title="Support"
                :description="
                    isStaff
                        ? 'Staff logins are not managed from here.'
                        : `Joined ${when(user.joinedAt)}. Every action is recorded in the staff history.`
                "
            >
                <div v-if="!isStaff" class="flex flex-col gap-2">
                    <ConfirmAction
                        title="Send a password reset link?"
                        :description="`${user.email} gets the same reset email they would get by asking for it themselves.`"
                        confirm-label="Send link"
                        :action="passwordReset(user.id)"
                    >
                        <Button variant="outline" class="justify-start">
                            <KeyRound class="size-4" />
                            Send password reset
                        </Button>
                    </ConfirmAction>

                    <template v-if="!user.verifiedAt">
                        <ConfirmAction
                            title="Send the confirmation email again?"
                            :description="`A new link goes to ${user.email}.`"
                            confirm-label="Send"
                            :action="resendVerification(user.id)"
                        >
                            <Button variant="outline" class="justify-start">
                                <MailPlus class="size-4" />
                                Resend confirmation email
                            </Button>
                        </ConfirmAction>
                        <ConfirmAction
                            title="Mark this email as confirmed?"
                            description="Only do this once you are sure the address is theirs, for example after talking to them."
                            confirm-label="Mark confirmed"
                            :action="markVerified(user.id)"
                        >
                            <Button variant="outline" class="justify-start">
                                <MailCheck class="size-4" />
                                Mark email confirmed
                            </Button>
                        </ConfirmAction>
                    </template>

                    <ConfirmAction
                        v-if="user.twoFactor"
                        title="Turn off two-factor?"
                        description="For someone who lost the phone their codes are on. They sign in with their password alone until they set it up again. Check it is really them first."
                        confirm-label="Turn off two-factor"
                        destructive
                        :action="twoFactorReset(user.id)"
                    >
                        <Button variant="outline" class="justify-start">
                            <ShieldOff class="size-4" />
                            Reset two-factor
                        </Button>
                    </ConfirmAction>

                    <ConfirmAction
                        title="Sign out of the apps?"
                        description="Ends every app session on their phones and tablets. Their browser stays signed in."
                        confirm-label="Sign out apps"
                        :action="revokeAppSessions(user.id)"
                    >
                        <Button
                            variant="outline"
                            class="justify-start"
                            :disabled="user.appSessions === 0"
                        >
                            <Smartphone class="size-4" />
                            Sign out of apps
                        </Button>
                    </ConfirmAction>

                    <ConfirmAction
                        title="Re-link their accounts?"
                        description="Forgets which Propertyware records they matched. They are matched again on their next visit, which fixes a login linked to the wrong contact."
                        confirm-label="Re-link"
                        :action="relinkAccounts(user.id)"
                    >
                        <Button variant="outline" class="justify-start">
                            <Link2Off class="size-4" />
                            Re-link accounts
                        </Button>
                    </ConfirmAction>

                    <ConfirmAction
                        v-if="user.suspendedAt"
                        title="Lift the suspension?"
                        description="They can sign in to the portal and the apps again straight away."
                        confirm-label="Lift suspension"
                        :action="restore(user.id)"
                    >
                        <Button variant="outline" class="justify-start">
                            <UserRoundCheck class="size-4" />
                            Lift suspension
                        </Button>
                    </ConfirmAction>
                    <ConfirmAction
                        v-else
                        :title="`Suspend ${name}?`"
                        description="Signs them out everywhere and stops them signing in to the portal or the apps. Nothing is deleted, and you can lift it at any time."
                        confirm-label="Suspend"
                        destructive
                        with-reason
                        :action="suspend(user.id)"
                    >
                        <Button
                            variant="outline"
                            class="text-destructive justify-start"
                        >
                            <Ban class="size-4" />
                            Suspend account
                        </Button>
                    </ConfirmAction>
                </div>
            </AppCard>
        </div>
    </div>
</template>
