<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { UserCheck } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { stop } from '@/routes/impersonate';

/**
 * Says, on every page, that this is not the admin's own account.
 *
 * Acting as someone else is easy to forget you are doing, and everything done
 * here is done in their name, so the banner is loud and always offers the way
 * out.
 */
const page = usePage();

const impersonating = computed(() => page.props.auth?.impersonating === true);
const name = computed(() => page.props.auth?.user?.name ?? 'this account');
</script>

<template>
    <div
        v-if="impersonating"
        class="bg-scrim text-scrim-foreground flex flex-wrap items-center justify-center gap-x-4 gap-y-2 px-4 py-2 text-sm"
        role="status"
    >
        <p class="flex items-center gap-2">
            <UserCheck class="size-4 shrink-0" aria-hidden="true" />
            You are signed in as <strong>{{ name }}</strong
            >. Anything you do here is done as them.
        </p>
        <Form v-bind="stop.form()" #default="{ processing }">
            <Button
                type="submit"
                size="sm"
                variant="outline"
                class="border-scrim-foreground/30 bg-scrim-foreground/10 text-scrim-foreground hover:bg-scrim-foreground/20 hover:text-scrim-foreground h-7"
                :disabled="processing"
            >
                <Spinner v-if="processing" class="size-3.5" />
                Back to my account
            </Button>
        </Form>
    </div>
</template>
