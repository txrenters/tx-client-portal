<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Check, Phone, Plus, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import FilePreviewInput from '@/components/FilePreviewInput.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Combobox,
    ComboboxAnchor,
    ComboboxEmpty,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxItemIndicator,
    ComboboxList,
} from '@/components/ui/combobox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
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
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { appDateString } from '@/lib/timezone';
import type { WorkOrderRequestOptions } from '@/types';

type PropertyOption = { id: number; label: string };

/**
 * The "New request" form: creates a work order in Propertyware, then opens it.
 */
const props = defineProps<{
    action: { url: string; method: 'post' };
    isOwner?: boolean;
    /** Undefined while loading, null when it failed. */
    options?: WorkOrderRequestOptions | null;
}>();

const open = ref(false);
const category = ref('');
const urgency = ref<'normal' | 'urgent'>('normal');
const entry = ref<'no' | 'anytime' | 'scheduled'>('no');
const property = ref<PropertyOption | undefined>();

const today = appDateString();

const onlyProperty = computed(() =>
    props.options?.properties.length === 1
        ? props.options.properties[0]
        : undefined,
);
const propertyId = computed(
    () => property.value?.id ?? onlyProperty.value?.id ?? '',
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button>
                <Plus class="size-4" />
                New request
            </Button>
        </DialogTrigger>

        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>New maintenance request</DialogTitle>
                <DialogDescription>
                    Tell us what needs fixing. TexasRenters.com will review it
                    and follow up.
                </DialogDescription>
            </DialogHeader>

            <Skeleton v-if="options === undefined" class="h-64 w-full" />

            <p v-else-if="options === null" class="text-destructive text-sm">
                We couldn't load the request form. Please try again shortly.
            </p>

            <Form
                v-else
                :action="props.action.url"
                method="post"
                class="flex flex-col gap-4"
                v-slot="{ errors, processing, progress }"
            >
                <div v-if="isOwner" class="grid gap-2">
                    <Label for="request-property">Property</Label>
                    <p v-if="onlyProperty" class="text-sm">
                        {{ onlyProperty.label }}
                    </p>
                    <Combobox
                        v-else
                        v-model="property"
                        by="id"
                        :reset-search-term-on-select="false"
                    >
                        <ComboboxAnchor class="w-full">
                            <ComboboxInput
                                id="request-property"
                                :display-value="
                                    (value) =>
                                        (value as PropertyOption | undefined)
                                            ?.label ?? ''
                                "
                                placeholder="Search your properties"
                            />
                        </ComboboxAnchor>
                        <ComboboxList class="max-h-72">
                            <ComboboxEmpty>No matching property.</ComboboxEmpty>
                            <ComboboxGroup>
                                <ComboboxItem
                                    v-for="item in options.properties"
                                    :key="item.id"
                                    :value="item"
                                >
                                    {{ item.label }}
                                    <ComboboxItemIndicator>
                                        <Check class="size-4" />
                                    </ComboboxItemIndicator>
                                </ComboboxItem>
                            </ComboboxGroup>
                        </ComboboxList>
                    </Combobox>
                    <input
                        type="hidden"
                        name="property_id"
                        :value="propertyId"
                    />
                    <InputError :message="errors.property_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="request-category">Category</Label>
                    <Select v-model="category" name="category" required>
                        <SelectTrigger id="request-category" class="w-full">
                            <SelectValue placeholder="Choose a category" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="item in options.categories"
                                :key="item.value"
                                :value="item.value"
                            >
                                {{ item.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="errors.category" />
                </div>

                <div class="grid gap-2">
                    <Label for="request-description">What's wrong?</Label>
                    <Textarea
                        id="request-description"
                        name="description"
                        required
                        rows="4"
                        maxlength="2000"
                        placeholder="Describe the problem and when it started."
                    />
                    <InputError :message="errors.description" />
                </div>

                <div class="grid gap-2">
                    <Label for="request-location">
                        Where in the home
                        <span class="text-muted-foreground font-normal"
                            >(optional)</span
                        >
                    </Label>
                    <Input
                        id="request-location"
                        name="location"
                        maxlength="255"
                        placeholder="e.g. Kitchen, upstairs bathroom"
                    />
                    <InputError :message="errors.location" />
                </div>

                <div class="grid gap-2">
                    <Label for="request-urgency">Urgency</Label>
                    <Select v-model="urgency" name="urgency">
                        <SelectTrigger id="request-urgency" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="normal">Normal</SelectItem>
                            <SelectItem value="urgent">Urgent</SelectItem>
                        </SelectContent>
                    </Select>
                    <p
                        v-if="urgency === 'urgent'"
                        class="text-muted-foreground flex items-start gap-2 text-xs"
                    >
                        <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
                        <span>
                            For emergencies such as fire, flooding or a gas
                            smell, call
                            <a
                                v-if="options.emergencyPhone"
                                :href="`tel:${options.emergencyPhone}`"
                                class="text-primary inline-flex items-center gap-1 font-medium hover:underline"
                                ><Phone class="size-3" />{{
                                    options.emergencyPhone
                                }}</a
                            >
                            <template v-else>us</template>
                            right away instead of sending a request.
                        </span>
                    </p>
                </div>

                <template v-if="!isOwner">
                    <div class="grid gap-2">
                        <Label for="request-entry">Permission to enter</Label>
                        <Select v-model="entry" name="entry">
                            <SelectTrigger id="request-entry" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="no"
                                    >No, contact me first</SelectItem
                                >
                                <SelectItem value="anytime"
                                    >Yes, anytime</SelectItem
                                >
                                <SelectItem value="scheduled"
                                    >Only at a set time</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>

                    <div
                        v-if="entry === 'scheduled'"
                        class="grid grid-cols-2 gap-2"
                    >
                        <div class="grid gap-2">
                            <Label for="request-entry-date">Day</Label>
                            <Input
                                id="request-entry-date"
                                type="date"
                                name="entry_date"
                                :min="today"
                                required
                            />
                            <InputError :message="errors.entry_date" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="request-entry-time">Time</Label>
                            <Input
                                id="request-entry-time"
                                type="time"
                                name="entry_time"
                                step="600"
                                required
                            />
                            <InputError :message="errors.entry_time" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="request-access-notes">
                            Access notes
                            <span class="text-muted-foreground font-normal"
                                >(optional)</span
                            >
                        </Label>
                        <Input
                            id="request-access-notes"
                            name="access_notes"
                            maxlength="500"
                            placeholder="e.g. Dog in the yard, gate code with the office"
                        />
                        <InputError :message="errors.access_notes" />
                    </div>
                </template>

                <div class="grid gap-2">
                    <Label for="request-photos">
                        Photos
                        <span class="text-muted-foreground font-normal"
                            >(optional, up to {{ options.maxPhotos }})</span
                        >
                    </Label>
                    <FilePreviewInput
                        id="request-photos"
                        name="photos[]"
                        multiple
                        :max="options.maxPhotos"
                        accept=".jpg,.jpeg,.png,.heic,.heif,.pdf"
                    />
                    <InputError
                        :message="
                            errors.photos ??
                            Object.entries(errors).find(([key]) =>
                                key.startsWith('photos.'),
                            )?.[1]
                        "
                    />
                </div>

                <progress
                    v-if="progress"
                    :value="progress.percentage"
                    max="100"
                    class="accent-primary h-1.5 w-full"
                >
                    {{ progress.percentage }}%
                </progress>

                <DialogFooter>
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        Send request
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
