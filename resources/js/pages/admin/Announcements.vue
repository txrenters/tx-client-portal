<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Megaphone, Pencil, Plus, Square, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppCard from '@/components/AppCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import ConfirmAction from '@/components/admin/ConfirmAction.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Textarea } from '@/components/ui/textarea';
import { appDateFormatter } from '@/lib/timezone';
import { dashboard as adminHome } from '@/routes/admin';
import {
    destroy,
    end,
    index as announcementsRoute,
    store,
    update,
} from '@/routes/admin/announcements';
import type { AdminAnnouncement } from '@/types';

/** The trail in the header beside the sidebar's collapse button. */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: adminHome() },
            { title: 'Announcements', href: announcementsRoute() },
        ],
    },
});

const props = defineProps<{
    announcements: AdminAnnouncement[];
    /** How many confirmed logins each audience reaches. */
    audienceSizes: { all: number; owner: number; resident: number };
}>();

const audienceLabels = {
    all: 'Everyone',
    owner: 'Owners',
    resident: 'Residents',
} as const;

const editing = ref<AdminAnnouncement | null>(null);
const dialogOpen = ref(false);

const form = useForm({
    title: '',
    body: '',
    audience: 'all' as AdminAnnouncement['audience'],
    tone: 'info' as AdminAnnouncement['tone'],
    starts_at: '',
    ends_at: '',
    send_email: false,
    send_push: false,
});

/** An ISO time as a datetime-local input wants it, in the browser's zone. */
function toLocalInput(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);
    const offset = date.getTimezoneOffset() * 60_000;

    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

/** A datetime-local value sent with its offset, so the server stores the right moment. */
function fromLocalInput(value: string): string | null {
    return value ? new Date(value).toISOString() : null;
}

function openEditor(announcement: AdminAnnouncement | null): void {
    editing.value = announcement;
    form.clearErrors();
    form.defaults({
        title: announcement?.title ?? '',
        body: announcement?.body ?? '',
        audience: announcement?.audience ?? 'all',
        tone: announcement?.tone ?? 'info',
        starts_at: toLocalInput(announcement?.startsAt ?? null),
        ends_at: toLocalInput(announcement?.endsAt ?? null),
        send_email: false,
        send_push: false,
    });
    form.reset();
    dialogOpen.value = true;
}

function save(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (dialogOpen.value = false),
    };

    form.transform((data) => ({
        ...data,
        starts_at: fromLocalInput(data.starts_at),
        ends_at: fromLocalInput(data.ends_at),
    }));

    if (editing.value) {
        form.put(update(editing.value.id).url, options);
    } else {
        form.post(store().url, options);
    }
}

const reach = computed(() => props.audienceSizes[form.audience]);

const stamped = appDateFormatter({ dateStyle: 'medium', timeStyle: 'short' });

function when(value: string | null): string {
    return value ? stamped.format(new Date(value)) : '';
}
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Announcements" />

        <PageHeader
            title="Announcements"
            description="Notices shown as a banner on the web portal and in the app until each client closes them. Email and push are optional and go once."
        >
            <template #actions>
                <Button size="sm" @click="openEditor(null)">
                    <Plus class="size-4" />
                    New announcement
                </Button>
            </template>
        </PageHeader>

        <AppCard v-if="announcements.length === 0">
            <EmptyState
                :icon="Megaphone"
                title="No announcements"
                description="Post one for an office closure, a change to payments, or an outage."
            />
        </AppCard>

        <AppCard v-for="announcement in announcements" :key="announcement.id">
            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-medium">{{ announcement.title }}</p>
                        <p class="text-muted-foreground text-xs">
                            {{ audienceLabels[announcement.audience] }} · posted
                            {{ when(announcement.postedAt) }}
                            <span v-if="announcement.createdBy">
                                by {{ announcement.createdBy }}</span
                            >
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <Badge
                            v-if="announcement.tone === 'warning'"
                            variant="destructive"
                            >Warning</Badge
                        >
                        <Badge
                            :variant="
                                announcement.isLive ? 'default' : 'outline'
                            "
                            >{{
                                announcement.isLive ? 'Showing' : 'Not showing'
                            }}</Badge
                        >
                        <Badge v-if="announcement.emailedAt" variant="secondary"
                            >Emailed</Badge
                        >
                        <Badge v-if="announcement.pushedAt" variant="secondary"
                            >Pushed</Badge
                        >
                    </div>
                </div>

                <p class="text-sm whitespace-pre-line">
                    {{ announcement.body }}
                </p>

                <p class="text-muted-foreground text-xs">
                    <span v-if="announcement.startsAt"
                        >From {{ when(announcement.startsAt) }}.
                    </span>
                    <span v-if="announcement.endsAt"
                        >Until {{ when(announcement.endsAt) }}.
                    </span>
                    Closed by {{ announcement.dismissals }} client(s).
                </p>

                <div class="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="openEditor(announcement)"
                    >
                        <Pencil class="size-4" />
                        Edit
                    </Button>
                    <ConfirmAction
                        v-if="announcement.isLive"
                        title="Take this announcement down?"
                        description="It stops showing straight away. It stays in this list, and you can put it back up by editing its dates."
                        confirm-label="Take down"
                        :action="end(announcement.id)"
                    >
                        <Button variant="outline" size="sm">
                            <Square class="size-4" />
                            Take down
                        </Button>
                    </ConfirmAction>
                    <ConfirmAction
                        :title="`Delete “${announcement.title}”?`"
                        description="It stops showing and is removed from this list for good."
                        confirm-label="Delete"
                        destructive
                        :action="destroy(announcement.id)"
                    >
                        <Button
                            variant="ghost"
                            size="sm"
                            class="text-destructive"
                        >
                            <Trash2 class="size-4" />
                            Delete
                        </Button>
                    </ConfirmAction>
                </div>
            </div>
        </AppCard>

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{
                        editing ? 'Edit announcement' : 'New announcement'
                    }}</DialogTitle>
                    <DialogDescription
                        >Shown on the web and in the app as soon as it
                        starts.</DialogDescription
                    >
                </DialogHeader>

                <form class="grid gap-4" @submit.prevent="save">
                    <div class="grid gap-2">
                        <Label for="announcement-title">Title</Label>
                        <Input
                            id="announcement-title"
                            v-model="form.title"
                            maxlength="120"
                            required
                        />
                        <InputError :message="form.errors.title" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="announcement-body">Message</Label>
                        <Textarea
                            id="announcement-body"
                            v-model="form.body"
                            rows="4"
                            maxlength="2000"
                            required
                        />
                        <InputError :message="form.errors.body" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label for="announcement-audience"
                                >Who sees it</Label
                            >
                            <Select v-model="form.audience">
                                <SelectTrigger
                                    id="announcement-audience"
                                    class="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all"
                                        >Everyone</SelectItem
                                    >
                                    <SelectItem value="owner"
                                        >Owners</SelectItem
                                    >
                                    <SelectItem value="resident"
                                        >Residents</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="announcement-tone">Style</Label>
                            <Select v-model="form.tone">
                                <SelectTrigger
                                    id="announcement-tone"
                                    class="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="info">Notice</SelectItem>
                                    <SelectItem value="warning"
                                        >Warning</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label for="announcement-starts">
                                Starts
                                <span class="text-muted-foreground font-normal"
                                    >(optional)</span
                                >
                            </Label>
                            <Input
                                id="announcement-starts"
                                v-model="form.starts_at"
                                type="datetime-local"
                            />
                            <InputError :message="form.errors.starts_at" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="announcement-ends">
                                Ends
                                <span class="text-muted-foreground font-normal"
                                    >(optional)</span
                                >
                            </Label>
                            <Input
                                id="announcement-ends"
                                v-model="form.ends_at"
                                type="datetime-local"
                            />
                            <InputError :message="form.errors.ends_at" />
                        </div>
                    </div>

                    <div class="grid gap-3 rounded-lg border p-3">
                        <p class="text-sm">
                            Reaches {{ reach }} confirmed client login(s).
                        </p>
                        <Label
                            v-if="!editing?.emailedAt"
                            class="flex items-center gap-2 font-normal"
                        >
                            <Checkbox v-model="form.send_email" />
                            Also email it to them
                        </Label>
                        <Label
                            v-if="!editing?.pushedAt"
                            class="flex items-center gap-2 font-normal"
                        >
                            <Checkbox v-model="form.send_push" />
                            Also send it to the app as a notification
                        </Label>
                        <p class="text-muted-foreground text-xs">
                            Email and push go once, when you save, and cannot be
                            taken back.
                        </p>
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
                            {{ editing ? 'Save' : 'Post' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
