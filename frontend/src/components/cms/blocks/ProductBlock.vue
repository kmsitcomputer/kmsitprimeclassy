<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { formatRupiah } from '@/utils/format'
import AppIcon from '@/components/ui/AppIcon.vue'

const { t } = useI18n()
defineProps<{ content: Record<string, any> }>()
</script>

<template>
  <section v-if="content.products?.length">
    <h2 v-if="content.heading" class="mb-3 font-display text-lg font-semibold text-stone-800 dark:text-stone-100">
      {{ content.heading }}
    </h2>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
      <RouterLink
        v-for="product in content.products"
        :key="product.id"
        :to="{ name: 'product-detail', params: { slug: product.slug } }"
        class="group flex flex-col overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900"
      >
        <div class="aspect-square overflow-hidden bg-stone-100 dark:bg-stone-800">
          <img v-if="product.image_url" :src="product.image_url" :alt="product.name" class="h-full w-full object-cover transition group-hover:scale-105" />
          <div v-else class="grid h-full w-full place-items-center text-stone-300"><AppIcon name="box" :size="32" /></div>
        </div>
        <div class="p-3">
          <h3 class="line-clamp-2 text-sm font-medium text-stone-800 dark:text-stone-100">{{ product.name }}</h3>
          <p class="font-display text-sm font-semibold text-brand-700 dark:text-brand-300">
            <template v-if="product.has_variations">{{ t('cms.startingFrom') }}</template>{{ product.base_price ? formatRupiah(product.base_price) : '—' }}
          </p>
        </div>
      </RouterLink>
    </div>
  </section>
</template>
