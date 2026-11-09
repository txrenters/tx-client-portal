<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    FileText,
    Fingerprint,
    Lock,
    MessageCircle,
    ShieldCheck,
    Wrench,
} from '@lucide/vue';
import { computed } from 'vue';
import AppPromo from '@/components/marketing/AppPromo.vue';
import AppStoreBadges from '@/components/marketing/AppStoreBadges.vue';
import AppTabBar from '@/components/marketing/AppTabBar.vue';
import AppTour from '@/components/marketing/AppTour.vue';
import BrowserFrame from '@/components/marketing/BrowserFrame.vue';
import HouseBackdrop from '@/components/marketing/HouseBackdrop.vue';
import PhoneFrame from '@/components/marketing/PhoneFrame.vue';
import SiteFooter from '@/components/marketing/SiteFooter.vue';
import SiteHeader from '@/components/marketing/SiteHeader.vue';
import TutorialDialog from '@/components/marketing/TutorialDialog.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

defineProps<{
    isWebPortalOpen: boolean;
}>();

const page = usePage();
const isSignedIn = computed(() => page.props.auth.user !== null);

/** Made-up examples of the alerts the app really sends. */
const alerts = [
    {
        icon: MessageCircle,
        title: 'Jordan Ellis',
        body: 'You’re all set. Lone Star Air & Heat is booked for tomorrow.',
    },
    {
        icon: Wrench,
        title: 'Work order scheduled',
        body: 'AC not cooling in the main bedroom',
    },
    {
        icon: FileText,
        title: 'Approval needed',
        body: 'Replace water heater',
    },
];

const steps = [
    {
        title: 'Download the app',
        description:
            'It is free on the App Store for iPhone and on Google Play for Android.',
    },
    {
        title: 'Sign up with your email',
        description:
            'Use the email address on your lease or ownership agreement, or open the invitation we sent you.',
    },
    {
        title: 'Confirm it is you',
        description:
            'Click the link we email you. Your property, work orders, lease and documents are already there.',
    },
];

const securityPoints = [
    {
        icon: Lock,
        title: 'Verified email only',
        description:
            'Every account confirms its email first, and only sees the property records tied to that email in our system.',
    },
    {
        icon: ShieldCheck,
        title: 'You see only your own',
        description:
            'Every page checks who you are before it loads anything. One client can never reach another client’s information.',
    },
    {
        icon: Fingerprint,
        title: 'Two-step sign-in and passkeys',
        description:
            'Add a code from an authenticator app, or sign in with your fingerprint or face instead of a password.',
    },
    {
        icon: FileText,
        title: 'Files stay private',
        description:
            'Documents are served through your signed-in session, never from a public link that could be forwarded.',
    },
];
</script>

<template>
    <div
        class="bg-background text-foreground flex min-h-svh flex-col overflow-x-clip"
    >
        <!-- The description and share tags are rendered in app.blade.php. -->
        <Head title="The app for your rental" />

        <SiteHeader />

        <main class="flex-1">
            <!-- Hero: the app's own navy header with its curved bottom edge,
                 the real app running off it. -->
            <section
                id="top"
                class="bg-scrim text-scrim-foreground relative isolate scroll-mt-16 overflow-hidden rounded-b-[2.5rem] sm:rounded-b-[3.5rem]"
            >
                <HouseBackdrop
                    class="text-scrim-foreground pointer-events-none absolute inset-x-0 bottom-0 -z-10 h-40 opacity-[0.08] sm:h-52"
                />

                <div
                    class="mx-auto grid w-full max-w-6xl items-center gap-12 px-4 pt-12 sm:px-6 sm:pt-16 lg:grid-cols-[1.1fr_1fr] lg:gap-8 lg:pt-0"
                >
                    <div class="flex flex-col items-start gap-7 lg:py-20">
                        <h1
                            class="font-heading text-5xl leading-[1.02] font-semibold tracking-tight text-balance sm:text-7xl"
                        >
                            Your rental, handled.
                        </h1>
                        <p
                            class="text-scrim-foreground/80 max-w-lg text-lg text-pretty"
                        >
                            The TexasRenters App for residents and owners in
                            Houston. Report repairs, message our team, and find
                            your lease, payments and documents on your phone.
                        </p>

                        <Button
                            v-if="isSignedIn"
                            as-child
                            size="lg"
                            class="bg-scrim-foreground text-scrim hover:bg-scrim-foreground/90 h-12 rounded-full px-6 text-base"
                        >
                            <Link :href="dashboard()">
                                Go to your dashboard
                                <ArrowRight class="size-4" />
                            </Link>
                        </Button>
                        <AppStoreBadges v-else />
                    </div>

                    <!-- A resident's home in front, an owner's numbers behind:
                         one app serves both. -->
                    <div
                        class="relative mx-auto h-[26rem] w-full max-w-md sm:h-[32rem] lg:h-[40rem] lg:max-w-none"
                        aria-hidden="true"
                    >
                        <PhoneFrame
                            screen="07-financials"
                            alt=""
                            eager
                            class="motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-bottom-24 motion-safe:fill-mode-both absolute top-16 right-0 w-[52%] opacity-90 motion-safe:delay-150 motion-safe:duration-1000 lg:top-28 lg:w-[20rem]"
                        />
                        <PhoneFrame
                            screen="01-home"
                            alt=""
                            eager
                            class="motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-bottom-24 motion-safe:fill-mode-both absolute top-0 left-0 w-[60%] motion-safe:duration-1000 lg:top-12 lg:left-4 lg:w-[22rem]"
                        />
                    </div>
                </div>
            </section>

            <div id="the-app" class="scroll-mt-16">
                <AppPromo />

                <AppTour />

                <!-- What only a phone can do, and the portal for a bigger screen. -->
                <section
                    class="mx-auto grid w-full max-w-6xl gap-4 px-4 sm:px-6 lg:grid-cols-5"
                    aria-label="More from the app"
                >
                    <div
                        class="bg-scrim text-scrim-foreground flex flex-col gap-8 rounded-[2rem] p-6 sm:p-10 lg:col-span-3"
                    >
                        <div>
                            <h2
                                class="font-heading text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                            >
                                Know the moment something moves
                            </h2>
                            <p
                                class="text-scrim-foreground/75 mt-3 max-w-md text-pretty"
                            >
                                Your phone tells you when we reply, when a
                                repair gets scheduled, and when work needs your
                                OK.
                            </p>
                        </div>

                        <ul class="mt-auto flex flex-col gap-2">
                            <li
                                v-for="alert in alerts"
                                :key="alert.title"
                                class="bg-card text-card-foreground flex items-center gap-3 rounded-2xl p-3"
                            >
                                <span
                                    class="bg-accent text-accent-foreground flex size-10 shrink-0 items-center justify-center rounded-xl"
                                >
                                    <component
                                        :is="alert.icon"
                                        class="size-5"
                                    />
                                </span>
                                <span class="min-w-0 text-sm">
                                    <span class="block font-semibold">
                                        {{ alert.title }}
                                    </span>
                                    <span class="text-muted-foreground block">
                                        {{ alert.body }}
                                    </span>
                                </span>
                            </li>
                        </ul>
                    </div>

                    <div
                        class="bg-scrim-accent text-scrim-foreground relative flex min-h-80 flex-col overflow-hidden rounded-[2rem] p-6 sm:p-10 lg:col-span-2"
                    >
                        <h2
                            class="font-heading text-3xl font-semibold tracking-tight text-balance"
                        >
                            Locked with Face ID
                        </h2>
                        <p class="text-scrim-foreground/80 mt-3 text-pretty">
                            Your account stays private on your phone.
                        </p>
                        <PhoneFrame
                            screen="09-lock"
                            alt=""
                            class="mx-auto mt-8 -mb-48 w-56"
                        />
                    </div>

                    <div
                        id="tour"
                        class="bg-muted grid scroll-mt-20 items-center gap-8 overflow-hidden rounded-[2rem] p-6 sm:p-10 lg:col-span-5 lg:grid-cols-[1fr_1.4fr]"
                    >
                        <div class="flex flex-col items-start gap-4">
                            <h2
                                class="font-heading text-3xl font-semibold tracking-tight text-balance"
                            >
                                Prefer a bigger screen?
                            </h2>
                            <template v-if="isWebPortalOpen">
                                <p class="text-muted-foreground text-pretty">
                                    The same account works in the web portal, in
                                    any browser.
                                </p>
                                <div class="flex flex-wrap gap-2">
                                    <Button
                                        v-if="!isSignedIn"
                                        as-child
                                        class="rounded-full"
                                    >
                                        <Link :href="register()">
                                            Create your account
                                        </Link>
                                    </Button>
                                    <Button
                                        v-if="!isSignedIn"
                                        as-child
                                        variant="outline"
                                        class="rounded-full"
                                    >
                                        <Link :href="login()">Sign in</Link>
                                    </Button>
                                    <TutorialDialog />
                                </div>
                            </template>
                            <p v-else class="text-muted-foreground text-pretty">
                                The web portal is opening soon, and your app
                                login will work there too. Until then,
                                everything is in the app.
                            </p>
                        </div>

                        <div
                            class="-mb-24 sm:-mb-32 lg:-mr-24"
                            aria-hidden="true"
                        >
                            <BrowserFrame
                                screen="01-resident-dashboard"
                                alt=""
                            />
                        </div>
                    </div>
                </section>
            </div>

            <!-- Getting started: a real sequence, so the steps are numbered. -->
            <section
                id="how-it-works"
                class="mx-auto w-full max-w-6xl scroll-mt-20 px-4 pt-16 sm:px-6 sm:pt-24"
            >
                <h2
                    class="font-heading max-w-xl text-3xl font-semibold tracking-tight text-balance sm:text-5xl"
                >
                    Getting in takes a minute
                </h2>

                <ol class="mt-10 grid gap-8 md:grid-cols-3">
                    <li
                        v-for="(step, index) in steps"
                        :key="step.title"
                        class="flex flex-col items-start gap-3"
                    >
                        <span
                            class="font-heading bg-primary text-primary-foreground flex size-10 items-center justify-center rounded-full font-semibold tabular-nums"
                        >
                            {{ index + 1 }}
                        </span>
                        <h3
                            class="font-heading text-xl font-semibold tracking-tight"
                        >
                            {{ step.title }}
                        </h3>
                        <p class="text-muted-foreground text-pretty">
                            {{ step.description }}
                        </p>
                    </li>
                </ol>
            </section>

            <!-- Security, as a grouped settings list. -->
            <section
                id="security"
                class="mx-auto grid w-full max-w-6xl scroll-mt-20 gap-8 px-4 py-16 sm:px-6 sm:py-24 lg:grid-cols-[1fr_1.4fr] lg:gap-16"
            >
                <div>
                    <h2
                        class="font-heading text-3xl font-semibold tracking-tight text-balance sm:text-5xl"
                    >
                        Your information stays yours
                    </h2>
                    <p class="text-muted-foreground mt-4 max-w-sm text-pretty">
                        A client account can only ever reach its own property,
                        lease and files.
                    </p>
                </div>

                <ul
                    class="bg-card ring-border divide-border divide-y overflow-hidden rounded-2xl ring-1"
                >
                    <li
                        v-for="point in securityPoints"
                        :key="point.title"
                        class="flex gap-4 p-4"
                    >
                        <span
                            class="bg-accent text-accent-foreground flex size-10 shrink-0 items-center justify-center rounded-xl"
                        >
                            <component :is="point.icon" class="size-5" />
                        </span>
                        <div>
                            <h3 class="font-heading font-semibold">
                                {{ point.title }}
                            </h3>
                            <p
                                class="text-muted-foreground text-sm text-pretty"
                            >
                                {{ point.description }}
                            </p>
                        </div>
                    </li>
                </ul>
            </section>
        </main>

        <!-- Navy in both themes, curving up to match the hero. The bottom
             padding keeps the last lines clear of the tab bar. -->
        <footer
            class="bg-scrim text-scrim-foreground rounded-t-[2.5rem] pb-24 sm:rounded-t-[3.5rem]"
        >
            <div
                id="download"
                class="border-scrim-foreground/15 mx-auto flex w-full max-w-6xl scroll-mt-20 flex-col gap-8 border-b px-4 py-16 sm:px-6 sm:py-20 lg:flex-row lg:items-center lg:justify-between"
            >
                <div class="flex max-w-xl flex-col items-start gap-6">
                    <h2
                        class="font-heading text-4xl font-semibold tracking-tight text-balance sm:text-6xl"
                    >
                        Get the app
                    </h2>
                    <p class="text-scrim-foreground/75 text-pretty">
                        Free for iPhone and Android. Sign up with the email we
                        have on file.
                    </p>
                    <AppStoreBadges />
                </div>

                <!-- Someone on a computer needs a way to reach their phone. -->
                <div class="hidden items-center gap-4 lg:flex">
                    <p class="text-scrim-foreground/75 w-32 text-right text-sm">
                        Scan with your phone camera
                    </p>
                    <div class="bg-scrim-foreground rounded-2xl p-3">
                        <img
                            src="/images/app/get-the-app-qr.svg"
                            alt="QR code for the TexasRenters App on the App Store and Google Play"
                            width="128"
                            height="128"
                            class="size-32"
                        />
                    </div>
                </div>
            </div>

            <SiteFooter />
        </footer>

        <AppTabBar />
    </div>
</template>
