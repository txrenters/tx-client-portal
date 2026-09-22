<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Banknote,
    Building2,
    CheckCircle2,
    FileText,
    KeyRound,
    LayoutGrid,
    Lock,
    Mail,
    MailCheck,
    MessageSquare,
    Globe,
    LineChart,
    ScrollText,
    ShieldCheck,
    Smartphone,
    Wrench,
} from '@lucide/vue';
import AppStoreBadges from '@/components/marketing/AppStoreBadges.vue';
import PortalPreview from '@/components/marketing/PortalPreview.vue';
import ThemeToggle from '@/components/marketing/ThemeToggle.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';

const heroPills = [
    { label: 'Residents', icon: KeyRound },
    { label: 'Owners', icon: Building2 },
    { label: 'Verified email', icon: ShieldCheck },
    { label: 'Works on your phone', icon: Smartphone },
];

const heroTiles = [
    { label: 'Work Orders', icon: Wrench, live: true },
    { label: 'Documents', icon: FileText, live: true },
    { label: 'Property', icon: Building2, live: true },
    { label: 'Payments', icon: Banknote, live: false },
    { label: 'Lease', icon: ScrollText, live: false },
    { label: 'Messages', icon: MessageSquare, live: false },
];

const navLinks = [
    { label: 'Residents', href: '#tenants' },
    { label: 'Owners', href: '#owners' },
    { label: 'How it works', href: '#how-it-works' },
    { label: 'Security', href: '#security' },
];

const features = [
    {
        icon: Wrench,
        title: 'Work Orders',
        description:
            'Every work order with its status, schedule, assigned vendor and full history.',
        tags: ['Open', 'Scheduled', 'Completed'],
        live: true,
    },
    {
        icon: FileText,
        title: 'Documents',
        description:
            'Lease paperwork, statements and property files. PDFs and photos open right in the page.',
        tags: ['View', 'Download'],
        live: true,
    },
    {
        icon: Building2,
        title: 'Property details',
        description:
            'Address, unit, bedrooms, bathrooms, year built and occupancy, straight from our records.',
        tags: ['Residents', 'Owners'],
        live: true,
    },
    {
        icon: Banknote,
        title: 'Rent and payments',
        description:
            'Balance, amount due, due date and payment history for your lease.',
        tags: ['Balance', 'History'],
        live: false,
    },
    {
        icon: ScrollText,
        title: 'Lease and statements',
        description:
            'Lease dates and terms for tenants; owner statements and financial summaries.',
        tags: ['Lease', 'Statements'],
        live: false,
    },
    {
        icon: MessageSquare,
        title: 'Messages',
        description:
            'Ask about a specific repair, or anything else, and keep the whole thread in one place.',
        tags: ['Maintenance', 'Support'],
        live: false,
    },
];

const residentPoints = [
    'See the home you rent, your unit and your lease status',
    'Follow repairs from reported to finished, with photos and invoices',
    'Read and download your lease documents whenever you need them',
    'Know what is due and what you have already paid',
];

const ownerPoints = [
    'Your whole portfolio, with occupied and vacant properties at a glance',
    'Work orders across every property, filtered by property or status',
    'Costs on completed work, including what was charged to you',
    'Owner statements and property paperwork in one library',
];

const steps = [
    {
        icon: Mail,
        title: 'Sign up with your email',
        description:
            'Create your account with the email address on your lease or ownership agreement, or open the invitation we sent you.',
    },
    {
        icon: MailCheck,
        title: 'Confirm it is you',
        description:
            'Click the link we email you. It proves the address is yours before any property information is shown.',
    },
    {
        icon: LayoutGrid,
        title: 'Everything is waiting',
        description:
            'We match your email to our records, so your property, work orders and documents are already there.',
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
        icon: FileText,
        title: 'Files stay private',
        description:
            'Documents are served through your signed-in session, never from a public link that could be forwarded.',
    },
    {
        icon: Smartphone,
        title: 'Built for your phone',
        description:
            'The portal is designed for mobile first, so checking a repair on the way home is easy.',
    },
];
</script>

<template>
    <div
        class="bg-background text-foreground relative isolate flex min-h-svh flex-col overflow-x-clip"
    >
        <Head title="Client portal">
            <meta
                name="description"
                content="The TexasRenters.com client portal: maintenance, documents and property information for residents and owners."
            />
        </Head>

        <div
            class="pointer-events-none absolute inset-0 -z-10 overflow-hidden"
            aria-hidden="true"
        >
            <div
                class="bg-primary/20 dark:bg-primary/15 absolute -top-32 -left-48 h-[48rem] w-[48rem] rounded-full blur-[100px]"
            />
            <div
                class="bg-success/20 dark:bg-success/15 absolute top-96 -right-48 h-[44rem] w-[44rem] rounded-full blur-[100px]"
            />
            <div
                class="bg-primary/15 absolute top-1/2 -left-64 h-[52rem] w-[52rem] rounded-full blur-[100px]"
            />
            <div
                class="bg-success/15 absolute -right-32 bottom-0 h-[48rem] w-[48rem] rounded-full blur-[100px]"
            />
        </div>
        <header class="sticky top-0 z-50 px-4 pt-4 sm:px-6">
            <div
                class="glass-strong mx-auto flex w-full max-w-6xl items-center justify-between gap-4 rounded-2xl px-4 py-3 sm:px-5"
            >
                <a href="#top" class="flex items-center">
                    <img
                        src="/texas-renters-logo.png"
                        alt="TexasRenters.com"
                        class="h-8 w-auto object-contain sm:h-9 dark:brightness-0 dark:invert"
                        width="400"
                        height="111"
                    />
                </a>

                <nav
                    class="hidden items-center gap-1 lg:flex"
                    aria-label="Sections"
                >
                    <a
                        v-for="item in navLinks"
                        :key="item.href"
                        :href="item.href"
                        class="text-muted-foreground hover:text-foreground hover:bg-accent rounded-md px-3 py-2 text-sm font-medium transition-colors"
                    >
                        {{ item.label }}
                    </a>
                </nav>

                <div class="flex items-center gap-1">
                    <ThemeToggle />
                    <Button
                        as-child
                        size="sm"
                        variant="ghost"
                        class="hidden sm:inline-flex"
                    >
                        <Link :href="register()">Create account</Link>
                    </Button>
                    <Button as-child size="sm">
                        <Link :href="login()">Sign in</Link>
                    </Button>
                </div>
            </div>
        </header>

        <main id="top" class="flex-1">
            <!-- Hero -->
            <section class="relative overflow-hidden">
                <div
                    class="pointer-events-none absolute inset-0"
                    aria-hidden="true"
                >
                    <div
                        class="bg-primary/12 dark:bg-primary/25 absolute -top-40 left-1/2 size-[46rem] -translate-x-1/2 rounded-full blur-[140px]"
                    />
                    <div
                        class="bg-success/10 dark:bg-success/20 absolute top-40 -right-32 size-[30rem] rounded-full blur-[130px]"
                    />
                </div>

                <div
                    class="relative mx-auto flex w-full max-w-5xl flex-col items-center gap-8 px-4 py-20 text-center sm:px-6 sm:py-28"
                >
                    <ul
                        class="flex flex-wrap items-center justify-center gap-2"
                    >
                        <li v-for="pill in heroPills" :key="pill.label">
                            <span
                                class="glass flex items-center gap-2 rounded-full px-3 py-1.5 text-sm"
                            >
                                <component
                                    :is="pill.icon"
                                    class="text-primary size-4"
                                />
                                {{ pill.label }}
                            </span>
                        </li>
                    </ul>

                    <div class="flex flex-col gap-3">
                        <h1
                            class="font-heading text-4xl font-semibold tracking-tight text-balance sm:text-6xl"
                        >
                            <span
                                class="from-primary via-foreground to-success bg-gradient-to-r bg-clip-text text-transparent"
                            >
                                TexasRenters.com
                            </span>
                            <span class="block">Client Portal</span>
                        </h1>

                        <p
                            class="text-muted-foreground mx-auto max-w-2xl text-lg text-pretty"
                        >
                            The resident and owner portal for TexasRenters.com
                            property management in Houston. Work orders,
                            documents and property information, any time, from
                            any device.
                        </p>
                    </div>

                    <div
                        class="glass text-muted-foreground flex items-center gap-2 rounded-xl px-4 py-2.5 font-mono text-sm"
                    >
                        <Lock class="text-success size-3.5" />
                        portal.texasrenters.com
                    </div>

                    <div
                        class="flex flex-wrap items-center justify-center gap-3"
                    >
                        <Button as-child size="lg">
                            <Link :href="register()">
                                Create your account
                                <ArrowRight class="size-4" />
                            </Link>
                        </Button>
                        <Button as-child size="lg" variant="outline">
                            <Link :href="login()">Sign in</Link>
                        </Button>
                    </div>

                    <AppStoreBadges
                        heading="Also on your phone"
                        class="items-center"
                    />

                    <ul
                        class="mt-4 grid w-full grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6"
                    >
                        <li
                            v-for="tile in heroTiles"
                            :key="tile.label"
                            class="glass hover:border-primary/40 flex flex-col items-center gap-2 rounded-2xl p-4 transition-all duration-200 motion-safe:hover:-translate-y-0.5 motion-reduce:transition-none"
                        >
                            <component
                                :is="tile.icon"
                                class="text-primary size-5"
                            />
                            <span class="text-sm font-medium">
                                {{ tile.label }}
                            </span>
                            <span
                                class="text-[11px] font-medium tracking-wide uppercase"
                                :class="
                                    tile.live
                                        ? 'text-success'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ tile.live ? 'Available' : 'Soon' }}
                            </span>
                        </li>
                    </ul>

                    <p class="text-muted-foreground text-sm text-pretty">
                        Use the email address on your lease or ownership
                        agreement. Got an invitation? Just open the link in the
                        email.
                        <a
                            href="#how-it-works"
                            class="text-primary font-medium hover:underline"
                        >
                            How it works
                        </a>
                    </p>

                    <PortalPreview class="mt-6 w-full max-w-3xl text-left" />
                </div>
            </section>

            <!-- Features -->
            <section class="border-border/50 bg-card/20 border-y">
                <div
                    class="mx-auto w-full max-w-6xl px-4 py-16 sm:px-6 sm:py-20"
                >
                    <div class="max-w-2xl">
                        <h2
                            class="font-heading text-3xl font-semibold tracking-tight text-balance"
                        >
                            Everything about your property, in one place
                        </h2>
                        <p class="text-muted-foreground mt-3 text-pretty">
                            The portal reads directly from the systems
                            TexasRenters.com uses every day, so what you see is
                            what we see.
                        </p>
                    </div>

                    <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <article
                            v-for="feature in features"
                            :key="feature.title"
                            class="glass hover:border-primary/40 flex flex-col gap-3 rounded-2xl p-5 transition-all duration-200 motion-safe:hover:-translate-y-0.5 motion-reduce:transition-none"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <span
                                    class="bg-primary/10 text-primary flex size-10 items-center justify-center rounded-lg"
                                >
                                    <component
                                        :is="feature.icon"
                                        class="size-5"
                                    />
                                </span>
                                <Badge
                                    v-if="!feature.live"
                                    variant="secondary"
                                    class="text-[11px]"
                                >
                                    Coming soon
                                </Badge>
                            </div>

                            <h3 class="font-heading text-lg font-semibold">
                                {{ feature.title }}
                            </h3>
                            <p
                                class="text-muted-foreground text-sm text-pretty"
                            >
                                {{ feature.description }}
                            </p>

                            <div class="mt-auto flex flex-wrap gap-1.5 pt-2">
                                <span
                                    v-for="tag in feature.tags"
                                    :key="tag"
                                    class="bg-muted text-muted-foreground rounded-full px-2.5 py-1 text-xs font-medium"
                                >
                                    {{ tag }}
                                </span>
                            </div>
                        </article>
                    </div>
                </div>
            </section>

            <!-- Tenants / Owners -->
            <section
                class="mx-auto w-full max-w-6xl px-4 py-16 sm:px-6 sm:py-20"
            >
                <div class="grid gap-6 lg:grid-cols-2">
                    <div
                        id="tenants"
                        class="glass flex scroll-mt-24 flex-col gap-5 rounded-3xl p-6 sm:p-8"
                    >
                        <span
                            class="bg-primary/10 text-primary flex size-11 items-center justify-center rounded-xl"
                        >
                            <KeyRound class="size-5" />
                        </span>
                        <div>
                            <h2 class="font-heading text-2xl font-semibold">
                                For residents
                            </h2>
                            <p class="text-muted-foreground mt-1">
                                The home you rent, and everything that goes with
                                it.
                            </p>
                        </div>
                        <ul class="flex flex-col gap-3">
                            <li
                                v-for="point in residentPoints"
                                :key="point"
                                class="flex gap-2.5 text-sm"
                            >
                                <CheckCircle2
                                    class="text-primary mt-0.5 size-4 shrink-0"
                                />
                                <span>{{ point }}</span>
                            </li>
                        </ul>
                    </div>

                    <div
                        id="owners"
                        class="glass flex scroll-mt-24 flex-col gap-5 rounded-3xl p-6 sm:p-8"
                    >
                        <span
                            class="bg-success/10 text-success flex size-11 items-center justify-center rounded-xl"
                        >
                            <Building2 class="size-5" />
                        </span>
                        <div>
                            <h2 class="font-heading text-2xl font-semibold">
                                For owners
                            </h2>
                            <p class="text-muted-foreground mt-1">
                                How your properties are performing, without the
                                back and forth.
                            </p>
                        </div>
                        <ul class="flex flex-col gap-3">
                            <li
                                v-for="point in ownerPoints"
                                :key="point"
                                class="flex gap-2.5 text-sm"
                            >
                                <CheckCircle2
                                    class="text-success mt-0.5 size-4 shrink-0"
                                />
                                <span>{{ point }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- How it works -->
            <section
                id="how-it-works"
                class="border-border/50 bg-card/20 scroll-mt-20 border-y"
            >
                <div
                    class="mx-auto w-full max-w-6xl px-4 py-16 sm:px-6 sm:py-20"
                >
                    <div class="max-w-2xl">
                        <h2
                            class="font-heading text-3xl font-semibold tracking-tight"
                        >
                            Getting in takes a minute
                        </h2>
                        <p class="text-muted-foreground mt-3">
                            There is nothing to install and nothing to set up.
                        </p>
                    </div>

                    <ol class="mt-10 grid gap-4 md:grid-cols-3">
                        <li
                            v-for="(step, index) in steps"
                            :key="step.title"
                            class="glass relative flex flex-col gap-3 rounded-2xl p-6"
                        >
                            <span
                                class="text-muted-foreground/40 font-heading absolute top-4 right-5 text-4xl font-semibold tabular-nums"
                            >
                                {{ index + 1 }}
                            </span>
                            <span
                                class="bg-primary text-primary-foreground flex size-10 items-center justify-center rounded-lg"
                            >
                                <component :is="step.icon" class="size-5" />
                            </span>
                            <h3 class="font-heading text-lg font-semibold">
                                {{ step.title }}
                            </h3>
                            <p
                                class="text-muted-foreground text-sm text-pretty"
                            >
                                {{ step.description }}
                            </p>
                        </li>
                    </ol>
                </div>
            </section>

            <!-- Security -->
            <section
                id="security"
                class="mx-auto w-full max-w-6xl scroll-mt-20 px-4 py-16 sm:px-6 sm:py-20"
            >
                <div class="max-w-2xl">
                    <h2
                        class="font-heading text-3xl font-semibold tracking-tight"
                    >
                        Your information stays yours
                    </h2>
                    <p class="text-muted-foreground mt-3 text-pretty">
                        The portal was built so that a client account can only
                        ever reach its own property, lease and files.
                    </p>
                </div>

                <div class="mt-10 grid gap-4 sm:grid-cols-2">
                    <div
                        v-for="point in securityPoints"
                        :key="point.title"
                        class="glass flex gap-4 rounded-2xl p-5"
                    >
                        <span
                            class="bg-primary/10 text-primary flex size-10 shrink-0 items-center justify-center rounded-lg"
                        >
                            <component :is="point.icon" class="size-5" />
                        </span>
                        <div>
                            <h3 class="font-medium">{{ point.title }}</h3>
                            <p
                                class="text-muted-foreground mt-1 text-sm text-pretty"
                            >
                                {{ point.description }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Final CTA -->
            <section class="mx-auto w-full max-w-6xl px-4 pb-20 sm:px-6">
                <div
                    class="glass-strong from-primary/15 via-card/40 to-success/15 dark:from-primary/25 dark:to-success/20 relative flex flex-col items-start gap-6 overflow-hidden rounded-3xl bg-gradient-to-br p-8 sm:p-12"
                >
                    <h2
                        class="font-heading max-w-2xl text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                    >
                        Ready to see your property?
                    </h2>
                    <p class="text-muted-foreground max-w-xl text-pretty">
                        Create your account with the email we have on file, or
                        sign in if you already have one. If your email is not
                        recognized, get in touch and we will sort it out.
                    </p>

                    <div class="flex flex-wrap items-center gap-3">
                        <Button as-child size="lg">
                            <Link :href="register()">Create your account</Link>
                        </Button>
                        <Button as-child size="lg" variant="secondary">
                            <Link :href="login()">Sign in</Link>
                        </Button>
                        <Button
                            as-child
                            size="lg"
                            variant="outline"
                            class="border-primary/30 bg-card/50 text-foreground hover:bg-accent"
                        >
                            <a href="mailto:info@texasrenters.com">
                                <Mail class="size-4" />
                                Contact us
                            </a>
                        </Button>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-border/60 bg-card/30 border-t">
            <div
                class="mx-auto grid w-full max-w-6xl gap-8 px-4 py-12 sm:grid-cols-2 sm:px-6 lg:grid-cols-4"
            >
                <div class="flex flex-col gap-3">
                    <img
                        src="/texas-renters-logo.png"
                        alt="TexasRenters.com"
                        class="h-8 w-auto self-start object-contain dark:brightness-0 dark:invert"
                        width="400"
                        height="111"
                    />
                    <p class="text-muted-foreground text-sm text-pretty">
                        Houston property management for owners and residents.
                        Serving Houston, Katy, Sugar Land, The Woodlands,
                        Cypress, Pearland and surrounding areas.
                    </p>

                    <AppStoreBadges class="pt-1" />
                </div>

                <div class="flex flex-col gap-2 text-sm">
                    <p class="font-medium">Portal</p>
                    <Link
                        :href="register()"
                        class="text-muted-foreground hover:text-foreground"
                    >
                        Create account
                    </Link>
                    <Link
                        :href="login()"
                        class="text-muted-foreground hover:text-foreground"
                    >
                        Sign in
                    </Link>
                    <a
                        href="#how-it-works"
                        class="text-muted-foreground hover:text-foreground"
                    >
                        How it works
                    </a>
                    <a
                        href="#security"
                        class="text-muted-foreground hover:text-foreground"
                    >
                        Security
                    </a>
                </div>

                <div class="flex flex-col gap-2 text-sm">
                    <p class="font-medium">For clients</p>
                    <a
                        href="#tenants"
                        class="text-muted-foreground hover:text-foreground"
                    >
                        Tenants
                    </a>
                    <a
                        href="#owners"
                        class="text-muted-foreground hover:text-foreground"
                    >
                        Owners
                    </a>
                </div>

                <div class="flex flex-col gap-2 text-sm">
                    <p class="font-medium">Contact</p>
                    <a
                        href="mailto:info@texasrenters.com"
                        class="text-muted-foreground hover:text-foreground flex items-center gap-2"
                    >
                        <Mail class="size-3.5" />
                        info@texasrenters.com
                    </a>
                    <a
                        href="https://www.texasrenters.com"
                        class="text-muted-foreground hover:text-foreground flex items-center gap-2"
                        rel="noopener"
                    >
                        <Globe class="size-3.5" />
                        texasrenters.com
                    </a>
                    <a
                        href="https://www.texasrenters.com/free-rental-analysis"
                        class="text-muted-foreground hover:text-foreground flex items-center gap-2"
                        rel="noopener"
                    >
                        <LineChart class="size-3.5" />
                        Free rental analysis
                    </a>
                </div>
            </div>

            <div class="border-border/60 border-t">
                <div
                    class="text-muted-foreground mx-auto w-full max-w-6xl px-4 py-6 text-sm sm:px-6"
                >
                    &copy; {{ new Date().getFullYear() }} TexasRenters.com
                </div>
            </div>
        </footer>
    </div>
</template>
