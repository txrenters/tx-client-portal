<script setup lang="ts">
import { Head, Link, usePage } from "@inertiajs/vue3";
import { ArrowLeft, House, LifeBuoy } from "@lucide/vue";
import { computed } from "vue";
import SiteFooter from "@/components/marketing/SiteFooter.vue";
import SiteHeader from "@/components/marketing/SiteHeader.vue";
import { Button } from "@/components/ui/button";
import { dashboard, help, home, support } from "@/routes";

/**
 * What a visitor sees when something goes wrong, in the same header, navy
 * band and footer as the portal's public pages. Shown for 403, 404, 429, 500
 * and 503 (see AppServiceProvider::configureErrorPages).
 */
const props = defineProps<{
    status: number;
}>();

const messages: Record<number, { title: string; description: string }> = {
    403: {
        title: "You don't have access to this page",
        description:
            "It may belong to another account, or need a different role. If you think you should be able to see it, let us know.",
    },
    404: {
        title: "We couldn't find that page",
        description:
            "The link may be old or mistyped, or the page may have moved.",
    },
    429: {
        title: "Too many tries in a row",
        description:
            "Please wait a minute, then try again. This keeps your account safe.",
    },
    500: {
        title: "Something went wrong on our side",
        description:
            "We've been told about it and are looking into it. Please try again in a few minutes.",
    },
    503: {
        title: "We'll be right back",
        description:
            "The portal is down for a short update. Please check back in a few minutes.",
    },
};

const message = computed(
    () =>
        messages[props.status] ?? {
            title: "Something went wrong",
            description: "Please try again in a few minutes.",
        },
);

const page = usePage();
const isSignedIn = computed(() => page.props.auth?.user != null);

function goBack(): void {
    window.history.back();
}
</script>

<template>
    <div
        class="bg-background text-foreground flex min-h-svh flex-col overflow-x-clip"
    >
        <Head :title="message.title" />

        <SiteHeader />

        <main class="flex flex-1 flex-col">
            <section class="bg-scrim text-scrim-foreground">
                <div
                    class="mx-auto w-full max-w-3xl px-4 py-14 sm:px-6 sm:py-20"
                >
                    <p
                        class="text-scrim-foreground/60 font-heading text-sm font-semibold tracking-widest uppercase"
                    >
                        Error {{ status }}
                    </p>
                    <h1
                        class="font-heading mt-3 text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
                    >
                        {{ message.title }}
                    </h1>
                    <p
                        class="text-scrim-foreground/80 mt-4 max-w-xl text-lg leading-relaxed text-pretty"
                    >
                        {{ message.description }}
                    </p>
                </div>
            </section>

            <div
                class="mx-auto flex w-full max-w-3xl flex-col gap-8 px-4 py-12 sm:px-6"
            >
                <div class="flex flex-wrap gap-3">
                    <Button as-child>
                        <Link :href="isSignedIn ? dashboard() : home()">
                            <House class="size-4" />
                            {{
                                isSignedIn
                                    ? "Go to your dashboard"
                                    : "Go to the home page"
                            }}
                        </Link>
                    </Button>
                    <Button variant="outline" @click="goBack">
                        <ArrowLeft class="size-4" />
                        Go back
                    </Button>
                </div>

                <div
                    class="border-border bg-card text-card-foreground flex items-start gap-3 rounded-xl border p-4"
                >
                    <LifeBuoy class="text-primary mt-0.5 size-5 shrink-0" />
                    <p class="text-muted-foreground text-sm leading-relaxed">
                        Still stuck?
                        <Link
                            v-if="isSignedIn"
                            :href="help()"
                            class="text-primary underline-offset-4 hover:underline"
                            >Ask us for help</Link
                        >
                        <Link
                            v-else
                            :href="support()"
                            class="text-primary underline-offset-4 hover:underline"
                            >Visit our support page</Link
                        >
                        or text us at
                        <a
                            href="sms:+12812488018"
                            class="text-primary underline-offset-4 hover:underline"
                            >(281) 248-8018</a
                        >.
                    </p>
                </div>
            </div>
        </main>

        <footer class="bg-scrim text-scrim-foreground">
            <SiteFooter />
        </footer>
    </div>
</template>
