<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { Play } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { events as landingEvents } from '@/routes/landing';

/**
 * The web portal's tutorial in a dialog. The video comes from
 * ~/projects/tx-web-tutorial (Remotion), compressed into public/videos.
 */

/** Tells Insights the tutorial was played; it counts each visitor once a day. */
const tracker = useHttp({ event: 'tutorial_play' });

const trackPlay = (isOpen: boolean): void => {
    if (isOpen) {
        tracker.post(landingEvents.url());
    }
};
</script>

<template>
    <Dialog @update:open="trackPlay">
        <DialogTrigger as-child>
            <Button variant="outline" class="rounded-full">
                <Play class="size-4" />
                Watch the tutorial
            </Button>
        </DialogTrigger>
        <DialogContent class="overflow-hidden p-0 sm:max-w-4xl">
            <DialogTitle class="sr-only">
                How to use the TexasRenters.com client portal
            </DialogTitle>
            <DialogDescription class="sr-only">
                Signing up, reporting a repair, messaging the team and finding
                your documents, start to finish.
            </DialogDescription>
            <video
                class="aspect-video w-full"
                src="/videos/web-tutorial.mp4"
                poster="/videos/web-tutorial.webp"
                autoplay
                controls
                playsinline
            ></video>
        </DialogContent>
    </Dialog>
</template>
