<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { MeetingType } from '@/types';

/**
 * Book one meeting on coresched's own calendar, framed inside the portal.
 *
 * Coresched's embed page is the same booking flow as its public page (the
 * host's real open times, its questions, the confirmation) without the page
 * around it. The owner's name, email and phone ride along on the address so
 * the form starts filled in, and they can change them there. The Booked list
 * picks the booking up from coresched's webhook.
 *
 * The window keeps a fixed height and only the calendar scrolls, inside the
 * frame, so there is one scrollbar however long coresched's steps get. The
 * calendar shows the meeting's name itself, so the title here is for screen
 * readers only.
 */
const props = defineProps<{
    meeting: MeetingType | null;
    /** The owner's number from Propertyware, for the phone-call meetings. */
    phone?: string | null;
}>();
const open = defineModel<boolean>('open', { required: true });

const page = usePage();

/** The meeting's /book address with /embed in its place, and the owner's details. */
const src = computed<string | null>(() => {
    if (!props.meeting?.url) {
        return null;
    }

    const url = new URL(props.meeting.url);
    url.pathname = url.pathname.replace(/^\/book\//, '/embed/');

    const user = page.props.auth.user;
    const details: Record<string, string | null | undefined> = {
        name: user?.name,
        email: user?.email,
        phone: props.phone,
    };

    for (const [key, value] of Object.entries(details)) {
        if (value) {
            url.searchParams.set(key, value);
        }
    }

    return url.toString();
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            v-if="meeting && src"
            class="flex h-[92dvh] flex-col gap-0 overflow-hidden p-0 sm:max-w-3xl"
        >
            <DialogHeader class="sr-only">
                <DialogTitle>{{ meeting.name }}</DialogTitle>
                <DialogDescription>
                    Pick a day and time. We'll email you the invitation.
                </DialogDescription>
            </DialogHeader>
            <iframe
                :src="src"
                :title="`Book ${meeting.name}`"
                class="block min-h-0 w-full flex-1 border-0"
            />
        </DialogContent>
    </Dialog>
</template>
