<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import ShopLayout from '@/layouts/ShopLayout.vue'
import HomepageBlockRenderer from '@/components/cms/HomepageBlockRenderer.vue'
import { getHomepage, type HomepageBlock } from '@/api/cms'
import AppIcon from '@/components/ui/AppIcon.vue'

const { t } = useI18n()
const blocks = ref<HomepageBlock[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

onMounted(async () => {
  try {
    blocks.value = await getHomepage()
  } catch {
    error.value = t('home.loadError')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <ShopLayout>
    <div v-if="loading" class="space-y-6">
      <div class="h-48 animate-pulse rounded-3xl bg-stone-100 dark:bg-stone-800" />
      <div class="h-24 animate-pulse rounded-2xl bg-stone-100 dark:bg-stone-800" />
    </div>

    <p v-else-if="error" class="rounded-xl bg-red-50 p-4 text-sm text-red-700 dark:bg-red-950 dark:text-red-400">{{ error }}</p>

    <div v-else-if="!blocks.length" class="flex flex-col items-center gap-3 rounded-2xl bg-stone-100 py-20 text-center dark:bg-stone-900">
      <AppIcon name="box" :size="40" class="text-stone-300 dark:text-stone-700" />
      <p class="text-sm text-stone-500 dark:text-stone-400">{{ t('home.notConfigured') }}</p>
      <RouterLink :to="{ name: 'products' }" class="text-sm font-medium text-brand-600 dark:text-brand-400">
        {{ t('home.viewAllProducts') }}
      </RouterLink>
    </div>

    <!-- One source of block spacing (blocks add no outer margin/padding): 20 / 28 / 36 px. Flex gap, not space-y, so a fixed (floating) block never adds a gap. The extra mobile bottom padding keeps the last block clear of the floating WhatsApp button. -->
    <div v-else class="flex flex-col gap-5 pb-16 sm:gap-7 sm:pb-0 lg:gap-9">
      <HomepageBlockRenderer v-for="block in blocks" :key="block.id" :block="block" />
    </div>
  </ShopLayout>
</template>
