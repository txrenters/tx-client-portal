<script setup lang="ts">
import { FileText, X } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * A file input that previews what was picked, with a way to remove each file.
 *
 * The chosen files stay on the real input, so the surrounding form submits
 * them as usual; removing one rebuilds the input's file list. It is a plain
 * input styled like the shadcn Input: that component two-way binds a text
 * value, which Vue would write back over a file input's selection.
 */
const props = withDefaults(
    defineProps<{
        id: string;
        name: string;
        accept?: string;
        multiple?: boolean;
        required?: boolean;
        max?: number;
        /** Keep the input out of sight; open the picker with the exposed pick(). */
        hideInput?: boolean;
    }>(),
    {
        accept: undefined,
        multiple: false,
        required: false,
        max: undefined,
        hideInput: false,
    },
);

type Picked = { file: File; url: string | null };

const input = ref<HTMLInputElement | null>(null);
const picked = ref<Picked[]>([]);

const overLimit = computed(
    () => props.max !== undefined && picked.value.length > props.max,
);

/** Browsers can draw these; HEIC only renders in Safari, so it gets an icon. */
function canPreview(file: File): boolean {
    return /^image\/(jpeg|png|gif|webp)$/.test(file.type);
}

function element(): HTMLInputElement | null {
    return input.value;
}

function release(): void {
    picked.value.forEach((item) => item.url && URL.revokeObjectURL(item.url));
}

function sync(): void {
    release();
    picked.value = Array.from(element()?.files ?? []).map((file) => ({
        file,
        url: canPreview(file) ? URL.createObjectURL(file) : null,
    }));
}

function remove(index: number): void {
    const el = element();

    if (!el) {
        return;
    }

    const remaining = new DataTransfer();
    picked.value
        .filter((_, position) => position !== index)
        .forEach((item) => remaining.items.add(item.file));
    el.files = remaining.files;
    sync();
}

function size(bytes: number): string {
    return bytes >= 1_048_576
        ? `${(bytes / 1_048_576).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/** Clear the selection, e.g. once the form was sent. */
function clear(): void {
    const el = element();

    if (el) {
        el.value = '';
    }

    sync();
}

defineExpose({
    pick: () => element()?.click(),
    clear,
    /** How many files are picked. */
    count: computed(() => picked.value.length),
});

onBeforeUnmount(release);
</script>

<template>
    <div class="grid gap-2">
        <input
            :id="id"
            ref="input"
            type="file"
            data-slot="input"
            :name="name"
            :accept="accept"
            :multiple="multiple"
            :required="required && picked.length === 0"
            :class="
                cn(
                    'dark:bg-input/30 border-input focus-visible:border-ring focus-visible:ring-ring/50 file:text-foreground h-8 w-full min-w-0 rounded-lg border bg-transparent px-2.5 py-1 text-base transition-colors outline-none file:inline-flex file:h-6 file:border-0 file:bg-transparent file:text-sm file:font-medium focus-visible:ring-3 md:text-sm',
                    hideInput && 'sr-only',
                )
            "
            @change="sync"
        />

        <ul
            v-if="picked.length > 0"
            class="grid grid-cols-[repeat(auto-fill,minmax(88px,1fr))] gap-2"
        >
            <li
                v-for="(item, index) in picked"
                :key="`${item.file.name}-${item.file.size}-${item.file.lastModified}`"
                class="bg-muted relative overflow-hidden rounded-lg border"
            >
                <img
                    v-if="item.url"
                    :src="item.url"
                    :alt="item.file.name"
                    class="aspect-square w-full object-cover"
                />
                <div
                    v-else
                    class="text-muted-foreground flex aspect-square w-full flex-col items-center justify-center gap-1 p-2 text-center"
                >
                    <FileText class="size-6" />
                    <span class="line-clamp-2 text-[10px] break-all">{{
                        item.file.name
                    }}</span>
                </div>
                <span
                    class="bg-background/80 absolute bottom-1 left-1 rounded px-1 text-[10px] tabular-nums"
                    >{{ size(item.file.size) }}</span
                >
                <Button
                    type="button"
                    variant="secondary"
                    size="icon"
                    class="absolute top-1 right-1 size-6 rounded-full"
                    :aria-label="`Remove ${item.file.name}`"
                    @click="remove(index)"
                >
                    <X class="size-3.5" />
                </Button>
            </li>
        </ul>

        <p
            v-if="
                max !== undefined &&
                multiple &&
                (!hideInput || picked.length > 0)
            "
            class="text-xs"
            :class="overLimit ? 'text-destructive' : 'text-muted-foreground'"
        >
            {{ picked.length }} of {{ max }} selected<template v-if="overLimit"
                >. Remove {{ picked.length - max }} to continue.</template
            >
        </p>
    </div>
</template>
