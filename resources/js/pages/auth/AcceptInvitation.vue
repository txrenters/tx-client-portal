<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { describePasswordRules } from '@/lib/passwordRules';
import { login } from '@/routes';
import { store } from '@/routes/invitations';

type Invitation = {
    token: string;
    name: string;
    email: string;
    portal: string;
};

defineOptions({
    layout: {
        title: 'Accept your invitation',
        description: 'Set up your TexasRenters.com account',
    },
});

defineProps<{
    invitation: Invitation | null;
    requiresPassword?: boolean;
    signedInAsOtherUser?: boolean;
    passwordRules?: string;
}>();
</script>

<template>
    <Head title="Accept invitation" />

    <div v-if="!invitation" class="flex flex-col gap-6 text-center">
        <p class="text-muted-foreground text-sm">
            This invitation link is invalid or has expired. Please contact
            TexasRenters.com for a new one.
        </p>

        <Button variant="outline" as-child>
            <Link :href="login()">Go to sign in</Link>
        </Button>
    </div>

    <div
        v-else-if="signedInAsOtherUser"
        class="flex flex-col gap-6 text-center"
    >
        <p class="text-muted-foreground text-sm">
            This invitation was sent to {{ invitation.email }}, but you are
            signed in with a different account. Sign out, then open the link
            again.
        </p>
    </div>

    <Form
        v-else
        v-bind="store.form(invitation.token)"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    :default-value="invitation.email"
                    disabled
                    readonly
                />
                <p class="text-muted-foreground text-xs">
                    You're joining the {{ invitation.portal.toLowerCase() }}.
                </p>
            </div>

            <template v-if="requiresPassword">
                <div class="grid gap-2">
                    <Label for="name">Full name</Label>
                    <Input
                        id="name"
                        name="name"
                        :default-value="invitation.name"
                        required
                        autofocus
                        autocomplete="name"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Password</Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        required
                        autocomplete="new-password"
                        placeholder="Create a password"
                        :passwordrules="passwordRules"
                    />
                    <p
                        v-if="passwordRules"
                        class="text-muted-foreground text-xs"
                    >
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
                        autocomplete="new-password"
                        placeholder="Confirm your password"
                        :passwordrules="passwordRules"
                    />
                    <InputError :message="errors.password_confirmation" />
                </div>
            </template>

            <InputError :message="errors.invitation" />

            <Button
                type="submit"
                class="w-full"
                :disabled="processing"
                data-test="accept-invitation-button"
            >
                <Spinner v-if="processing" />
                {{ requiresPassword ? 'Create account' : 'Accept invitation' }}
            </Button>
        </div>
    </Form>
</template>
