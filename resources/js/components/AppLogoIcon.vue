<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({ inheritAttrs: false });

defineProps<{ className?: HTMLAttributes['class'] }>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 40 40"
        fill="none"
        :class="className"
        v-bind="$attrs"
    >
        <rect x="2" y="2" width="36" height="36" rx="11" fill="#C6FF83" />
        <path d="m13 27 12-14M10 17l-3-1 3-1 1-3 1 3 3 1-3 1-1 3-1-3ZM27 26l-2.5-.8L27 24l1-3 1 3 2.5 1.2L29 26l-1 3-1-3ZM24 10l-.6-2L22 7.4 23.4 7l.6-2 .7 2L26 7.4l-1.3.6-.7 2Z" stroke="#17231A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
        <path d="m12.5 27.5 3 3 13-13-3-3-13 13Z" fill="#17231A" />
    </svg>
</template>
