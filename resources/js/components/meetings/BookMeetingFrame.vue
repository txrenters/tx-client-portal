<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    computed,
    onMounted,
    onUnmounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
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
 */
const props = defineProps<{
    meeting: MeetingType | null;
    /** The owner's number from Propertyware, for the phone-call meetings. */
    phone?: string | null;
}>();
const open = defineModel<boolean>('open', { required: true });

const page = usePage();
const frame = useTemplateRef<HTMLIFrameElement>('frame');
/** The height the embed asks for; until it says, a sensible start. */
const height = ref(640);

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

watch(open, (isOpen) => {
    if (isOpen) {
        height.value = 640;
    }
});

/** The embed posts its height as it changes, so the frame never scrolls twice. */
function resize(event: MessageEvent): void {
    const data = event.data as { type?: string; height?: unknown } | null;

    if (
        !src.value ||
        event.origin !== new URL(src.value).origin ||
        event.source !== frame.value?.contentWindow ||
        data?.type !== 'coresched:resize' ||
        typeof data.height !== 'number'
    ) {
        return;
    }

    height.value = Math.max(data.height, 200);
}

onMounted(() => window.addEventListener('message', resize));
onUnmounted(() => window.removeEventListener('message', resize));
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            v-if="meeting && src"
            class="max-h-[92dvh] gap-3 overflow-y-auto p-0 sm:max-w-3xl"
        >
            <DialogHeader class="px-6 pt-6">
                <DialogTitle>{{ meeting.name }}</DialogTitle>
                <DialogDescription>
                    Pick a day and time. We'll email you the invitation.
                </DialogDescription>
            </DialogHeader>
            <iframe
                ref="frame"
                :src="src"
                :title="`Book ${meeting.name}`"
                class="block w-full border-0"
                :style="{ height: `${height}px` }"
            />
        </DialogContent>
    </Dialog>
</template>
