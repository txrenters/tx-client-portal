<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, Eye, Megaphone, Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import AppCard from '@/components/AppCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import PromotionPopup from '@/components/PromotionPopup.vue';
import {
    iconFor,
    promotionIcons,
    promotionThemes,
    themeFor,
} from '@/lib/promotionDesign';
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
import {
    destroy as destroyRoute,
    store as storeRoute,
    update as updateRoute,
} from '@/routes/admin/promotions';
import type { AdminPromotion, Promotion } from '@/types';
import { dashboard as adminHome } from '@/routes/admin';
import { index as promotionsCrumb } from '@/routes/admin/promotions';

/** The trail in the header beside the sidebar's collapse button. */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: adminHome() },
            { title: 'Promotions', href: promotionsCrumb() },
        ],
    },
});

defineProps<{
    promotions: AdminPromotion[];
}>();

const audienceLabel = {
    all: 'Owners and residents',
    owner: 'Owners',
    resident: 'Residents',
} as const;

const shortDate = appDateFormatter({
    month: 'short',
    day: 'numeric',
    year: 'numeric',
});

/** The promotion being edited, or null while writing a new one. */
const editing = ref<AdminPromotion | null>(null);
const dialogOpen = ref(false);

/** "referrals" or "url"; the address itself lives in linkUrl. */
const linkType = ref<'referrals' | 'url'>('referrals');
const linkUrl = ref('');

/** The promotion being previewed, exactly as a client would see it. */
const previewing = ref<Promotion | null>(null);

function preview(promotion: AdminPromotion): void {
    previewing.value = {
        id: promotion.id,
        title: promotion.title,
        body: promotion.body,
        buttonLabel: promotion.buttonLabel,
        theme: promotion.theme,
        icon: promotion.icon,
        link: promotion.link,
    };
}

/** What the editor holds right now, before it is saved. */
function previewDraft(): void {
    previewing.value = {
        id: editing.value?.id ?? 0,
        title: form.title || 'Your title',
        body: form.body || 'Your message.',
        buttonLabel: form.button_label || 'Button',
        theme: form.theme,
        icon: form.icon,
        link:
            linkType.value === 'referrals'
                ? { type: 'referrals' }
                : { type: 'url', url: linkUrl.value },
    };
}

const form = useForm({
    title: '',
    body: '',
    button_label: '',
    button_link: 'referrals',
    audience: 'all',
    starts_at: '',
    ends_at: '',
    is_active: true,
    priority: 0,
    stop_after_referral: false,
    theme: 'blue',
    icon: 'gift',
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

function openEditor(promotion: AdminPromotion | null): void {
    editing.value = promotion;
    form.clearErrors();
    form.defaults({
        title: promotion?.title ?? '',
        body: promotion?.body ?? '',
        button_label: promotion?.buttonLabel ?? 'Refer an owner',
        button_link: promotion?.buttonLink ?? 'referrals',
        audience: promotion?.audience ?? 'all',
        starts_at: toLocalInput(promotion?.startsAt ?? null),
        ends_at: toLocalInput(promotion?.endsAt ?? null),
        is_active: promotion?.isActive ?? true,
        priority: promotion?.priority ?? 0,
        stop_after_referral: promotion?.stopAfterReferral ?? false,
        theme: promotion?.theme ?? 'blue',
        icon: promotion?.icon ?? 'gift',
    });
    form.reset();
    linkType.value =
        (promotion?.buttonLink ?? 'referrals') === 'referrals'
            ? 'referrals'
            : 'url';
    linkUrl.value = linkType.value === 'url' ? promotion!.buttonLink : '';
    dialogOpen.value = true;
}

function save(): void {
    form.button_link =
        linkType.value === 'referrals' ? 'referrals' : linkUrl.value;

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    };

    if (editing.value) {
        form.put(updateRoute(editing.value.id).url, options);
    } else {
        form.post(storeRoute().url, options);
    }
}

function toggle(promotion: AdminPromotion): void {
    router.put(
        updateRoute(promotion.id).url,
        {
            title: promotion.title,
            body: promotion.body,
            button_label: promotion.buttonLabel,
            button_link: promotion.buttonLink,
            audience: promotion.audience,
            starts_at: promotion.startsAt,
            ends_at: promotion.endsAt,
            is_active: !promotion.isActive,
            priority: promotion.priority,
            stop_after_referral: promotion.stopAfterReferral,
            theme: promotion.theme,
            icon: promotion.icon,
        },
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Promotions" />

        <PageHeader
            title="Promotions"
            description="Popups clients see on the web portal and in the app, at most once a day each."
        >
            <template #actions>
                <Button @click="openEditor(null)">
                    <Plus class="size-4" />
                    New promotion
                </Button>
            </template>
        </PageHeader>

        <AppCard v-if="promotions.length === 0">
            <EmptyState
                :icon="Megaphone"
                title="No promotions"
                description="Create one to pop it up for clients once a day."
            />
        </AppCard>

        <AppCard v-for="promotion in promotions" :key="promotion.id">
            <div class="flex flex-col gap-4 md:flex-row md:items-start">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span
                            class="flex size-8 items-center justify-center rounded-lg bg-gradient-to-br"
                            :class="[
                                themeFor(promotion.theme).header,
                                themeFor(promotion.theme).text,
                            ]"
                            :title="`${themeFor(promotion.theme).label} popup`"
                        >
                            <component
                                :is="iconFor(promotion.icon)"
                                class="size-4"
                            />
                        </span>
                        <h2 class="text-lg font-semibold">
                            {{ promotion.title }}
                        </h2>
                        <Badge
                            :variant="
                                promotion.isActive ? 'default' : 'outline'
                            "
                            >{{ promotion.isActive ? 'Running' : 'Off' }}</Badge
                        >
                        <Badge variant="secondary">{{
                            audienceLabel[promotion.audience]
                        }}</Badge>
                    </div>
                    <p
                        class="text-muted-foreground mt-1 text-sm whitespace-pre-line"
                    >
                        {{ promotion.body }}
                    </p>
                    <p class="text-muted-foreground mt-2 text-xs">
                        Button “{{ promotion.buttonLabel }}” →
                        {{
                            promotion.buttonLink === 'referrals'
                                ? 'Refer a friend page'
                                : promotion.buttonLink
                        }}
                        <template v-if="promotion.startsAt || promotion.endsAt">
                            ·
                            {{
                                promotion.startsAt
                                    ? shortDate.format(
                                          new Date(promotion.startsAt),
                                      )
                                    : 'Now'
                            }}
                            –
                            {{
                                promotion.endsAt
                                    ? shortDate.format(
                                          new Date(promotion.endsAt),
                                      )
                                    : 'no end date'
                            }}
                        </template>
                        <template v-if="promotion.stopAfterReferral">
                            · Stops once they refer someone</template
                        >
                    </p>
                </div>

                <dl class="grid grid-cols-3 gap-4 text-center md:w-72">
                    <div>
                        <dt class="text-muted-foreground text-xs">Shown to</dt>
                        <dd class="text-xl font-semibold tabular-nums">
                            {{ promotion.stats.shown }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Tapped</dt>
                        <dd class="text-xl font-semibold tabular-nums">
                            {{ promotion.stats.clicked }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            Turned off
                        </dt>
                        <dd class="text-xl font-semibold tabular-nums">
                            {{ promotion.stats.hidden }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <Button variant="outline" size="sm" @click="preview(promotion)">
                    <Eye class="size-4" />
                    Preview
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    @click="openEditor(promotion)"
                >
                    <Pencil class="size-4" />
                    Edit
                </Button>
                <Button variant="outline" size="sm" @click="toggle(promotion)">
                    {{ promotion.isActive ? 'Turn off' : 'Turn on' }}
                </Button>
                <ConfirmAction
                    :title="`Delete “${promotion.title}”?`"
                    description="Clients stop seeing it straight away, and its view and click counts are lost. Turn it off instead to keep them."
                    confirm-label="Delete"
                    destructive
                    :action="destroyRoute(promotion.id)"
                >
                    <Button variant="ghost" size="sm" class="text-destructive">
                        <Trash2 class="size-4" />
                        Delete
                    </Button>
                </ConfirmAction>
            </div>
        </AppCard>

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{
                        editing ? 'Edit promotion' : 'New promotion'
                    }}</DialogTitle>
                    <DialogDescription
                        >Pops up once a day for each client in the audience, on
                        the web and in the app.</DialogDescription
                    >
                </DialogHeader>

                <form class="grid gap-4" @submit.prevent="save">
                    <div class="grid gap-2">
                        <Label for="promo-title">Title</Label>
                        <Input
                            id="promo-title"
                            v-model="form.title"
                            maxlength="80"
                        />
                        <InputError :message="form.errors.title" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="promo-body">Message</Label>
                        <Textarea
                            id="promo-body"
                            v-model="form.body"
                            rows="3"
                            maxlength="500"
                        />
                        <InputError :message="form.errors.body" />
                    </div>

                    <div class="grid gap-2">
                        <Label id="promo-theme-label">Colour</Label>
                        <div
                            class="flex flex-wrap gap-2"
                            role="radiogroup"
                            aria-labelledby="promo-theme-label"
                        >
                            <button
                                v-for="(theme, name) in promotionThemes"
                                :key="name"
                                type="button"
                                role="radio"
                                :aria-checked="form.theme === name"
                                :aria-label="theme.label"
                                :title="theme.label"
                                class="ring-offset-background focus-visible:ring-ring aria-checked:ring-foreground flex size-9 items-center justify-center rounded-full bg-gradient-to-br ring-offset-2 outline-none focus-visible:ring-2 aria-checked:ring-2"
                                :class="[theme.header, theme.text]"
                                @click="form.theme = name"
                            >
                                <Check
                                    v-if="form.theme === name"
                                    class="size-4"
                                />
                            </button>
                        </div>
                        <p class="text-muted-foreground text-xs">
                            {{ themeFor(form.theme).label }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label id="promo-icon-label">Icon</Label>
                        <div
                            class="grid grid-cols-6 gap-2"
                            role="radiogroup"
                            aria-labelledby="promo-icon-label"
                        >
                            <button
                                v-for="(icon, name) in promotionIcons"
                                :key="name"
                                type="button"
                                role="radio"
                                :aria-checked="form.icon === name"
                                :aria-label="icon.label"
                                :title="icon.label"
                                class="border-border hover:bg-muted focus-visible:ring-ring flex h-10 items-center justify-center rounded-lg border outline-none focus-visible:ring-2 aria-checked:border-transparent aria-checked:bg-gradient-to-br"
                                :class="
                                    form.icon === name
                                        ? [
                                              themeFor(form.theme).header,
                                              themeFor(form.theme).text,
                                          ]
                                        : ''
                                "
                                @click="form.icon = name"
                            >
                                <component
                                    :is="icon.component"
                                    class="size-5"
                                />
                            </button>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="promo-button">Button text</Label>
                            <Input
                                id="promo-button"
                                v-model="form.button_label"
                                maxlength="40"
                            />
                            <InputError :message="form.errors.button_label" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="promo-link">Button opens</Label>
                            <Select v-model="linkType">
                                <SelectTrigger id="promo-link" class="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="referrals"
                                        >Refer a friend page</SelectItem
                                    >
                                    <SelectItem value="url"
                                        >A web address</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div v-if="linkType === 'url'" class="grid gap-2">
                        <Label for="promo-url">Web address</Label>
                        <Input
                            id="promo-url"
                            v-model="linkUrl"
                            type="url"
                            placeholder="https://texasrenters.com/…"
                        />
                    </div>
                    <InputError :message="form.errors.button_link" />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="promo-audience">Who sees it</Label>
                            <Select v-model="form.audience">
                                <SelectTrigger
                                    id="promo-audience"
                                    class="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all"
                                        >Owners and residents</SelectItem
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
                            <Label for="promo-priority">Priority</Label>
                            <Input
                                id="promo-priority"
                                v-model.number="form.priority"
                                type="number"
                                min="0"
                                max="100"
                            />
                            <p class="text-muted-foreground text-xs">
                                Higher shows first when several are running.
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="promo-starts">Starts (optional)</Label>
                            <Input
                                id="promo-starts"
                                v-model="form.starts_at"
                                type="datetime-local"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="promo-ends">Ends (optional)</Label>
                            <Input
                                id="promo-ends"
                                v-model="form.ends_at"
                                type="datetime-local"
                            />
                            <InputError :message="form.errors.ends_at" />
                        </div>
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox v-model="form.is_active" />
                        Running
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox v-model="form.stop_after_referral" />
                        Stop showing it to someone once they refer an owner
                    </label>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="dialogOpen = false"
                            >Cancel</Button
                        >
                        <Button
                            type="button"
                            variant="outline"
                            @click="previewDraft"
                        >
                            <Eye class="size-4" />
                            Preview
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            <Spinner v-if="form.processing" />
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Keyed so previewing the same promotion twice opens it again. -->
        <PromotionPopup
            v-if="previewing"
            :key="`${previewing.id}-${previewing.title}`"
            :preview="previewing"
            @closed="previewing = null"
        />
    </div>
</template>
