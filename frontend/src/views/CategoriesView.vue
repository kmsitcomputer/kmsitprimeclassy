<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import ShopLayout from '@/layouts/ShopLayout.vue'
import CategoryTreeList from '@/components/shop/CategoryTreeList.vue'
import { buildCategoryTree } from '@/utils/categoryTree'
import { listCategories } from '@/api/catalog'
import type { Category } from '@/api/types'

const { t } = useI18n()
const categories = ref<Category[]>([])
const loading = ref(true)
const tree = computed(() => buildCategoryTree(categories.value))

onMounted(async () => {
  categories.value = await listCategories()
  loading.value = false
})
</script>

<template>
  <ShopLayout>
    <h1 class="mb-4 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">{{ t('categories.title') }}</h1>

    <div v-if="loading" class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
      <div v-for="i in 8" :key="i" class="h-20 animate-pulse rounded-2xl bg-stone-100 dark:bg-stone-800" />
    </div>
    <p v-else-if="!tree.length" class="rounded-xl bg-stone-100 p-6 text-center text-sm text-stone-500 dark:bg-stone-900">
      {{ t('categories.empty') }}
    </p>
    <nav v-else :aria-label="t('categories.title')" data-testid="category-tree">
      <ul class="grid grid-cols-1 items-start gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <li v-for="root in tree" :key="root.category.id" class="rounded-2xl border border-stone-200 bg-stone-50 p-3 dark:border-stone-800 dark:bg-stone-950" data-testid="category-root">
          <CategoryTreeList :nodes="[root]" />
        </li>
      </ul>
    </nav>
  </ShopLayout>
</template>
