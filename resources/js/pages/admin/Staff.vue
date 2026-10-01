<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ShieldCheck, UserMinus, UserPlus } from '@lucide/vue';
import { ref } from 'vue';
import AppCard from '@/components/AppCard.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
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
import { appDateFormatter } from '@/lib/timezone';
import { dashboard as adminHome } from '@/routes/admin';
import {
    destroy,
    index as staffRoute,
    store,
    update,
} from '@/routes/admin/staff';
import { show as showUser } from '@/routes/admin/users';
import type { AdminStaffMember } from '@/types';

/** The trail in the header beside the sidebar's collapse button. */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: adminHome() },
            { title: 'Staff', href: staffRoute() },
        ],
    },
});

defineProps<{
    staff: AdminStaffMember[];
    currentUserId: number;
}>();

const roleLabels = { owner: 'Owner', admin: 'Admin', staff: 'Staff' } as const;

const dialogOpen = ref(false);
const form = useForm({
    email: '',
    name: '',
    role: 'staff' as 'admin' | 'staff',
});

function openAdd(): void {
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function add(): void {
    form.post(store().url, {
        preserveScroll: true,
        onSuccess: () => (dialogOpen.value = false),
    });
}

function changeRole(member: AdminStaffMember, role: unknown): void {
    if (
        member.id === null ||
        typeof role !== 'string' ||
        role === member.role
    ) {
        return;
    }

    router.patch(update(member.id).url, { role }, { preserveScroll: true });
}

const dated = appDateFormatter({ dateStyle: 'medium' });
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Staff" />

        <PageHeader
            title="Staff"
            description="Who can use the admin area. Admins can also manage this list; staff can do everything else."
        >
            <template #actions>
                <Button size="sm" @click="openAdd">
                    <UserPlus class="size-4" />
                    Add staff
                </Button>
            </template>
        </PageHeader>

        <AppCard>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Person</TableHead>
                        <TableHead>Access</TableHead>
                        <TableHead>Last seen</TableHead>
                        <TableHead class="text-right">
                            <span class="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="member in staff" :key="member.email">
                        <TableCell>
                            <Link
                                v-if="member.id"
                                :href="showUser(member.id)"
                                class="font-medium hover:underline"
                                >{{ member.name }}</Link
                            >
                            <p v-else class="font-medium">{{ member.name }}</p>
                            <p class="text-muted-foreground text-sm">
                                {{ member.email }}
                                <span v-if="member.id === currentUserId"
                                    >(you)</span
                                >
                            </p>
                            <Badge
                                v-if="member.id && !member.twoFactor"
                                variant="outline"
                                class="mt-1"
                                >No two-factor</Badge
                            >
                        </TableCell>
                        <TableCell>
                            <Select
                                v-if="
                                    !member.fixed && member.id !== currentUserId
                                "
                                :model-value="member.role"
                                @update:model-value="changeRole(member, $event)"
                            >
                                <SelectTrigger
                                    class="w-32"
                                    :aria-label="`Access for ${member.name}`"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="admin">Admin</SelectItem>
                                    <SelectItem value="staff">Staff</SelectItem>
                                </SelectContent>
                            </Select>
                            <Badge
                                v-else
                                :variant="
                                    member.fixed ? 'default' : 'secondary'
                                "
                                >{{ roleLabels[member.role] }}</Badge
                            >
                            <p
                                v-if="member.fixed"
                                class="text-muted-foreground mt-1 text-xs"
                            >
                                Set in ADMIN_EMAILS<span v-if="!member.id"
                                    >, no login yet</span
                                >
                            </p>
                        </TableCell>
                        <TableCell class="text-sm">
                            {{
                                member.lastSeenAt
                                    ? dated.format(new Date(member.lastSeenAt))
                                    : '—'
                            }}
                        </TableCell>
                        <TableCell class="text-right">
                            <ConfirmAction
                                v-if="
                                    !member.fixed &&
                                    member.id &&
                                    member.id !== currentUserId
                                "
                                :title="`Remove staff access from ${member.name}?`"
                                description="They lose the admin area straight away. Their login stays, so they can still use the client portal if they have accounts there."
                                confirm-label="Remove access"
                                destructive
                                :action="destroy(member.id)"
                            >
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="text-destructive"
                                >
                                    <UserMinus class="size-4" />
                                    Remove
                                </Button>
                            </ConfirmAction>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <p class="text-muted-foreground mt-4 text-sm text-pretty">
                Owners are set on the server in ADMIN_EMAILS and cannot be
                changed here, so the portal can never lose its last way in.
                Nobody can change their own access. Every change is in the
                activity log.
            </p>
        </AppCard>

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Add staff</DialogTitle>
                    <DialogDescription>
                        If they have no login yet, one is made and they are
                        emailed a link to choose their password. An existing
                        login just gains access.
                    </DialogDescription>
                </DialogHeader>

                <form class="grid gap-4" @submit.prevent="add">
                    <div class="grid gap-2">
                        <Label for="staff-email">Email address</Label>
                        <Input
                            id="staff-email"
                            v-model="form.email"
                            type="email"
                            autocomplete="off"
                            placeholder="name@texasrenters.com"
                            required
                        />
                        <InputError :message="form.errors.email" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="staff-name">Name</Label>
                        <Input
                            id="staff-name"
                            v-model="form.name"
                            autocomplete="off"
                            required
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="staff-role">Access</Label>
                        <Select v-model="form.role">
                            <SelectTrigger id="staff-role" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="staff"
                                    >Staff: everything except managing
                                    staff</SelectItem
                                >
                                <SelectItem value="admin"
                                    >Admin: everything, including
                                    staff</SelectItem
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
                        <Button type="submit" :disabled="form.processing">
                            <Spinner v-if="form.processing" />
                            <ShieldCheck v-else class="size-4" />
                            Give access
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
