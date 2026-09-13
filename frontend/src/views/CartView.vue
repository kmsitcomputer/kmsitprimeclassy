<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import ShopLayout from '@/layouts/ShopLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useCartStore } from '@/stores/cart'
import { useAuthStore } from '@/stores/auth'
import { formatRupiah } from '@/utils/format'

const { t } = useI18n()
const cart = useCartStore()
const auth = useAuthStore()
const router = useRouter()

function goToCheckout() {
  if (!auth.isLoggedIn) {
    router.push({ name: 'login', query: { redirect: '/checkout' } })
    return
  }
  router.push({ name: 'checkout' })
}
</script>

<template>
  <ShopLayout>
    <h1 class="mb-4 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">{{ t('cart.title') }}</h1>

    <div v-if="cart.isEmpty" class="flex flex-col items-center gap-3 rounded-2xl bg-stone-100 py-16 text-center dark:bg-stone-900">
      <AppIcon name="cart" :size="40" class="text-stone-300 dark:text-stone-700" />
      <p class="text-sm text-stone-500 dark:text-stone-400">{{ t('cart.empty') }}</p>
      <RouterLink :to="{ name: 'products' }" class="text-sm font-medium text-brand-600 dark:text-brand-400">{{ t('cart.startShopping') }}</RouterLink>
    </div>

    <div v-else class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_320px]">
      <ul class="space-y-3">
        <li
          v-for="line in cart.lines"
          :key="line.key"
          class="flex gap-3 rounded-2xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900"
        >
          <RouterLink :to="{ name: 'product-detail', params: { slug: line.productSlug } }" class="h-20 w-20 shrink-0 overflow-hidden rounded-xl bg-stone-100 dark:bg-stone-800">
            <img v-if="line.image" :src="line.image" :alt="line.name" class="h-full w-full object-cover" />
            <div v-else class="grid h-full w-full place-items-center text-stone-300"><AppIcon name="box" :size="24" /></div>
          </RouterLink>

          <div class="flex flex-1 flex-col">
            <div class="flex items-start justify-between gap-2">
              <div>
                <RouterLink :to="{ name: 'product-detail', params: { slug: line.productSlug } }" class="text-sm font-medium text-stone-800 dark:text-stone-100">
                  {{ line.name }}
                </RouterLink>
                <p v-if="line.variationLabel" class="text-xs text-stone-500 dark:text-stone-400">{{ line.variationLabel }}</p>
              </div>
              <button type="button" class="text-stone-400 hover:text-red-600" @click="cart.remove(line.key)">
                <AppIcon name="trash" :size="17" />
              </button>
            </div>

            <div class="mt-auto flex items-center justify-between">
              <div class="flex items-center rounded-full border border-stone-200 dark:border-stone-700">
                <button type="button" class="grid h-7 w-7 place-items-center text-stone-500" @click="cart.updateQuantity(line.key, line.quantity - 1)">
                  <AppIcon name="minus" :size="13" />
                </button>
                <span class="w-6 text-center text-xs font-medium">{{ line.quantity }}</span>
                <button type="button" class="grid h-7 w-7 place-items-center text-stone-500" @click="cart.updateQuantity(line.key, line.quantity + 1)">
                  <AppIcon name="plus" :size="13" />
                </button>
              </div>
              <p class="font-display text-sm font-semibold text-brand-700 dark:text-brand-300">
                {{ formatRupiah(line.unitPrice * line.quantity) }}
              </p>
            </div>
          </div>
        </li>
      </ul>

      <aside class="h-fit rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <h2 class="mb-3 font-display text-base font-semibold text-stone-800 dark:text-stone-100">{{ t('cart.summary') }}</h2>
        <div class="flex justify-between text-sm text-stone-600 dark:text-stone-300">
          <span>{{ t('cart.subtotalItems', { count: cart.count }) }}</span>
          <span>{{ formatRupiah(cart.subtotal) }}</span>
        </div>
        <p class="mt-1 text-xs text-stone-400">
          {{ t('cart.shippingNote') }}
        </p>
        <AppButton size="lg" block class="mt-4" @click="goToCheckout">{{ t('cart.checkout') }}</AppButton>
      </aside>
    </div>
  </ShopLayout>
</template>
