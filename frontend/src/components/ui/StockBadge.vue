<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

/**
 * Stock is only ever known to the frontend when the backend chose to
 * include `agent_available_quantity` (i.e. the viewer is authenticated and
 * has a resolved network agent) — see ProductController::attachAgentAvailability.
 * `quantity === undefined` means "not disclosed", not "zero"; this badge
 * must render that as a neutral prompt, never as "out of stock".
 */
const props = defineProps<{ quantity: number | undefined; loggedIn: boolean }>()

const state = computed<'hidden' | 'out' | 'low' | 'in'>(() => {
  if (!props.loggedIn || props.quantity === undefined) return 'hidden'
  if (props.quantity <= 0) return 'out'
  if (props.quantity <= 5) return 'low'
  return 'in'
})
</script>

<template>
  <span
    v-if="state === 'hidden'"
    class="inline-flex items-center gap-1 rounded-full bg-stone-100 px-2.5 py-1 text-xs font-medium text-stone-500 dark:bg-stone-800 dark:text-stone-400"
  >
    {{ t('stockBadge.loginToView') }}
  </span>
  <span
    v-else-if="state === 'out'"
    class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700 dark:bg-red-950 dark:text-red-400"
  >
    {{ t('stockBadge.outOfStock') }}
  </span>
  <span
    v-else-if="state === 'low'"
    class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 dark:bg-amber-950 dark:text-amber-400"
  >
    {{ t('stockBadge.stockCount', { quantity }) }}
  </span>
  <span
    v-else
    class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400"
  >
    {{ t('stockBadge.stockCount', { quantity }) }}
  </span>
</template>
