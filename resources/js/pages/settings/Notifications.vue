<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
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
    };
}>();

const form = useForm({ ...props.preferences });

/** Each switch saves as soon as it changes. */
function save(): void {
    form.submit(update(), { preserveScroll: true });
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
    </div>
</template>
