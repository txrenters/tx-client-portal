<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';

defineOptions({
    layout: {
        title: 'Email confirmed',
        description: 'Your TexasRenters.com account is ready to use.',
    },
});

defineProps<{
    email: string;
}>();
</script>

<template>
    <div class="flex flex-col items-center gap-6 text-center">
        <Head title="Email confirmed" />

        <CheckCircle2 class="text-primary size-12" aria-hidden="true" />

        <p class="text-muted-foreground text-sm">
            Thanks! {{ email }} is confirmed. Go back to the TexasRenters.com
            app to continue, or log in here on the web.
        </p>

        <Button variant="outline" class="w-full" as-child>
            <Link :href="login()">Log in on the web</Link>
        </Button>
    </div>
</template>
