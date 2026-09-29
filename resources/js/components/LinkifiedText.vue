<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    text: string;
}>();

/** Matches `http(s)://` URLs and bare `www.` addresses. */
const linkPattern = /(?:https?:\/\/|www\.)[^\s<>"']+/gi;

type Segment = {
    text: string;
    href?: string;
};

function countOf(value: string, character: string): number {
    return value.split(character).length - 1;
}

/**
 * Drops punctuation that ends the sentence rather than the link, keeping a
 * closing bracket only when the link itself opened one.
 */
function trimSentencePunctuation(url: string): string {
    let end = url.length;

    while (end > 0) {
        const character = url[end - 1];

        if ('.,;:!?\'"'.includes(character)) {
            end--;
            continue;
        }

        if (character === ')' || character === ']') {
            const opener = character === ')' ? '(' : '[';
            const candidate = url.slice(0, end);

            if (countOf(candidate, opener) >= countOf(candidate, character)) {
                break;
            }

            end--;
            continue;
        }

        break;
    }

    return url.slice(0, end);
}

const segments = computed<Segment[]>(() => {
    const parts: Segment[] = [];
    let cursor = 0;

    for (const match of props.text.matchAll(linkPattern)) {
        const start = match.index ?? 0;
        const url = trimSentencePunctuation(match[0]);

        if (url === '') {
            continue;
        }

        if (start > cursor) {
            parts.push({ text: props.text.slice(cursor, start) });
        }

        parts.push({
            text: url,
            href: url.startsWith('www.') ? `https://${url}` : url,
        });

        cursor = start + url.length;
    }

    if (cursor < props.text.length) {
        parts.push({ text: props.text.slice(cursor) });
    }

    return parts;
});
</script>

<template>
    <span
        ><template v-for="(segment, index) in segments" :key="index"
            ><a
                v-if="segment.href"
                :href="segment.href"
                target="_blank"
                rel="noopener noreferrer"
                class="underline underline-offset-2 hover:opacity-80"
                >{{ segment.text }}</a
            ><template v-else>{{ segment.text }}</template></template
        ></span
    >
</template>
