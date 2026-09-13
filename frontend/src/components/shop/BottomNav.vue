<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'
import AppIcon from '@/components/ui/AppIcon.vue'

const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const cart = useCartStore()

const tabs = computed(() => [
  { name: 'home', label: t('nav.home'), icon: 'home' as const },
  { name: 'categories', label: t('nav.categories'), icon: 'grid' as const },
  { name: 'cart', label: t('nav.cart'), icon: 'cart' as const },
  { name: 'wishlist', label: t('nav.wishlist'), icon: 'heart' as const },
  // Only a logged-in konsumen has orders to check — a guest sees the
  // 4 tabs above plus "Login" only (see the 5th slot below).
  ...(auth.isLoggedIn ? [{ name: 'orders', label: t('nav.orders'), icon: 'clock' as const }] : []),
])
</script>

<template>
  <nav
    class="fixed inset-x-0 bottom-0 z-30 grid border-t border-stone-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur sm:hidden dark:border-stone-800 dark:bg-stone-950/95"
    :class="auth.isLoggedIn ? 'grid-cols-6' : 'grid-cols-5'"
  >
    <RouterLink
      v-for="tab in tabs"
      :key="tab.name"
      :to="{ name: tab.name }"
      class="relative flex flex-col items-center justify-center gap-0.5 py-2 text-[11px]"
      :class="route.name === tab.name ? 'text-brand-600 dark:text-brand-400' : 'text-stone-500 dark:text-stone-400'"
    >
      <AppIcon :name="tab.icon" :size="21" />
      <span
        v-if="tab.name === 'cart' && cart.count > 0"
        class="absolute right-[28%] top-1 grid h-3.5 min-w-3.5 place-items-center rounded-full bg-brand-600 px-0.5 text-[9px] font-semibold text-white"
      >
        {{ cart.count > 9 ? '9+' : cart.count }}
      </span>
      {{ tab.label }}
    </RouterLink>

    <RouterLink
      :to="{ name: auth.isLoggedIn ? 'profile' : 'login' }"
      class="flex flex-col items-center justify-center gap-0.5 py-2 text-[11px]"
      :class="
        route.name === 'profile' || route.name === 'login'
          ? 'text-brand-600 dark:text-brand-400'
          : 'text-stone-500 dark:text-stone-400'
      "
    >
      <AppIcon name="user" :size="21" />
      {{ auth.isLoggedIn ? t('nav.account') : t('nav.login') }}
    </RouterLink>
  </nav>
</template>
