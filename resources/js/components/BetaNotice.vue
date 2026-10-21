<script setup lang="ts">
import { FlaskConical } from '@lucide/vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
</script>

<template>
    <Alert class="mb-6">
        <FlaskConical />
        <AlertTitle>We're still in beta</AlertTitle>
        <AlertDescription>
            Access to the client portal isn't open yet. Please check back soon.
        </AlertDescription>
    </Alert>
</template>
