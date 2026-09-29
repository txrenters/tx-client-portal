<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

/**
 * A staff action that asks first. The trigger is the slot; the request only
 * goes once the dialog is confirmed, and the dialog stays open while it runs.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        description: string;
        confirmLabel: string;
        action: { url: string; method: 'get' | 'post' | 'put' | 'patch' | 'delete' };
        destructive?: boolean;
        /** Ask for an optional reason, sent as "reason". */
        withReason?: boolean;
    }>(),
    { destructive: false, withReason: false },
);

const open = ref(false);
const processing = ref(false);
const reason = ref('');

function confirm(): void {
    router.visit(props.action.url, {
        method: props.action.method,
        data: props.withReason ? { reason: reason.value || null } : {},
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => {
            processing.value = false;
            open.value = false;
            reason.value = '';
        },
    });
}
</script>

<template>
    <AlertDialog v-model:open="open">
        <AlertDialogTrigger as-child>
            <slot />
        </AlertDialogTrigger>
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>{{ title }}</AlertDialogTitle>
                <AlertDialogDescription>{{ description }}</AlertDialogDescription>
            </AlertDialogHeader>

            <div v-if="withReason" class="grid gap-2">
                <Label for="confirm-reason">
                    Reason
                    <span class="text-muted-foreground font-normal"
                        >(optional, for the audit trail)</span
                    >
                </Label>
                <Textarea
                    id="confirm-reason"
                    v-model="reason"
                    rows="2"
                    maxlength="500"
                />
            </div>

            <AlertDialogFooter>
                <AlertDialogCancel :disabled="processing">Cancel</AlertDialogCancel>
                <Button
                    :variant="destructive ? 'destructive' : 'default'"
                    :disabled="processing"
                    @click="confirm"
                >
                    <Spinner v-if="processing" />
                    {{ confirmLabel }}
                </Button>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
