<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { Check, Upload } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
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
import type { UploadTarget, UploadTargets } from '@/types';

type AttachTo = 'property' | 'portfolio' | 'work_order' | 'lease' | 'account';

const props = defineProps<{
    action: { url: string; method: 'post' };
    maxMegabytes: number;
    isOwner?: boolean;
    /** What the file can be attached to; undefined while loading, null when it failed. */
    targets?: UploadTargets | null;
    /** Upload straight to this property, without asking. */
    fixedPropertyId?: number;
}>();

const choices = computed<{ value: AttachTo; label: string }[]>(() =>
    props.isOwner
        ? [
              { value: 'property', label: 'A property' },
              { value: 'portfolio', label: 'A portfolio' },
              { value: 'work_order', label: 'A work order' },
              { value: 'account', label: 'My account' },
          ]
        : [
              { value: 'lease', label: 'My lease' },
              { value: 'work_order', label: 'A work order' },
              { value: 'account', label: 'My account' },
          ],
);

const open = ref(false);
const attachTo = ref<AttachTo>(props.isOwner ? 'property' : 'lease');
const selected = ref<UploadTarget | undefined>();

const options = computed<UploadTarget[]>(() => {
    const targets = props.targets;

    if (!targets) {
        return [];
    }

    return {
        property: targets.properties,
        portfolio: targets.portfolios,
        work_order: targets.workOrders,
        lease: targets.leases,
        account: [],
    }[attachTo.value];
});

/** A single choice needs no picker; it is shown and sent as is. */
const onlyOption = computed(() =>
    options.value.length === 1 ? options.value[0] : undefined,
);

const targetId = computed(
    () => selected.value?.id ?? onlyOption.value?.id ?? '',
);

const searchPlaceholder = computed(
    () =>
        ({
            property: 'Search by address',
            portfolio: 'Search portfolios',
            work_order: 'Search by number or title',
            lease: 'Choose a lease',
            account: '',
        })[attachTo.value],
);

watch(attachTo, () => (selected.value = undefined));

function uploaded(): void {
    open.value = false;
    selected.value = undefined;
    router.reload({ only: ['documents'] });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button>
                <Upload class="size-4" />
                Upload
            </Button>
        </DialogTrigger>

        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Upload a document</DialogTitle>
                <DialogDescription>
                    TexasRenters.com will receive it, and it will appear in your
                    documents.
                </DialogDescription>
            </DialogHeader>

            <Form
                :action="props.action.url"
                method="post"
                :options="{ preserveScroll: true }"
                reset-on-success
                class="flex flex-col gap-4"
                v-slot="{ errors, processing, progress }"
                @success="uploaded"
            >
                <div class="grid gap-2">
                    <Label for="upload-file">File</Label>
                    <FilePreviewInput
                        id="upload-file"
                        name="file"
                        required
                        accept=".pdf,.jpg,.jpeg,.png,.heic,.heif,.doc,.docx"
                    />
                    <p class="text-muted-foreground text-xs">
                        PDF, photo (JPG, PNG, HEIC) or Word document, up to
                        {{ maxMegabytes }} MB.
                    </p>
                    <InputError :message="errors.file" />
                </div>

                <template v-if="fixedPropertyId">
                    <input type="hidden" name="attach_to" value="property" />
                    <input
                        type="hidden"
                        name="target_id"
                        :value="fixedPropertyId"
                    />
                </template>

                <template v-else>
                    <div class="grid gap-2">
                        <Label for="upload-attach-to">Attach to</Label>
                        <Select v-model="attachTo">
                            <SelectTrigger id="upload-attach-to" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="choice in choices"
                                    :key="choice.value"
                                    :value="choice.value"
                                >
                                    {{ choice.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <input
                            type="hidden"
                            name="attach_to"
                            :value="attachTo"
                        />
                        <input
                            type="hidden"
                            name="target_id"
                            :value="targetId"
                        />
                    </div>

                    <div v-if="attachTo !== 'account'" class="grid gap-2">
                        <Skeleton
                            v-if="targets === undefined"
                            class="h-9 w-full"
                        />
                        <p
                            v-else-if="targets === null"
                            class="text-destructive text-sm"
                        >
                            We couldn't load your records. Try again shortly.
                        </p>
                        <p
                            v-else-if="options.length === 0"
                            class="text-muted-foreground text-sm"
                        >
                            There's nothing of this kind to attach to.
                        </p>
                        <p v-else-if="onlyOption" class="text-sm">
                            {{ onlyOption.label }}
                        </p>
                        <Combobox
                            v-else
                            v-model="selected"
                            by="id"
                            :reset-search-term-on-select="false"
                        >
                            <ComboboxAnchor class="w-full">
                                <ComboboxInput
                                    :display-value="
                                        (value) =>
                                            (value as UploadTarget | undefined)
                                                ?.label ?? ''
                                    "
                                    :placeholder="searchPlaceholder"
                                    aria-label="Choose what to attach the file to"
                                />
                            </ComboboxAnchor>
                            <ComboboxList class="max-h-72">
                                <ComboboxEmpty>No matches.</ComboboxEmpty>
                                <ComboboxGroup>
                                    <ComboboxItem
                                        v-for="option in options"
                                        :key="option.id"
                                        :value="option"
                                    >
                                        {{ option.label }}
                                        <ComboboxItemIndicator>
                                            <Check class="size-4" />
                                        </ComboboxItemIndicator>
                                    </ComboboxItem>
                                </ComboboxGroup>
                            </ComboboxList>
                        </Combobox>
                    </div>
                    <InputError :message="errors.target_id" />
                </template>

                <div class="grid gap-2">
                    <Label for="upload-description">
                        Description
                        <span class="text-muted-foreground font-normal"
                            >(optional)</span
                        >
                    </Label>
                    <Input
                        id="upload-description"
                        name="description"
                        maxlength="255"
                        placeholder="e.g. Signed renewal, receipt for repair"
                    />
                    <InputError :message="errors.description" />
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
                        Upload
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
