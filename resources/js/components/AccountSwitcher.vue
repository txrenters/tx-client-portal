<script setup lang="ts">
import { router, usePage } from "@inertiajs/vue3";
import { Building2, Check, ChevronsUpDown, ShieldCheck } from "@lucide/vue";
import { computed } from "vue";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from "@/components/ui/sidebar";
import { dashboard as adminDashboard } from "@/routes/admin";
import { update } from "@/routes/accounts";

const page = usePage();
const accounts = computed(() => page.props.client?.accounts ?? []);

/** Staff who also hold client accounts switch between the two sides here. */
const isStaff = computed(() => page.props.auth?.isAdmin === true);

/** Worth showing when there is somewhere else to go. */
const visible = computed(
    () =>
        accounts.value.length > 1 ||
        (isStaff.value && accounts.value.length > 0),
);

const goToStaffPortal = (): void => {
    router.visit(adminDashboard());
};
const active = computed(
    () => accounts.value.find((account) => account.active) ?? accounts.value[0],
);

const select = (id: number): void => {
    router.put(update.url(id));
};
</script>

<template>
    <SidebarMenu v-if="visible">
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        tooltip="Switch account"
                        data-test="account-switcher"
                    >
                        <Building2 class="size-4 shrink-0" />
                        <span
                            class="flex min-w-0 flex-1 flex-col text-left group-data-[collapsible=icon]:hidden"
                        >
                            <span class="text-muted-foreground text-xs"
                                >Switch account</span
                            >
                            <span class="truncate">{{
                                active?.label ?? "Select account"
                            }}</span>
                            <span
                                v-if="active"
                                class="text-muted-foreground truncate text-xs"
                                >Ref: {{ active.reference }}</span
                            >
                        </span>
                        <ChevronsUpDown
                            class="ml-auto size-4 group-data-[collapsible=icon]:hidden"
                        />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="max-h-80 w-(--reka-dropdown-menu-trigger-width) min-w-64 overflow-y-auto"
                    align="start"
                >
                    <template v-if="isStaff">
                        <DropdownMenuItem
                            class="cursor-pointer"
                            data-test="staff-portal"
                            @select="goToStaffPortal"
                        >
                            <ShieldCheck class="size-4 shrink-0" />
                            <span class="flex-1">Staff portal</span>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                    </template>
                    <DropdownMenuLabel class="text-muted-foreground text-xs">
                        Your accounts
                    </DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="account in accounts"
                        :key="account.id"
                        class="cursor-pointer"
                        @select="select(account.id)"
                    >
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="wrap-anywhere">{{
                                account.label
                            }}</span>
                            <span class="text-muted-foreground text-xs"
                                >{{ account.portal }} · Ref:
                                {{ account.reference }}</span
                            >
                        </span>
                        <Check
                            v-if="account.active"
                            class="ml-auto size-4 shrink-0"
                        />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
