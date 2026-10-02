<script setup lang="ts">
import PublicPageLayout from '@/layouts/PublicPageLayout.vue';
</script>

<template>
    <PublicPageLayout
        title="Privacy policy"
        description="How the TexasRenters.com client portal and the TexasRenters App collect, use and protect your information."
        updated="October 2, 2026"
    >
        <p>
            This policy covers the TexasRenters.com client portal at
            app.texasrenters.com and the TexasRenters App for iPhone and
            Android. Both are run by Texas Renters, the property management
            company behind TexasRenters.com. For our main website, see the
            <a href="https://www.texasrenters.com/privacy-policy" rel="noopener"
                >TexasRenters.com privacy policy</a
            >.
        </p>

        <h2>What we collect</h2>
        <ul>
            <li>
                <strong>Your account:</strong> your name, email address, phone
                number and password. Passwords are stored only as a secure hash.
            </li>
            <li>
                <strong>Your property records:</strong> the property, lease,
                charges, payments, owner statements and documents tied to your
                email address in our property management records.
            </li>
            <li>
                <strong>What you send us:</strong> maintenance requests, the
                photos and files you attach, messages, meeting bookings, help
                requests and referrals.
            </li>
            <li>
                <strong>Your device:</strong> if you allow notifications, a push
                notification token and your device's name, so we can send alerts
                to the right phone.
            </li>
            <li>
                <strong>Security logs:</strong> sign-in times, IP addresses and
                browser details, used to protect your account.
            </li>
        </ul>

        <h2>Camera, photos and Face ID</h2>
        <p>
            The app uses your camera or photo library only when you choose to
            attach a picture to a maintenance request or message. Face ID, Touch
            ID and fingerprint unlock are handled entirely by your device. We
            never receive or store your biometric data. Passkeys work the same
            way: we keep only a public key, never your fingerprint or face.
        </p>

        <h2>How we use it</h2>
        <ul>
            <li>To show you your property, lease, payments and documents.</li>
            <li>To handle repairs, messages and meetings you request.</li>
            <li>
                To send notifications about your account, which you can turn off
                in the app or in your browser at any time.
            </li>
            <li>To keep accounts secure and prevent fraud.</li>
        </ul>
        <p>
            We do not sell your information, show ads, or use third-party
            advertising or analytics trackers in the portal or the app.
        </p>

        <h2>Who we share it with</h2>
        <ul>
            <li>
                <strong>Our team:</strong> the Texas Renters staff who manage
                your property.
            </li>
            <li>
                <strong>Contractors:</strong> the vendors who carry out a repair
                you request receive the details they need to do the job, such as
                the address, the issue and how to reach you.
            </li>
            <li>
                <strong>Property owners:</strong> owners see work orders and
                financial records for the properties they own.
            </li>
            <li>
                <strong>Service providers:</strong> companies that host our
                servers, deliver email, and deliver push notifications through
                Expo, Apple and Google. They may use the data only to provide
                that service.
            </li>
            <li>
                <strong>When the law requires it:</strong> to comply with a
                legal obligation or protect the rights and safety of others.
            </li>
        </ul>

        <h2>How long we keep it</h2>
        <p>
            We keep your account while it is open. Lease, payment and other
            financial records may be kept longer where the law or our agreements
            with property owners require it.
        </p>

        <h2>Your choices</h2>
        <ul>
            <li>
                Update your name, email and phone number in your profile
                settings.
            </li>
            <li>Turn notifications on or off in your notification settings.</li>
            <li>
                Delete your account at any time from your profile settings, or
                by emailing
                <a href="mailto:info@texasrenters.com">info@texasrenters.com</a
                >. Deleting your account removes your sign-in, devices and
                personal settings. Records we must keep by law, such as lease
                and payment history, are retained by Texas Renters.
            </li>
        </ul>

        <h2>Security</h2>
        <p>
            All traffic is encrypted. Every account must confirm its email
            address, and you only see records tied to that email. You can add
            two-step sign-in or passkeys, and lock the app with Face ID.
            Documents are only served to signed-in users, never from public
            links.
        </p>

        <h2>Children</h2>
        <p>
            The portal and the app are for adult residents and property owners.
            They are not intended for children under 18.
        </p>

        <h2>Changes</h2>
        <p>
            If we change this policy we will update the date at the top of this
            page, and tell you in the portal if the change is significant.
        </p>

        <h2>Contact us</h2>
        <p>
            Texas Renters<br />
            5225 Katy Fwy Ste 545, Houston, TX 77007<br />
            <a href="mailto:info@texasrenters.com">info@texasrenters.com</a>
            &middot;
            <a href="tel:+12814073818">(281) 407-3818</a>
        </p>
    </PublicPageLayout>
</template>
