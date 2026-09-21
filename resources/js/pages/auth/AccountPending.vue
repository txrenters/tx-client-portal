<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { logout } from '@/routes';

defineOptions({
    layout: {
        title: 'Your account is being set up',
        description:
            'Your login works, but it is not linked to a property yet.',
    },
});
</script>

<template>
    <Head title="Account pending" />

    <div class="flex flex-col gap-6 text-center">
        <p class="text-muted-foreground text-sm">
            TexasRenters.com needs to finish connecting your account. Please
            contact us if you were expecting access right away.
        </p>

        <Button variant="outline" as-child>
            <Link :href="logout()" as="button" data-test="logout-button">
                Log out
            </Link>
        </Button>
    </div>
</template>
