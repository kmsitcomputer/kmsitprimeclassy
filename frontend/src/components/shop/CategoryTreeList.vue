<script setup lang="ts">
/** Recursive, accessible category list — each child is rendered inside its parent's group. */
import type { CategoryNode } from '@/utils/categoryTree'
import AppIcon from '@/components/ui/AppIcon.vue'

defineProps<{ nodes: CategoryNode[] }>()
</script>

<template>
  <ul class="space-y-1.5" role="group">
    <li v-for="node in nodes" :key="node.category.id" :data-category-id="node.category.id" :data-depth="node.depth">
      <RouterLink
        :to="{ name: 'products', query: { category: node.category.slug } }"
        class="flex items-center justify-between rounded-xl border bg-white px-4 text-stone-700 hover:border-brand-300 hover:text-brand-700 dark:bg-stone-900 dark:text-stone-200"
        :class="
          node.depth === 0
            ? 'border-stone-200 py-3.5 font-semibold dark:border-stone-700'
            : 'border-stone-100 py-2.5 text-sm font-medium dark:border-stone-800'
        "
      >
        {{ node.category.name }}
        <AppIcon name="chevron-right" :size="node.depth === 0 ? 18 : 15" class="text-stone-400" />
      </RouterLink>
      <div v-if="node.children.length" class="ml-4 mt-1.5 border-l-2 border-brand-100 pl-3 dark:border-stone-700">
        <CategoryTreeList :nodes="node.children" />
      </div>
    </li>
  </ul>
</template>
