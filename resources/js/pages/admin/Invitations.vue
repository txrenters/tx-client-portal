<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Ban, MailPlus, RotateCcw, Search, UserPlus } from '@lucide/vue';
import { refDebounced } from '@vueuse/core';
import { computed, onMounted, ref, watch } from 'vue';
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
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { appDateFormatter } from '@/lib/timezone';
import { dashboard as adminHome } from '@/routes/admin';
import {
    destroy,
    index as invitationsRoute,
    resend,
    store,
} from '@/routes/admin/invitations';
import type { AdminContactMatch, AdminInvitation, Paginated } from '@/types';

/** The trail in the header beside the sidebar's collapse button. */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: adminHome() },
            { title: 'Invitations', href: invitationsRoute() },
        ],
    },
});

const props = defineProps<{
    status: 'all' | 'pending' | 'accepted' | 'expired' | 'cancelled';
    search: string;
    counts: { pending: number; accepted: number; expired: number };
    invitations: Paginated<AdminInvitation>;
    /** Only loaded while the invite dialog looks an email up. */
    contacts?: {
        email: string | null;
        results: AdminContactMatch[];
        error: string | null;
    };
}>();

const status = ref(props.status);
const search = ref(props.search);
const debouncedSearch = refDebounced(search, 300);

function reload(page?: number): void {
    router.get(
        invitationsRoute().url,
        { status: status.value, search: search.value || undefined, page },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch([status, debouncedSearch], () => reload());

/* The invite dialog. */
const dialogOpen = ref(false);
const form = useForm({
    email: '',
    name: '',
    role: 'resident' as 'owner' | 'resident',
    propertyware_id: '',
});
const looking = ref(false);
const debouncedEmail = refDebounced(
    computed(() => form.email),
    500,
);

watch(debouncedEmail, (email) => {
    if (!dialogOpen.value || !/^\S+@\S+\.\S+$/.test(email)) {
        return;
    }

    router.reload({
        only: ['contacts'],
        data: { lookup: email },
        onStart: () => (looking.value = true),
        onFinish: () => (looking.value = false),
    });
});

function openInvite(prefill: Partial<typeof form.data> = {}): void {
    form.reset();
    form.clearErrors();
    Object.assign(form, prefill);
    dialogOpen.value = true;
}

function choose(contact: AdminContactMatch): void {
    form.role = contact.role;
    form.propertyware_id = contact.id;

    if (!form.name) {
        form.name = contact.name;
    }
}

function send(): void {
    form.post(store().url, {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
            form.reset();
        },
    });
}

/** Find by property links here with the person filled in. */
onMounted(() => {
    const params = new URLSearchParams(window.location.search);

    if (params.get('invite') === '1') {
        openInvite({
            email: params.get('email') ?? '',
            name: params.get('name') ?? '',
            role: params.get('role') === 'owner' ? 'owner' : 'resident',
            propertyware_id: params.get('contact') ?? '',
        });
    }
});

const statusVariant: Record<
    AdminInvitation['status'],
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    pending: 'default',
    accepted: 'secondary',
    expired: 'outline',
    cancelled: 'outline',
};

const dated = appDateFormatter({ dateStyle: 'medium' });

function when(value: string | null): string {
    return value ? dated.format(new Date(value)) : '—';
}
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Invitations" />

        <PageHeader
            title="Invitations"
            description="Invite Propertyware owners and tenants to the portal, and follow up on links that were never used."
        >
            <template #actions>
                <Button size="sm" @click="openInvite()">
                    <UserPlus class="size-4" />
                    Invite a client
                </Button>
            </template>
        </PageHeader>

        <div class="flex flex-wrap items-center gap-3">
            <Tabs v-model="status">
                <TabsList>
                    <TabsTrigger value="all">All</TabsTrigger>
                    <TabsTrigger value="pending"
                        >Pending
                        <Badge
                            v-if="counts.pending"
                            variant="secondary"
                            class="ml-1"
                            >{{ counts.pending }}</Badge
                        ></TabsTrigger
                    >
                    <TabsTrigger value="accepted">Accepted</TabsTrigger>
                    <TabsTrigger value="expired"
                        >Expired
                        <Badge
                            v-if="counts.expired"
                            variant="secondary"
                            class="ml-1"
                            >{{ counts.expired }}</Badge
                        ></TabsTrigger
                    >
                    <TabsTrigger value="cancelled">Cancelled</TabsTrigger>
                </TabsList>
            </Tabs>
            <div class="relative w-full max-w-sm">
                <Search
                    class="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2"
                />
                <Input
                    v-model="search"
                    class="pl-9"
                    placeholder="Search name or email"
                    aria-label="Search invitations"
                />
            </div>
        </div>

        <AppCard>
            <EmptyState
                v-if="invitations.data.length === 0"
                :icon="MailPlus"
                title="No invitations"
                :description="
                    search || status !== 'all'
                        ? 'Nothing matches this search or status.'
                        : 'Invite a client to get them onto the portal.'
                "
            />

            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>Invited</TableHead>
                        <TableHead>Portal</TableHead>
                        <TableHead>Sent</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead class="text-right">
                            <span class="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="invitation in invitations.data"
                        :key="invitation.id"
                    >
                        <TableCell>
                            <p class="font-medium">{{ invitation.name }}</p>
                            <p class="text-muted-foreground text-sm">
                                {{ invitation.email }}
                            </p>
                        </TableCell>
                        <TableCell>
                            <Badge variant="outline" class="capitalize">{{
                                invitation.role
                            }}</Badge>
                            <p class="text-muted-foreground mt-1 text-xs">
                                Propertyware {{ invitation.propertywareId }}
                            </p>
                        </TableCell>
                        <TableCell class="text-sm">
                            {{ when(invitation.sentAt) }}
                            <p
                                v-if="invitation.invitedBy"
                                class="text-muted-foreground text-xs"
                            >
                                by {{ invitation.invitedBy }}
                            </p>
                        </TableCell>
                        <TableCell>
                            <Badge
                                :variant="statusVariant[invitation.status]"
                                class="capitalize"
                                >{{ invitation.status }}</Badge
                            >
                            <p class="text-muted-foreground mt-1 text-xs">
                                <template
                                    v-if="invitation.status === 'accepted'"
                                    >{{ when(invitation.acceptedAt) }}</template
                                >
                                <template
                                    v-else-if="invitation.status === 'pending'"
                                    >Until
                                    {{ when(invitation.expiresAt) }}</template
                                >
                            </p>
                        </TableCell>
                        <TableCell>
                            <div
                                v-if="invitation.status !== 'accepted'"
                                class="flex flex-wrap justify-end gap-2"
                            >
                                <ConfirmAction
                                    title="Send a new invitation link?"
                                    :description="`A fresh link goes to ${invitation.email}, valid for 7 days. Any older link stops working.`"
                                    confirm-label="Resend"
                                    :action="resend(invitation.id)"
                                >
                                    <Button variant="outline" size="sm">
                                        <RotateCcw class="size-4" />
                                        Resend
                                    </Button>
                                </ConfirmAction>
                                <ConfirmAction
                                    v-if="invitation.status === 'pending'"
                                    title="Cancel this invitation?"
                                    description="The link in their email stops working. You can invite them again later."
                                    confirm-label="Cancel invitation"
                                    destructive
                                    :action="destroy(invitation.id)"
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="text-destructive"
                                    >
                                        <Ban class="size-4" />
                                        Cancel
                                    </Button>
                                </ConfirmAction>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <ResultsPagination
                :results="invitations"
                class="mt-4"
                @change="(page) => reload(page)"
            />
        </AppCard>

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Invite a client</DialogTitle>
                    <DialogDescription>
                        Enter their email and pick the Propertyware record it
                        belongs to. They get a link to set up their login, valid
                        for 7 days.
                    </DialogDescription>
                </DialogHeader>

                <form class="grid gap-4" @submit.prevent="send">
                    <div class="grid gap-2">
                        <Label for="invite-email">Email address</Label>
                        <Input
                            id="invite-email"
                            v-model="form.email"
                            type="email"
                            autocomplete="off"
                            required
                        />
                        <InputError :message="form.errors.email" />
                    </div>

                    <div
                        v-if="looking"
                        class="text-muted-foreground flex items-center gap-2 text-sm"
                    >
                        <Spinner class="size-4" /> Looking in Propertyware…
                    </div>
                    <template
                        v-else-if="contacts && contacts.email === form.email"
                    >
                        <p
                            v-if="contacts.error"
                            class="text-destructive text-sm"
                        >
                            {{ contacts.error }}
                        </p>
                        <p
                            v-else-if="contacts.results.length === 0"
                            class="text-muted-foreground text-sm"
                        >
                            No Propertyware tenant or owner uses this address.
                            Enter the contact ID by hand if you know it.
                        </p>
                        <div v-else class="grid gap-2">
                            <p class="text-sm font-medium">
                                Propertyware records with this email
                            </p>
                            <button
                                v-for="contact in contacts.results"
                                :key="`${contact.role}-${contact.id}`"
                                type="button"
                                class="border-border hover:bg-muted data-[chosen=true]:border-primary data-[chosen=true]:bg-primary/5 flex items-start justify-between gap-3 rounded-lg border p-3 text-left disabled:cursor-not-allowed disabled:opacity-60"
                                :data-chosen="
                                    form.propertyware_id === contact.id &&
                                    form.role === contact.role
                                "
                                :disabled="
                                    contact.linkedTo !== null ||
                                    contact.pendingInvitation
                                "
                                @click="choose(contact)"
                            >
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium">{{
                                        contact.name || 'Unnamed'
                                    }}</span>
                                    <span
                                        class="text-muted-foreground block text-xs"
                                    >
                                        #{{ contact.id }} ·
                                        <template
                                            v-if="contact.role === 'owner'"
                                            >{{
                                                contact.portfolios
                                            }}
                                            portfolio(s)</template
                                        ><template v-else
                                            >{{
                                                contact.leases
                                            }}
                                            lease(s)</template
                                        >
                                    </span>
                                    <span
                                        v-if="contact.linkedTo"
                                        class="text-muted-foreground block text-xs"
                                        >Already on the portal as
                                        {{ contact.linkedTo }}</span
                                    >
                                    <span
                                        v-else-if="contact.pendingInvitation"
                                        class="text-muted-foreground block text-xs"
                                        >Already has a pending invitation</span
                                    >
                                </span>
                                <Badge variant="outline" class="capitalize">{{
                                    contact.role
                                }}</Badge>
                            </button>
                        </div>
                    </template>

                    <div class="grid gap-2">
                        <Label for="invite-name">Name for the email</Label>
                        <Input
                            id="invite-name"
                            v-model="form.name"
                            autocomplete="off"
                            required
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label for="invite-role">Portal</Label>
                            <Select v-model="form.role">
                                <SelectTrigger id="invite-role" class="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="resident"
                                        >Resident</SelectItem
                                    >
                                    <SelectItem value="owner">Owner</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.role" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="invite-contact"
                                >Propertyware contact ID</Label
                            >
                            <Input
                                id="invite-contact"
                                v-model="form.propertyware_id"
                                inputmode="numeric"
                                autocomplete="off"
                                required
                            />
                        </div>
                    </div>
                    <InputError :message="form.errors.propertyware_id" />

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="dialogOpen = false"
                            >Cancel</Button
                        >
                        <Button type="submit" :disabled="form.processing">
                            <Spinner v-if="form.processing" />
                            <MailPlus v-else class="size-4" />
                            Send invitation
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
