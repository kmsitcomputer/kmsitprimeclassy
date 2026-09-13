<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import ShopLayout from '@/layouts/ShopLayout.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { listCategories } from '@/api/catalog'
import type { Category } from '@/api/types'

const { t } = useI18n()
const categories = ref<Category[]>([])
const loading = ref(true)

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
    <p v-else-if="!categories.length" class="rounded-xl bg-stone-100 p-6 text-center text-sm text-stone-500 dark:bg-stone-900">
      {{ t('categories.empty') }}
    </p>
    <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
      <RouterLink
        v-for="category in categories"
        :key="category.id"
        :to="{ name: 'products', query: { category: category.slug } }"
        class="flex items-center justify-between rounded-2xl border border-stone-200 bg-white px-4 py-4 font-medium text-stone-700 hover:border-brand-300 hover:text-brand-700 dark:border-stone-800 dark:bg-stone-900 dark:text-stone-200"
      >
        {{ category.name }}
        <AppIcon name="chevron-right" :size="18" class="text-stone-400" />
      </RouterLink>
    </div>
  </ShopLayout>
</template>
