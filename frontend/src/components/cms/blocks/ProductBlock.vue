<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { formatRupiah } from '@/utils/format'
import AppIcon from '@/components/ui/AppIcon.vue'
import '@/assets/product-card.css'

const { t } = useI18n()
import type { HomepageContentProduct } from '@/api/cms'

defineProps<{ content: { heading?: string; products?: HomepageContentProduct[] } }>()

// A variation product has no base price: its price is the cheapest active variation (`starting_price`).
// Older payloads without that field fall back to base_price. No valid price -> no amount at all.
function priceOf(product: HomepageContentProduct): string | number | null {
  const value = product.starting_price !== undefined ? product.starting_price : (product.base_price ?? null)
  return value === null || value === '' || Number.isNaN(Number(value)) ? null : value
}
</script>

<template>
  <section v-if="content.products?.length">
    <h2 v-if="content.heading" class="mb-3 font-display text-lg font-semibold text-stone-800 dark:text-stone-100">
      {{ content.heading }}
    </h2>
    <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 sm:gap-3.5 lg:grid-cols-4 lg:gap-4">
      <RouterLink
        v-for="product in content.products"
        :key="product.id"
        :to="{ name: 'product-detail', params: { slug: product.slug } }"
        class="pc-card group"
      >
        <div class="pc-card__image aspect-square">
          <img v-if="product.image_url" :src="product.image_url" :alt="product.name" class="h-full w-full object-cover" />
          <div v-else class="pc-card__placeholder grid h-full w-full place-items-center"><AppIcon name="box" :size="32" /></div>
        </div>
        <div class="pc-card__footer p-2.5 sm:p-3 lg:p-4">
          <h3 class="pc-card__name line-clamp-2 min-h-10 text-[13px] font-medium leading-5 sm:text-sm lg:text-[15px]">{{ product.name }}</h3>
          <div class="pc-card__divider my-1.5 sm:my-2" data-testid="product-divider" aria-hidden="true" />
          <p class="pc-card__price font-display text-sm font-semibold sm:text-[15px] lg:text-base" data-testid="product-price">
            <template v-if="priceOf(product) !== null"><template v-if="product.has_variations">{{ t('cms.startingFrom') }}</template>{{ formatRupiah(priceOf(product)!) }}</template><template v-else>—</template>
          </p>
        </div>
      </RouterLink>
    </div>
  </section>
</template>
