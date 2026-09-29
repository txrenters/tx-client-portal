<script setup lang="ts">
import { router, useHttp, usePage } from '@inertiajs/vue3';
import { ArrowRight, X } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { iconFor, themeFor } from '@/lib/promotionDesign';
import { show as promotionRoute } from '@/routes/promotion';
import { record } from '@/routes/promotions';
import { index as ownerReferrals } from '@/routes/owner/referrals';
import { index as residentReferrals } from '@/routes/resident/referrals';
import type { Promotion } from '@/types';

/**
 * The once-a-day promotion popup (the referral program first). The portal
 * decides whether one is due: at most once a day per person, across the web
 * and the apps, and never again once they turn it off or, for a referral
 * promotion, once they have referred someone.
 */
const props = defineProps<{
    /**
     * Staff previewing a promotion in the admin area: it is shown straight
     * away, nothing is recorded, and the button only closes it.
     */
    preview?: Promotion | null;
}>();

const emit = defineEmits<{ closed: [] }>();

const isPreview = props.preview !== undefined;

const page = usePage();
const promotion = ref<Promotion | null>(null);
const open = ref(false);

watch(
    () => props.preview,
    (shown) => {
        if (shown) {
            promotion.value = shown;
            open.value = true;
        }
    },
    { immediate: true },
);

watch(open, (isOpen) => {
    if (!isOpen && isPreview) {
        emit('closed');
    }
});

const loader = useHttp({});
const recorder = useHttp({});

/** The colours and picture staff chose for it. */
const theme = computed(() => themeFor(promotion.value?.theme));
const icon = computed(() => iconFor(promotion.value?.icon));

function track(action: 'seen' | 'click' | 'hide'): void {
    if (promotion.value && !isPreview) {
        recorder.post(record.url({ promotion: promotion.value.id, action }));
    }
}

onMounted(() => {
    if (isPreview) {
        return;
    }

    loader.get(promotionRoute.url(), {
        onSuccess: (response) => {
            const due = (response as { data: Promotion | null }).data;

            if (due) {
                promotion.value = due;
                open.value = true;
                // Counts as today's showing, on the web and in the app.
                track('seen');
            }
        },
    });
});

function go(): void {
    const due = promotion.value;
    track('click');
    open.value = false;

    if (!due || isPreview) {
        return;
    }

    if (due.link.type === 'referrals') {
        router.visit(
            page.props.client?.activeRole === 'owner'
                ? ownerReferrals()
                : residentReferrals(),
        );

        return;
    }

    window.open(due.link.url, '_blank', 'noopener');
}

function hideForGood(): void {
    track('hide');
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            v-if="promotion"
            :show-close-button="false"
            class="gap-0 overflow-hidden rounded-2xl border-0 p-0 shadow-2xl sm:max-w-[26rem]"
            @open-auto-focus.prevent
        >
            <div
                class="relative isolate overflow-hidden bg-gradient-to-br px-8 pt-10 pb-8 text-center"
                :class="[theme.header, theme.text]"
            >
                <!-- Soft shapes, so the header reads as a card rather than a flat block. -->
                <span
                    aria-hidden="true"
                    class="bg-current/10 absolute -top-16 -right-12 -z-10 size-48 rounded-full"
                />
                <span
                    aria-hidden="true"
                    class="bg-current/10 absolute -bottom-20 -left-16 -z-10 size-52 rounded-full"
                />

                <DialogClose
                    class="text-current/80 hover:bg-current/15 hover:text-current focus-visible:ring-current/60 absolute top-3 right-3 rounded-full p-1.5 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                >
                    <X class="size-5" />
                    <span class="sr-only">Close</span>
                </DialogClose>

                <span
                    class="bg-current/15 inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold tracking-[0.12em] uppercase"
                    >TexasRenters.com</span
                >

                <div
                    class="bg-current/15 ring-current/25 mx-auto mt-5 flex size-16 items-center justify-center rounded-2xl ring-1"
                >
                    <component :is="icon" class="size-8" />
                </div>

                <DialogHeader class="mt-5 items-center gap-3 text-center">
                    <DialogTitle
                        class="text-current text-3xl leading-tight font-bold tracking-tight"
                        >{{ promotion.title }}</DialogTitle
                    >
                    <DialogDescription
                        class="text-current/90 max-w-xs text-base leading-relaxed whitespace-pre-line"
                        >{{ promotion.body }}</DialogDescription
                    >
                </DialogHeader>
            </div>

            <div
                class="bg-popover flex flex-col items-center gap-2 px-6 pt-6 pb-5"
            >
                <Button
                    size="lg"
                    class="h-12 w-full rounded-full text-base font-semibold shadow-sm"
                    :class="theme.button"
                    @click="go"
                >
                    {{ promotion.buttonLabel }}
                    <ArrowRight class="size-4" />
                </Button>
                <button
                    type="button"
                    class="text-muted-foreground hover:text-foreground rounded-md px-2 py-1.5 text-sm transition-colors"
                    @click="hideForGood"
                >
                    Don't show this again
                </button>
            </div>
        </DialogContent>
    </Dialog>
</template>
