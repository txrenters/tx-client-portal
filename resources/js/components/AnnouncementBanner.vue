<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Megaphone, TriangleAlert, X } from '@lucide/vue';
import { computed } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { dismiss } from '@/routes/announcements';

/**
 * Notices from TexasRenters.com, above every client page until closed.
 * Closing one closes it in the app too.
 */
const page = usePage();

const announcements = computed(() => page.props.announcements ?? []);

function close(id: number): void {
    router.post(
        dismiss(id).url,
        {},
        { preserveScroll: true, preserveState: true, only: ['announcements'] },
    );
}
</script>

<template>
    <div
        v-if="announcements.length > 0"
        class="flex flex-col gap-2 px-4 pt-4 md:px-6"
    >
        <Alert
            v-for="announcement in announcements"
            :key="announcement.id"
            :variant="
                announcement.tone === 'warning' ? 'destructive' : 'default'
            "
            class="pr-12"
        >
            <TriangleAlert v-if="announcement.tone === 'warning'" />
            <Megaphone v-else />
            <AlertTitle>{{ announcement.title }}</AlertTitle>
            <AlertDescription class="whitespace-pre-line">{{
                announcement.body
            }}</AlertDescription>
            <Button
                variant="ghost"
                size="icon"
                class="absolute top-2 right-2 size-7"
                :aria-label="`Close ${announcement.title}`"
                @click="close(announcement.id)"
            >
                <X class="size-4" />
            </Button>
        </Alert>
    </div>
</template>
