<script setup lang="ts">
import { Mail, Phone, Wrench } from '@lucide/vue';
import ListCard from '@/components/ListCard.vue';
import type { WorkOrderVendor } from '@/types';

defineProps<{
    vendors: WorkOrderVendor[];
}>();
</script>

<template>
    <ul class="flex flex-col gap-3">
        <li v-for="vendor in vendors" :key="vendor.name">
            <ListCard
                :title="vendor.name"
                :subtitle="vendor.type"
                :icon="Wrench"
            >
                <template v-if="vendor.phone || vendor.email" #meta>
                    <a
                        v-if="vendor.phone"
                        :href="`tel:${vendor.phone}`"
                        class="hover:text-foreground flex items-center gap-1"
                    >
                        <Phone class="size-3.5" />
                        {{ vendor.phone }}
                    </a>
                    <a
                        v-if="vendor.email"
                        :href="`mailto:${vendor.email}`"
                        class="hover:text-foreground flex items-center gap-1"
                    >
                        <Mail class="size-3.5" />
                        {{ vendor.email }}
                    </a>
                </template>
            </ListCard>
        </li>
    </ul>
</template>
