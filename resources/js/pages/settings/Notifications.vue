<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { edit, update } from '@/routes/notifications';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Notification settings',
                href: edit(),
            },
        ],
    },
});

const props = defineProps<{
    preferences: {
        message_replies_email: boolean;
        /** Pushed to the mobile app; there is no switch for it here. */
        message_replies_push: boolean;
        /** Owners: last month's income statement by email. */
        monthly_statement_email: boolean;
    };
    isOwner: boolean;
    /**
     * Texted replies live in the messaging hub: undefined while it answers,
     * null when this login has no client account or the hub is unreachable.
     */
    sms?: { enabled: boolean; phone: string | null } | null;
}>();

const form = useForm({ ...props.preferences });

/** Each switch saves as soon as it changes. */
function save(): void {
    form.submit(update(), { preserveScroll: true });
}

const smsForm = useForm({ message_replies_sms: false });

watch(
    () => props.sms,
    (sms) => {
        smsForm.message_replies_sms = sms?.enabled ?? false;
    },
    { immediate: true },
);

function saveSms(): void {
    smsForm.submit(update(), { preserveScroll: true, only: ['sms', 'flash'] });
}
</script>

<template>
    <Head title="Notification settings" />

    <h1 class="sr-only">Notification settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Notifications"
            description="Choose how we let you know about new activity"
        />

        <div
            class="flex items-start justify-between gap-4 rounded-xl border p-4"
        >
            <div class="space-y-1">
                <Label for="message-replies-email"
                    >Email me about replies</Label
                >
                <p class="text-muted-foreground text-sm">
                    When our team replies to a message you sent here, we'll
                    email you so you don't miss it.
                </p>
            </div>
            <Switch
                id="message-replies-email"
                v-model="form.message_replies_email"
                :disabled="form.processing"
                @update:model-value="save"
            />
        </div>

        <div
            v-if="isOwner"
            class="flex items-start justify-between gap-4 rounded-xl border p-4"
        >
            <div class="space-y-1">
                <Label for="monthly-statement-email"
                    >Email me my monthly statement</Label
                >
                <p class="text-muted-foreground text-sm">
                    Early each month we'll email last month's income statement,
                    as a PDF for each of your portfolios.
                </p>
            </div>
            <Switch
                id="monthly-statement-email"
                v-model="form.monthly_statement_email"
                :disabled="form.processing"
                @update:model-value="save"
            />
        </div>

        <div
            v-if="sms !== null"
            class="flex items-start justify-between gap-4 rounded-xl border p-4"
        >
            <div class="space-y-1">
                <Label for="message-replies-sms">Text me replies</Label>
                <p class="text-muted-foreground text-sm">
                    <template v-if="sms === undefined">
                        Checking where we can text you…
                    </template>
                    <template v-else-if="sms.phone">
                        We'll also text our replies to {{ sms.phone }}.
                    </template>
                    <template v-else>
                        We don't have a mobile number for you yet, so we can
                        only reply here and by email.
                    </template>
                </p>
            </div>
            <Skeleton v-if="sms === undefined" class="h-6 w-11 rounded-full" />
            <Switch
                v-else
                id="message-replies-sms"
                v-model="smsForm.message_replies_sms"
                :disabled="smsForm.processing || !sms.phone"
                @update:model-value="saveSms"
            />
        </div>
    </div>
</template>
