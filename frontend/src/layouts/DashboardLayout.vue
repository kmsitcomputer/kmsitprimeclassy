<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { useSiteStore } from '@/stores/site'
import { buildNavStructure, groupHasVisibleItems, type NavGroup } from '@/dashboard/navConfig'
import AppIcon from '@/components/ui/AppIcon.vue'

const auth = useAuthStore()
const ui = useUiStore()
const site = useSiteStore()
const router = useRouter()
const route = useRoute()

const mobileNavOpen = ref(false)

const COLLAPSE_KEY = 'pc-sidebar-collapsed'
const collapsed = ref(false)
try {
  collapsed.value = localStorage.getItem(COLLAPSE_KEY) === '1'
} catch {
  /* localStorage unavailable — default to expanded, non-fatal */
}

function toggleCollapsed() {
  collapsed.value = !collapsed.value
  try {
    localStorage.setItem(COLLAPSE_KEY, collapsed.value ? '1' : '0')
  } catch {
    /* non-fatal */
  }
}

const navStructure = computed(() =>
  buildNavStructure()
    .filter((entry) => (entry.type === 'item' ? entry.show(auth) : groupHasVisibleItems(entry, auth)))
    .map((entry) =>
      entry.type === 'group' ? { ...entry, items: entry.items.filter((item) => item.show(auth)) } : entry,
    ),
)

/**
 * Collapsed-sidebar group flyouts are teleported to <body> and positioned
 * from the trigger's own bounding rect — an absolutely-positioned child
 * can't escape the sidebar <nav>'s clipping box otherwise, since setting
 * overflow-y without overflow-x still forces the browser to clip the x axis
 * too (a CSS rule most people never hit until a dropdown gets cut off).
 */
const flyoutGroup = ref<NavGroup | null>(null)
const flyoutPosition = ref({ top: 0, left: 0 })

function openFlyout(event: MouseEvent, group: NavGroup) {
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect()
  flyoutPosition.value = { top: rect.top, left: rect.right + 6 }
  flyoutGroup.value = group
}

function closeFlyout() {
  flyoutGroup.value = null
}

function groupContainsRoute(group: NavGroup, routeName: string | symbol | null | undefined): boolean {
  return group.items.some((item) => item.routeName === routeName)
}

const expandedGroups = ref<Set<string>>(new Set())

function syncExpandedToActiveRoute() {
  for (const entry of navStructure.value) {
    if (entry.type === 'group' && groupContainsRoute(entry, route.name)) {
      expandedGroups.value.add(entry.key)
    }
  }
}
syncExpandedToActiveRoute()
watch(() => route.name, syncExpandedToActiveRoute)

function toggleGroup(key: string) {
  if (expandedGroups.value.has(key)) {
    expandedGroups.value.delete(key)
  } else {
    expandedGroups.value.add(key)
  }
  // Re-trigger reactivity — Set mutation alone doesn't notify Vue's ref.
  expandedGroups.value = new Set(expandedGroups.value)
}

function isGroupActive(group: NavGroup): boolean {
  return groupContainsRoute(group, route.name)
}

const ROLE_LABELS: Record<string, string> = {
  super_admin: 'Super Admin',
  agen: 'Agen',
  korsal: 'Korsal',
  sales: 'Sales',
  admin: 'Admin',
  keuangan: 'Keuangan',
  kurir: 'Kurir',
  konsumen: 'Konsumen',
}

async function logout() {
  await auth.logout()
  router.push({ name: 'home' })
}
</script>

<template>
  <div class="flex min-h-screen bg-stone-50 dark:bg-stone-950">
    <!-- Desktop sidebar -->
    <aside
      class="hidden shrink-0 flex-col border-r border-stone-200 bg-white transition-[width] duration-150 dark:border-stone-800 dark:bg-stone-900 lg:flex"
      :class="collapsed ? 'w-16' : 'w-64'"
    >
      <div class="flex h-16 items-center justify-between px-3">
        <div v-if="!collapsed" class="flex min-w-0 items-center truncate px-2 font-display text-lg font-semibold text-brand-700 dark:text-brand-300">
          <img v-if="site.logoUrl" :src="site.logoUrl" :alt="site.siteTitle" class="h-8 w-auto object-contain" />
          <span v-else>{{ site.siteTitle }}</span>
        </div>
        <button
          type="button"
          class="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-stone-500 hover:bg-stone-100 dark:text-stone-400 dark:hover:bg-stone-800"
          :aria-label="collapsed ? 'Perluas sidebar' : 'Ciutkan sidebar'"
          @click="toggleCollapsed"
        >
          <AppIcon name="menu" :size="18" />
        </button>
      </div>

      <nav class="flex-1 space-y-0.5 overflow-y-auto px-2 py-2">
        <template v-for="entry in navStructure" :key="entry.key">
          <!-- Ungrouped single item -->
          <RouterLink
            v-if="entry.type === 'item'"
            :to="{ name: entry.routeName }"
            :title="collapsed ? entry.label : undefined"
            class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 dark:text-stone-300 dark:hover:bg-stone-800"
            :class="collapsed ? 'justify-center' : ''"
            active-class="!bg-brand-50 !text-brand-700 dark:!bg-brand-950 dark:!text-brand-300"
          >
            <AppIcon :name="entry.icon" :size="18" />
            <span v-if="!collapsed">{{ entry.label }}</span>
          </RouterLink>

          <!-- Group -->
          <div v-else class="relative" @mouseenter="collapsed && openFlyout($event, entry)" @mouseleave="collapsed && closeFlyout()">
            <button
              type="button"
              class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-medium text-stone-600 hover:bg-stone-100 dark:text-stone-300 dark:hover:bg-stone-800"
              :class="[collapsed ? 'justify-center' : '', isGroupActive(entry) && 'bg-stone-100 dark:bg-stone-800']"
              @click="!collapsed && toggleGroup(entry.key)"
            >
              <AppIcon :name="entry.icon" :size="18" :class="entry.color" />
              <template v-if="!collapsed">
                <span class="flex-1 truncate">{{ entry.label }}</span>
                <AppIcon
                  name="chevron-down"
                  :size="14"
                  class="shrink-0 transition-transform"
                  :class="expandedGroups.has(entry.key) ? 'rotate-180' : ''"
                />
              </template>
            </button>

            <!-- Expanded (non-collapsed) accordion children -->
            <div v-if="!collapsed && expandedGroups.has(entry.key)" class="ml-4 mt-0.5 space-y-0.5 border-l border-stone-200 pl-3 dark:border-stone-800">
              <RouterLink
                v-for="item in entry.items"
                :key="item.key"
                :to="{ name: item.routeName }"
                class="flex items-center gap-2.5 rounded-lg px-3 py-1.5 text-sm text-stone-600 hover:bg-stone-100 dark:text-stone-300 dark:hover:bg-stone-800"
                active-class="!bg-brand-50 !text-brand-700 dark:!bg-brand-950 dark:!text-brand-300"
              >
                <AppIcon :name="item.icon" :size="16" />
                <span>{{ item.label }}</span>
              </RouterLink>
            </div>
          </div>
        </template>
      </nav>

      <div class="border-t border-stone-200 p-3 dark:border-stone-800">
        <button
          type="button"
          class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 dark:text-stone-300 dark:hover:bg-stone-800"
          :class="collapsed ? 'justify-center' : ''"
          :title="collapsed ? 'Keluar' : undefined"
          @click="logout"
        >
          <AppIcon name="logout" :size="18" />
          <span v-if="!collapsed">Keluar</span>
        </button>
      </div>
    </aside>

    <!-- Mobile drawer -->
    <div v-if="mobileNavOpen" class="fixed inset-0 z-40 lg:hidden">
      <div class="absolute inset-0 bg-black/40" @click="mobileNavOpen = false" />
      <aside class="absolute inset-y-0 left-0 flex w-72 flex-col bg-white dark:bg-stone-900">
        <div class="flex h-16 items-center justify-between px-5">
          <span class="flex items-center font-display text-lg font-semibold text-brand-700 dark:text-brand-300">
            <img v-if="site.logoUrl" :src="site.logoUrl" :alt="site.siteTitle" class="h-8 w-auto object-contain" />
            <template v-else>{{ site.siteTitle }}</template>
          </span>
          <button type="button" class="text-stone-500" @click="mobileNavOpen = false">
            <AppIcon name="close" :size="20" />
          </button>
        </div>
        <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-2">
          <template v-for="entry in navStructure" :key="entry.key">
            <RouterLink
              v-if="entry.type === 'item'"
              :to="{ name: entry.routeName }"
              class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 dark:text-stone-300 dark:hover:bg-stone-800"
              active-class="!bg-brand-50 !text-brand-700 dark:!bg-brand-950 dark:!text-brand-300"
              @click="mobileNavOpen = false"
            >
              <AppIcon :name="entry.icon" :size="18" />
              {{ entry.label }}
            </RouterLink>
            <div v-else>
              <button
                type="button"
                class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-medium text-stone-600 hover:bg-stone-100 dark:text-stone-300 dark:hover:bg-stone-800"
                :class="isGroupActive(entry) && 'bg-stone-100 dark:bg-stone-800'"
                @click="toggleGroup(entry.key)"
              >
                <AppIcon :name="entry.icon" :size="18" :class="entry.color" />
                <span class="flex-1 truncate">{{ entry.label }}</span>
                <AppIcon
                  name="chevron-down"
                  :size="14"
                  class="shrink-0 transition-transform"
                  :class="expandedGroups.has(entry.key) ? 'rotate-180' : ''"
                />
              </button>
              <div v-if="expandedGroups.has(entry.key)" class="ml-4 mt-0.5 space-y-0.5 border-l border-stone-200 pl-3 dark:border-stone-800">
                <RouterLink
                  v-for="item in entry.items"
                  :key="item.key"
                  :to="{ name: item.routeName }"
                  class="flex items-center gap-2.5 rounded-lg px-3 py-1.5 text-sm text-stone-600 hover:bg-stone-100 dark:text-stone-300 dark:hover:bg-stone-800"
                  active-class="!bg-brand-50 !text-brand-700 dark:!bg-brand-950 dark:!text-brand-300"
                  @click="mobileNavOpen = false"
                >
                  <AppIcon :name="item.icon" :size="16" />
                  <span>{{ item.label }}</span>
                </RouterLink>
              </div>
            </div>
          </template>
        </nav>
        <div class="border-t border-stone-200 p-3 dark:border-stone-800">
          <button
            type="button"
            class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 dark:text-stone-300 dark:hover:bg-stone-800"
            @click="logout"
          >
            <AppIcon name="logout" :size="18" />
            Keluar
          </button>
        </div>
      </aside>
    </div>

    <div class="flex min-w-0 flex-1 flex-col">
      <header
        class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-stone-200 bg-white/95 px-4 backdrop-blur sm:px-6 dark:border-stone-800 dark:bg-stone-950/95"
      >
        <button type="button" class="text-stone-500 lg:hidden" @click="mobileNavOpen = true">
          <AppIcon name="menu" :size="22" />
        </button>
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-semibold text-stone-800 dark:text-stone-100">{{ auth.user?.name }}</p>
          <p class="text-xs text-stone-400 dark:text-stone-500">{{ ROLE_LABELS[auth.user?.role ?? ''] ?? auth.user?.role }}</p>
        </div>
        <button
          type="button"
          class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-stone-500 hover:bg-stone-100 dark:text-stone-400 dark:hover:bg-stone-800"
          aria-label="Ganti tema"
          @click="ui.toggleTheme()"
        >
          <AppIcon :name="ui.theme === 'dark' ? 'moon' : 'sun'" :size="18" />
        </button>
      </header>

      <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-5 sm:px-6 sm:py-6">
        <slot />
      </main>
    </div>

    <!-- Collapsed-sidebar group flyout — teleported so the sidebar's own
         overflow-y-auto (which forces x-axis clipping too) never cuts it off. -->
    <Teleport to="body">
      <div
        v-if="flyoutGroup"
        class="fixed z-50 w-52 rounded-xl border border-stone-200 bg-white p-1.5 shadow-lg dark:border-stone-800 dark:bg-stone-900"
        :style="{ top: `${flyoutPosition.top}px`, left: `${flyoutPosition.left}px` }"
        @mouseleave="closeFlyout"
      >
        <p class="px-2 py-1 text-xs font-semibold text-stone-400 dark:text-stone-500">{{ flyoutGroup.label }}</p>
        <RouterLink
          v-for="item in flyoutGroup.items"
          :key="item.key"
          :to="{ name: item.routeName }"
          class="flex items-center gap-2.5 rounded-lg px-2 py-1.5 text-sm text-stone-600 hover:bg-stone-100 dark:text-stone-300 dark:hover:bg-stone-800"
          active-class="!bg-brand-50 !text-brand-700 dark:!bg-brand-950 dark:!text-brand-300"
          @click="closeFlyout"
        >
          <AppIcon :name="item.icon" :size="16" />
          <span>{{ item.label }}</span>
        </RouterLink>
      </div>
    </Teleport>
  </div>
</template>
