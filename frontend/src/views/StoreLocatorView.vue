<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import ShopLayout from '@/layouts/ShopLayout.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { listPublicAgents, type PublicAgentContact } from '@/api/agents'

const router = useRouter()

/** Public storefront "Cari Agen/Toko" directory — GET /agents, no auth required. */
const agents = ref<PublicAgentContact[]>([])
const loading = ref(true)

onMounted(async () => {
  agents.value = await listPublicAgents()
  loading.value = false
})

function mapsUrl(agent: PublicAgentContact): string {
  return `https://www.google.com/maps/search/?api=1&query=${agent.latitude},${agent.longitude}`
}

const copiedIndex = ref<number | null>(null)
async function copyReferralCode(code: string, i: number) {
  await navigator.clipboard.writeText(code)
  copiedIndex.value = i
  setTimeout(() => {
    if (copiedIndex.value === i) copiedIndex.value = null
  }, 2000)
}

/** Router guard captures ?ref= from this navigation, RegisterView pre-fills from it. */
function useThisReferral(code: string) {
  router.push({ name: 'register', query: { ref: code } })
}
</script>

<template>
  <ShopLayout>
    <h1 class="mb-1 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">Cari Agen / Toko Kami</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">Temukan toko agen resmi kami terdekat dari lokasi Anda.</p>

    <div v-if="loading" class="space-y-3">
      <div v-for="i in 3" :key="i" class="h-24 animate-pulse rounded-2xl bg-stone-100 dark:bg-stone-800" />
    </div>

    <div v-else class="space-y-3">
      <a
        v-for="(agent, i) in agents"
        :key="i"
        :href="mapsUrl(agent)"
        target="_blank"
        rel="noopener"
        class="block rounded-2xl border border-stone-200 bg-white p-4 transition hover:border-brand-300 dark:border-stone-800 dark:bg-stone-900"
      >
        <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">{{ agent.name }}</h2>
        <p class="mt-1 flex items-start gap-1.5 text-sm text-stone-600 dark:text-stone-300">
          <AppIcon name="map-pin" :size="16" class="mt-0.5 shrink-0 text-stone-400" /> {{ agent.address }}
        </p>
        <p v-if="agent.phone" class="mt-1 text-sm text-stone-500 dark:text-stone-400">{{ agent.phone }}</p>
        <div v-if="agent.referral_code" class="mt-2 flex flex-wrap items-center gap-2">
          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-700 transition hover:bg-brand-100 dark:bg-brand-950 dark:text-brand-300 dark:hover:bg-brand-900"
            @click.stop.prevent="copyReferralCode(agent.referral_code, i)"
          >
            <span>Kode Referral: <span class="font-semibold tracking-wide">{{ agent.referral_code }}</span></span>
            <AppIcon v-if="copiedIndex === i" name="check" :size="14" />
            <span>{{ copiedIndex === i ? 'Tersalin!' : 'Salin' }}</span>
          </button>
          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-full bg-stone-800 px-2.5 py-1 text-xs font-medium text-white transition hover:bg-stone-700 dark:bg-white dark:text-stone-900 dark:hover:bg-stone-200"
            @click.stop.prevent="useThisReferral(agent.referral_code)"
          >
            Gunakan Kode Ini
          </button>
        </div>
      </a>
      <p v-if="agents.length === 0" class="text-sm text-stone-400">Belum ada agen aktif yang terdaftar.</p>
    </div>
  </ShopLayout>
</template>
