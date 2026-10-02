<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PublicPageLayout from '@/layouts/PublicPageLayout.vue';
import { privacy } from '@/routes';
import { request } from '@/routes/password';
</script>

<template>
    <PublicPageLayout
        title="Support"
        description="Get help with the TexasRenters.com client portal and the TexasRenters App."
    >
        <p>
            Need help with the client portal or the TexasRenters App? Our team
            is happy to help.
        </p>

        <h2>Contact us</h2>
        <ul>
            <li>
                Email
                <a href="mailto:info@texasrenters.com">info@texasrenters.com</a>
            </li>
            <li>Call <a href="tel:+12814073818">(281) 407-3818</a></li>
            <li>
                Already signed in? Use <strong>Help</strong> in the portal or
                <strong>Message us</strong> in the app to reach your property
                team directly.
            </li>
        </ul>
        <p>
            For an emergency at your home, such as a flood, gas leak or fire,
            call 911 first, then call us.
        </p>

        <h2>Common questions</h2>

        <p><strong>How do I sign up?</strong></p>
        <p>
            Create an account with the email address on your lease or ownership
            agreement, or open the invitation we emailed you. Confirm your
            email, and your property, work orders and documents will be waiting.
        </p>

        <p><strong>My confirmation email never arrived.</strong></p>
        <p>
            Check your spam folder, then email us from the same address and we
            will sort it out.
        </p>

        <p><strong>I forgot my password.</strong></p>
        <p>
            Use
            <Link :href="request()">Forgot password</Link>
            on the sign-in page, or tap "Forgot password?" in the app.
        </p>

        <p><strong>I signed in but don't see my property.</strong></p>
        <p>
            Your account matches our records by email address. If you signed up
            with a different email, contact us and we will link it.
        </p>

        <p><strong>How do I delete my account?</strong></p>
        <p>
            Go to your profile settings and choose Delete account, or email
            <a href="mailto:info@texasrenters.com">info@texasrenters.com</a>.
            See our <Link :href="privacy()">privacy policy</Link> for what is
            kept after deletion.
        </p>
    </PublicPageLayout>
</template>
