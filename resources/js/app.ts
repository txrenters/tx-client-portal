import { createInertiaApp } from "@inertiajs/vue3";
import { initializeTheme } from "@/composables/useAppearance";
import AdminLayout from "@/layouts/AdminLayout.vue";
import AppLayout from "@/layouts/AppLayout.vue";
import AuthLayout from "@/layouts/AuthLayout.vue";
import SettingsLayout from "@/layouts/settings/Layout.vue";
import { initializeFlashToast } from "@/lib/flashToast";
import { configureEcho } from "@laravel/echo-vue";

configureEcho({
    broadcaster: "reverb",
});

const appName = import.meta.env.VITE_APP_NAME || "Laravel";

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            // Public pages and the error page bring their own marketing header and footer.
            case ["Welcome", "Privacy", "Support", "Error"].includes(name):
                return null;
            case name.startsWith("auth/"):
                return AuthLayout;
            case name.startsWith("admin/"):
                return AdminLayout;
            case name.startsWith("settings/"):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: "#4B5563",
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
