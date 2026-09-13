<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'
import { useUiStore } from '@/stores/ui'
import { useSiteStore } from '@/stores/site'
import AppIcon from '@/components/ui/AppIcon.vue'
import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue'

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const cart = useCartStore()
const ui = useUiStore()
const site = useSiteStore()

/** Staff roles that operate from the dashboard get a shortcut back to it from the storefront. */
const DASHBOARD_ROLES = ['super_admin', 'agen', 'korsal', 'sales', 'admin', 'keuangan']
const canSeeDashboard = computed(() => !!auth.user && DASHBOARD_ROLES.includes(auth.user.role))

const searchTerm = ref('')

function submitSearch() {
  router.push({ name: 'products', query: searchTerm.value ? { search: searchTerm.value } : {} })
}
</script>

<template>
  <header class="sticky top-0 z-30 border-b border-stone-200 bg-white/95 backdrop-blur dark:border-stone-800 dark:bg-stone-950/95">
    <div class="mx-auto flex max-w-6xl items-center gap-3 px-4 py-3 sm:gap-4 sm:px-6">
      <RouterLink :to="{ name: 'home' }" class="flex shrink-0 items-center gap-2">
        <img v-if="site.logoUrl" :src="site.logoUrl" :alt="site.siteTitle" class="h-8 w-auto object-contain" />
        <span v-else class="font-display text-lg font-semibold text-brand-700 dark:text-brand-300">{{ site.siteTitle }}</span>
      </RouterLink>

      <form class="hidden flex-1 items-center sm:flex" @submit.prevent="submitSearch">
        <div class="relative w-full">
          <AppIcon name="search" :size="18" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-stone-400" />
          <input
            v-model="searchTerm"
            type="search"
            :placeholder="t('nav.searchPlaceholder')"
            class="w-full rounded-full border border-stone-200 bg-stone-50 py-2 pl-10 pr-4 text-sm text-stone-800 outline-none focus:border-brand-400 focus:ring-2 focus:ring-brand-100 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100 dark:focus:ring-brand-900"
          />
        </div>
      </form>

      <div class="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
        <LanguageSwitcher />

        <RouterLink
          :to="{ name: 'store-locator' }"
          class="grid h-10 w-10 place-items-center rounded-full text-stone-500 hover:bg-stone-100 dark:text-stone-400 dark:hover:bg-stone-800"
          :aria-label="t('nav.storeLocator')"
          :title="t('nav.storeLocator')"
        >
          <AppIcon name="map-pin" :size="19" />
        </RouterLink>

        <button
          type="button"
          class="grid h-10 w-10 place-items-center rounded-full text-stone-500 hover:bg-stone-100 dark:text-stone-400 dark:hover:bg-stone-800"
          :aria-label="t('nav.toggleTheme')"
          @click="ui.toggleTheme()"
        >
          <AppIcon :name="ui.theme === 'dark' ? 'moon' : 'sun'" :size="19" />
        </button>

        <RouterLink
          :to="{ name: 'wishlist' }"
          class="hidden h-10 w-10 place-items-center rounded-full text-stone-500 hover:bg-stone-100 sm:grid dark:text-stone-400 dark:hover:bg-stone-800"
          :aria-label="t('nav.wishlist')"
        >
          <AppIcon name="heart" :size="19" />
        </RouterLink>

        <RouterLink
          :to="{ name: 'cart' }"
          class="relative grid h-10 w-10 place-items-center rounded-full text-stone-500 hover:bg-stone-100 dark:text-stone-400 dark:hover:bg-stone-800"
          :aria-label="t('nav.cart')"
        >
          <AppIcon name="cart" :size="20" />
          <span
            v-if="cart.count > 0"
            class="absolute right-0.5 top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-brand-600 px-1 text-[10px] font-semibold text-white"
          >
            {{ cart.count > 99 ? '99+' : cart.count }}
          </span>
        </RouterLink>

        <RouterLink
          v-if="canSeeDashboard"
          :to="{ name: 'dashboard-home' }"
          class="flex items-center gap-1.5 rounded-full bg-stone-100 px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-200 dark:bg-stone-800 dark:text-stone-200"
          :aria-label="t('nav.dashboard')"
          :title="t('nav.dashboard')"
        >
          <AppIcon name="dashboard" :size="17" />
          <span class="hidden sm:inline">{{ t('nav.dashboard') }}</span>
        </RouterLink>

        <RouterLink
          v-if="auth.isLoggedIn"
          :to="{ name: 'profile' }"
          class="hidden h-10 w-10 place-items-center rounded-full text-stone-500 hover:bg-stone-100 sm:grid dark:text-stone-400 dark:hover:bg-stone-800"
          :aria-label="t('nav.account')"
        >
          <AppIcon name="user" :size="19" />
        </RouterLink>
        <RouterLink
          v-else
          :to="{ name: 'login' }"
          class="hidden rounded-full bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 sm:block"
        >
          {{ t('nav.login') }}
        </RouterLink>
      </div>
    </div>

    <form class="border-t border-stone-100 px-4 py-2 sm:hidden dark:border-stone-800" @submit.prevent="submitSearch">
      <div class="relative">
        <AppIcon name="search" :size="18" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-stone-400" />
        <input
          v-model="searchTerm"
          type="search"
          :placeholder="t('nav.searchPlaceholder')"
          class="w-full rounded-full border border-stone-200 bg-stone-50 py-2 pl-10 pr-4 text-sm outline-none focus:border-brand-400 focus:ring-2 focus:ring-brand-100 dark:border-stone-700 dark:bg-stone-900 dark:focus:ring-brand-900"
        />
      </div>
    </form>
  </header>
</template>
