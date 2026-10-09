<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    FileText,
    Fingerprint,
    Lock,
    ShieldCheck,
} from '@lucide/vue';
import AppShowcase from '@/components/marketing/AppShowcase.vue';
import HouseBackdrop from '@/components/marketing/HouseBackdrop.vue';
import BrowserFrame from '@/components/marketing/BrowserFrame.vue';
import SiteFooter from '@/components/marketing/SiteFooter.vue';
import SiteHeader from '@/components/marketing/SiteHeader.vue';
import WebShowcase from '@/components/marketing/WebShowcase.vue';
import { Button } from '@/components/ui/button';
import { vReveal } from '@/lib/reveal';
import { computed } from 'vue';
import { dashboard, login, register } from '@/routes';

const page = usePage();
const isSignedIn = computed(() => page.props.auth.user !== null);

const steps = [
    {
        title: 'Sign up with your email',
        description:
            'Use the email address on your lease or ownership agreement, or open the invitation we sent you.',
    },
    {
        title: 'Confirm it is you',
        description:
            'Click the link we email you. It proves the address is yours before any property information is shown.',
    },
    {
        title: 'Everything is waiting',
        description:
            'We match your email to our records, so your property, work orders, lease and documents are already there.',
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
        <Head title="Client portal" />

        <SiteHeader />

        <main id="top" class="flex-1">
            <!-- Hero: the words on the left, the real portal on the right,
                 its windows running off the bottom edge. -->
            <section
                class="bg-scrim text-scrim-foreground relative isolate overflow-hidden"
            >
                <HouseBackdrop
                    class="text-scrim-foreground pointer-events-none absolute inset-x-0 bottom-0 -z-10 h-40 opacity-[0.08] sm:h-52"
                />

                <div
                    class="mx-auto grid w-full max-w-6xl items-center gap-12 px-4 pt-16 sm:px-6 sm:pt-20 lg:grid-cols-[1.05fr_1fr] lg:gap-8 lg:pt-12"
                >
                    <div class="flex flex-col items-start gap-7 lg:py-16">
                        <h1
                            v-reveal="0"
                            class="font-heading text-5xl leading-[1.02] font-semibold tracking-tight text-balance sm:text-7xl"
                        >
                            Your rental, handled.
                        </h1>
                        <p
                            v-reveal="120"
                            class="text-scrim-foreground/80 max-w-lg text-lg text-pretty"
                        >
                            The client portal for TexasRenters.com residents and
                            owners in Houston. Report repairs, message our team,
                            and find your lease, payments and documents, from
                            any browser.
                        </p>

                        <div
                            v-reveal="240"
                            class="flex flex-wrap items-center gap-3"
                        >
                            <Button
                                v-if="isSignedIn"
                                as-child
                                size="lg"
                                class="bg-scrim-foreground text-scrim hover:bg-scrim-foreground/90 h-12 px-6 text-base"
                            >
                                <Link :href="dashboard()">
                                    Go to your dashboard
                                    <ArrowRight class="size-4" />
                                </Link>
                            </Button>
                            <template v-else>
                                <Button
                                    as-child
                                    size="lg"
                                    class="bg-scrim-foreground text-scrim hover:bg-scrim-foreground/90 h-12 px-6 text-base"
                                >
                                    <Link :href="register()">
                                        Create your account
                                        <ArrowRight class="size-4" />
                                    </Link>
                                </Button>
                                <Button
                                    as-child
                                    size="lg"
                                    variant="outline"
                                    class="border-scrim-foreground/30 text-scrim-foreground hover:bg-scrim-foreground/10 hover:text-scrim-foreground h-12 bg-transparent px-6 text-base dark:bg-transparent"
                                >
                                    <Link :href="login()">Sign in</Link>
                                </Button>
                            </template>
                        </div>

                        <p
                            v-reveal="360"
                            class="text-scrim-foreground/65 max-w-md text-sm text-pretty"
                        >
                            Sign up with the email on your lease or ownership
                            agreement. Got an invitation? Just open the link in
                            the email.
                        </p>
                    </div>

                    <!-- One resident page in front, one owner page behind:
                         the portal serves both. -->
                    <div
                        class="relative mx-auto h-[16rem] w-full max-w-xl sm:h-[24rem] lg:h-[30rem] lg:max-w-none lg:self-end"
                        aria-hidden="true"
                    >
                        <BrowserFrame
                            screen="02-owner-dashboard"
                            alt=""
                            eager
                            class="motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-bottom-16 motion-safe:fill-mode-both absolute top-0 left-0 w-[82%] opacity-80 motion-safe:delay-150 motion-safe:duration-1000 lg:top-6"
                        />
                        <BrowserFrame
                            screen="01-resident-dashboard"
                            alt=""
                            eager
                            class="motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-bottom-16 motion-safe:fill-mode-both absolute top-12 right-0 w-[82%] motion-safe:duration-1000 sm:top-16 lg:top-24 lg:-right-16 lg:w-[95%]"
                        />
                    </div>
                </div>
            </section>

            <WebShowcase />

            <!-- Getting started: a real sequence, so the steps are numbered. -->
            <section
                id="how-it-works"
                class="bg-muted/50 border-border scroll-mt-16 border-y"
            >
                <div
                    class="mx-auto w-full max-w-6xl px-4 py-20 sm:px-6 sm:py-28"
                >
                    <div
                        v-reveal
                        class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
                    >
                        <h2
                            class="font-heading max-w-xl text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                        >
                            Getting in takes a minute
                        </h2>
                        <p class="text-muted-foreground max-w-sm text-pretty">
                            Use it in any browser, on your computer or phone.
                            There is nothing to install.
                        </p>
                    </div>

                    <ol class="mt-14 grid gap-10 md:grid-cols-3 md:gap-8">
                        <li
                            v-for="(step, index) in steps"
                            :key="step.title"
                            v-reveal="index * 140"
                            class="border-foreground flex flex-col gap-3 border-t-2 pt-6"
                        >
                            <span
                                class="font-heading text-primary text-sm font-semibold tabular-nums"
                            >
                                Step {{ index + 1 }}
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
                </div>
            </section>

            <!-- Security -->
            <section
                id="security"
                class="mx-auto grid w-full max-w-6xl scroll-mt-16 gap-10 px-4 py-20 sm:px-6 sm:py-28 lg:grid-cols-[1fr_1.4fr] lg:gap-16"
            >
                <div v-reveal.left>
                    <h2
                        class="font-heading text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                    >
                        Your information stays yours
                    </h2>
                    <p class="text-muted-foreground mt-4 max-w-sm text-pretty">
                        The portal was built so that a client account can only
                        ever reach its own property, lease and files.
                    </p>
                </div>

                <ul class="grid gap-x-10 gap-y-8 sm:grid-cols-2">
                    <li
                        v-for="(point, index) in securityPoints"
                        v-reveal.zoom="index * 110"
                        :key="point.title"
                        class="flex flex-col gap-2"
                    >
                        <component
                            :is="point.icon"
                            class="text-primary size-5"
                        />
                        <h3 class="font-heading font-semibold">
                            {{ point.title }}
                        </h3>
                        <p class="text-muted-foreground text-sm text-pretty">
                            {{ point.description }}
                        </p>
                    </li>
                </ul>
            </section>

            <AppShowcase />
        </main>

        <!-- Navy in both themes: the last call to action and the footer close
             the page in one block, matching the hero. -->
        <footer class="bg-scrim text-scrim-foreground">
            <div
                class="border-scrim-foreground/15 mx-auto flex w-full max-w-6xl flex-col gap-8 border-b px-4 py-20 sm:px-6 lg:flex-row lg:items-end lg:justify-between"
            >
                <div v-reveal class="max-w-xl">
                    <h2
                        class="font-heading text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
                    >
                        Ready to see your property?
                    </h2>
                    <p class="text-scrim-foreground/75 mt-4 text-pretty">
                        Create your account with the email we have on file.
                    </p>
                </div>
                <div v-reveal="150" class="flex flex-wrap items-center gap-3">
                    <Button
                        as-child
                        size="lg"
                        class="bg-scrim-foreground text-scrim hover:bg-scrim-foreground/90 h-12 px-6 text-base"
                    >
                        <Link :href="register()">Create your account</Link>
                    </Button>
                </div>
            </div>

            <SiteFooter />
        </footer>
    </div>
</template>
