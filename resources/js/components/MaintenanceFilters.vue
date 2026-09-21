<script setup lang="ts">
import { Search } from '@lucide/vue';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { WorkOrderStatus } from '@/types';

defineProps<{
    counts: Partial<Record<WorkOrderStatus, number>>;
    total: number;
    properties?: string[];
    /** Owners: how many work orders wait for approval; shows the "Needs approval" tab. */
    awaitingApproval?: number;
}>();

const status = defineModel<string>('status', { default: 'all' });
const search = defineModel<string>('search', { default: '' });
const property = defineModel<string>('property', { default: 'all' });
</script>

<template>
    <div class="flex flex-col gap-3">
        <div
            class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center"
        >
            <div class="relative w-full sm:w-72">
                <Search
                    class="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2"
                    aria-hidden="true"
                />
                <Input
                    v-model="search"
                    type="search"
                    class="pl-9"
                    placeholder="Search by number or description"
                    aria-label="Search work orders"
                />
            </div>

            <Select
                v-if="properties && properties.length > 1"
                v-model="property"
            >
                <SelectTrigger
                    class="w-full sm:w-56"
                    aria-label="Filter by property"
                >
                    <SelectValue placeholder="All properties" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All properties</SelectItem>
                    <SelectItem
                        v-for="item in properties"
                        :key="item"
                        :value="item"
                    >
                        {{ item }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <Tabs v-model="status" class="min-w-0 items-start">
            <TabsList
                class="w-fit max-w-full justify-start overflow-x-auto overflow-y-hidden"
            >
                <TabsTrigger value="all">All ({{ total }})</TabsTrigger>
                <TabsTrigger
                    v-if="awaitingApproval !== undefined"
                    value="approval"
                >
                    Needs approval ({{ awaitingApproval }})
                </TabsTrigger>
                <TabsTrigger value="open">
                    Open ({{ counts.open ?? 0 }})
                </TabsTrigger>
                <TabsTrigger value="scheduled">
                    Scheduled ({{ counts.scheduled ?? 0 }})
                </TabsTrigger>
                <TabsTrigger value="completed">
                    Completed ({{ counts.completed ?? 0 }})
                </TabsTrigger>
                <TabsTrigger value="canceled">
                    Canceled ({{ counts.canceled ?? 0 }})
                </TabsTrigger>
            </TabsList>
        </Tabs>
    </div>
</template>
