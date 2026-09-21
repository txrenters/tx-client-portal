<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, Building2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { logout } from '@/routes';
import { update } from '@/routes/accounts';

defineOptions({
    layout: {
        title: 'Choose your account',
        description:
            'Your login is linked to more than one account. Choose which one to view.',
    },
});

defineProps<{
    accounts: {
        id: number;
        label: string;
        portal: string;
        reference: string;
    }[];
}>();

const form = useForm({});

function selectAccount(id: number): void {
    form.put(update.url(id));
}
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4">
        <Head title="Choose your account" />

        <Card v-for="account in accounts" :key="account.id">
            <CardContent class="flex min-w-0 flex-col gap-3">
                <div class="flex items-start gap-3">
                    <Building2 class="text-primary mt-1 size-5 shrink-0" />
                    <div class="min-w-0">
                        <h2 class="font-semibold wrap-anywhere">
                            {{ account.label }}
                        </h2>
                        <p class="text-muted-foreground text-sm">
                            {{ account.portal }}
                        </p>
                        <p class="text-muted-foreground text-xs wrap-anywhere">
                            Account reference: {{ account.reference }}
                        </p>
                    </div>
                </div>
                <Button
                    :disabled="form.processing"
                    @click="selectAccount(account.id)"
                >
                    Continue with this account
                    <ArrowRight class="size-4" />
                </Button>
            </CardContent>
        </Card>

        <p class="text-muted-foreground text-center text-sm">
            You can switch accounts later from the portal menu.
        </p>
        <Button variant="ghost" as-child>
            <Link :href="logout()" as="button">Log out</Link>
        </Button>
    </div>
</template>
