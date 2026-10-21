<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import BetaNotice from '@/components/BetaNotice.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { describePasswordRules } from '@/lib/passwordRules';
import { login } from '@/routes';
import { store } from '@/routes/register';

defineOptions({
    layout: {
        showAppLinks: true,
        title: 'Create your account',
        description:
            "Use the email address TexasRenters.com has on file. We'll send a link to confirm it's yours.",
    },
});

defineProps<{
    passwordRules?: string;
    accessPaused?: boolean;
}>();
</script>

<template>
    <Head title="Sign up" />

    <BetaNotice v-if="accessPaused" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="name">Full name</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="name"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    :tabindex="2"
                    autocomplete="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="3"
                    autocomplete="new-password"
                    placeholder="Create a password"
                    :passwordrules="passwordRules"
                />
                <p v-if="passwordRules" class="text-muted-foreground text-xs">
                    {{ describePasswordRules(passwordRules) }}
                </p>
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    required
                    :tabindex="4"
                    autocomplete="new-password"
                    placeholder="Confirm your password"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-2 w-full"
                :tabindex="5"
                :disabled="processing"
                data-test="register-button"
            >
                <Spinner v-if="processing" />
                Create account
            </Button>
        </div>

        <div class="text-muted-foreground text-center text-sm">
            Already have an account?
            <TextLink :href="login()" :tabindex="6">Log in</TextLink>
        </div>
    </Form>
</template>
