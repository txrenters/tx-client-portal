import { useHttp } from '@inertiajs/vue3';
import type { ComputedRef, Ref } from 'vue';
import { computed, ref } from 'vue';
import { latest as latestNotifications } from '@/routes/notifications';
import type { ChatThread, Notice, WorkOrder } from '@/types';

type Section<T> = { count: number; items: T[] } | null;

export type Attention = {
    count: number;
    portal: 'owner' | 'resident' | null;
    /** Private channel that announces new replies for the account in view. */
    channel: string | null;
    approvals: Section<WorkOrder>;
    messages: Section<ChatThread>;
    notices?: Section<Notice>;
};

/**
 * What needs the client's attention, read once and shared: the header bell
 * shows all of it and the sidebar's Messages link its unread replies, so the
 * two always agree and cost one request between them. The bell decides when
 * to refresh; anything else that changes the count (opening a conversation)
 * can ask for a refresh too.
 */
const latest = ref<Attention | null>(null);
let http: ReturnType<typeof useHttp> | null = null;
let fetchedAt = 0;
/** Bumped by reset(), so an answer to a request made before it is dropped. */
let generation = 0;

export type UseAttentionReturn = {
    latest: Ref<Attention | null>;
    unreadMessages: ComputedRef<number>;
    /** Refetch, at most once a minute unless forced. */
    refresh: (force?: boolean) => void;
    /**
     * Forget everything, for when the bell leaves the page (signing out, or
     * the staff side), so the next person in this tab never sees the last
     * one's replies while their own load.
     */
    reset: () => void;
};

export function useAttention(): UseAttentionReturn {
    function refresh(force = false): void {
        http ??= useHttp({});

        if (http.processing || (!force && Date.now() - fetchedAt < 60_000)) {
            return;
        }

        const asked = generation;
        fetchedAt = Date.now();
        void http.get(latestNotifications.url(), {
            onSuccess: (response) => {
                if (asked === generation) {
                    latest.value = response as Attention;
                }
            },
        });
    }

    function reset(): void {
        generation++;
        latest.value = null;
        fetchedAt = 0;
        // A new client, so a request still out for the last one can't hold up theirs.
        http = null;
    }

    return {
        latest,
        unreadMessages: computed(() => latest.value?.messages?.count ?? 0),
        refresh,
        reset,
    };
}
