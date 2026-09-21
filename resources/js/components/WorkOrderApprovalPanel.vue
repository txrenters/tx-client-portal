<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { CheckCircle2, CircleAlert, XCircle } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useFormattedDate } from '@/composables/useFormattedDate';
import type { WorkOrderApprovalState } from '@/types';

/**
 * Lets an owner approve or decline a work order that waits on them, and
 * shows their decision afterwards.
 */
defineProps<{
    approval: WorkOrderApprovalState;
    action: { url: string; method: 'post' };
}>();

const { formatDate } = useFormattedDate();
/** Which decision the owner is confirming, with an optional comment. */
const confirming = ref<'approved' | 'declined' | null>(null);
</script>

<template>
    <div class="flex flex-col gap-3">
        <Alert
            v-if="approval.decision === 'approved'"
            class="border-success/30 bg-success/5"
        >
            <CheckCircle2 class="text-success" />
            <AlertTitle>You approved this work order</AlertTitle>
            <AlertDescription>
                {{ approval.decidedBy }} approved it on
                {{ formatDate(approval.decidedAt)
                }}<template v-if="approval.note">
                    with the comment "{{ approval.note }}"</template
                >.
                <template v-if="approval.pendingSync">
                    Your approval is saved and will show in Propertyware
                    shortly.
                </template>
            </AlertDescription>
        </Alert>

        <Alert v-else-if="approval.decision === 'declined'">
            <XCircle />
            <AlertTitle>You declined this work order</AlertTitle>
            <AlertDescription>
                {{ approval.decidedBy }} declined it on
                {{ formatDate(approval.decidedAt)
                }}<template v-if="approval.note"
                    >: "{{ approval.note }}"</template
                >. Changed your mind? You can still approve it below.
            </AlertDescription>
        </Alert>

        <Alert
            v-else-if="approval.awaiting"
            class="border-primary/30 bg-primary/5"
        >
            <CircleAlert class="text-primary" />
            <AlertTitle>This work order needs your approval</AlertTitle>
            <AlertDescription>
                Please approve it so work can go ahead, or decline and tell us
                why.
            </AlertDescription>
        </Alert>

        <Form
            v-if="approval.canDecide"
            :action="action.url"
            method="post"
            :options="{ preserveScroll: true }"
            class="flex flex-col gap-3"
            v-slot="{ errors, processing }"
            @success="confirming = null"
        >
            <input type="hidden" name="decision" :value="confirming ?? ''" />

            <div v-if="confirming" class="grid gap-2">
                <Label for="approval-note">
                    {{
                        confirming === 'approved'
                            ? 'Comment for your team'
                            : 'Why are you declining?'
                    }}
                    <span class="text-muted-foreground font-normal"
                        >(optional)</span
                    >
                </Label>
                <Textarea
                    id="approval-note"
                    name="note"
                    rows="3"
                    maxlength="500"
                    :placeholder="
                        confirming === 'approved'
                            ? 'e.g. Go ahead, please use the same plumber as last time'
                            : 'e.g. Please get a second quote first'
                    "
                />
                <p
                    v-if="confirming === 'approved'"
                    class="text-muted-foreground text-xs"
                >
                    Your comment is saved with your approval in Propertyware.
                </p>
                <InputError :message="errors.note" />
            </div>

            <InputError :message="errors.decision" />

            <div class="flex flex-wrap gap-2">
                <template v-if="!confirming">
                    <Button
                        type="button"
                        :disabled="processing"
                        @click="confirming = 'approved'"
                    >
                        <CheckCircle2 class="size-4" />
                        Approve
                    </Button>
                    <Button
                        v-if="approval.decision !== 'declined'"
                        type="button"
                        variant="outline"
                        :disabled="processing"
                        @click="confirming = 'declined'"
                    >
                        Decline
                    </Button>
                </template>
                <template v-else>
                    <Button
                        type="submit"
                        :variant="
                            confirming === 'approved'
                                ? 'default'
                                : 'destructive'
                        "
                        :disabled="processing"
                    >
                        <Spinner v-if="processing" />
                        {{
                            confirming === 'approved'
                                ? 'Confirm approval'
                                : 'Decline work order'
                        }}
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        :disabled="processing"
                        @click="confirming = null"
                    >
                        Cancel
                    </Button>
                </template>
            </div>
        </Form>
    </div>
</template>
