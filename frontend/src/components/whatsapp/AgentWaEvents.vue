<script setup lang="ts">
/**
 * Per-Agen WhatsApp notification events and templates (Agen and Admin Agen of that branch). One record per event; the backend validates
 * every template against the event's own variable allow-list and refuses anything that is not a transactional event (OTP, verification
 * and registration notices are not configurable). Nothing here decides authorisation.
 */
import { onMounted, ref } from 'vue'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import {
  listAgentWaEvents,
  previewAgentWaTemplate,
  resetAgentWaTemplate,
  updateAgentWaEvent,
  type AgentWaEvent,
} from '@/api/agentWhatsapp'

const events = ref<AgentWaEvent[]>([])
const drafts = ref<Record<string, string>>({})
const previews = ref<Record<string, string>>({})
const errors = ref<Record<string, string>>({})
const busy = ref<string | null>(null)
const loading = ref(true)
const loadError = ref('')
const notice = ref('')

function hydrate(list: AgentWaEvent[]) {
  events.value = list
  drafts.value = Object.fromEntries(list.map((e) => [e.key, e.template]))
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    hydrate(await listAgentWaEvents())
  } catch (e) {
    loadError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat pengaturan notifikasi.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

function replace(updated: AgentWaEvent) {
  events.value = events.value.map((e) => (e.key === updated.key ? updated : e))
  drafts.value[updated.key] = updated.template
}

async function act(key: string, work: () => Promise<void>) {
  busy.value = key
  errors.value[key] = ''
  notice.value = ''
  try {
    await work()
  } catch (e) {
    errors.value[key] = e instanceof ApiError ? formatApiError(e) : 'Permintaan gagal. Coba lagi.'
  } finally {
    busy.value = null
  }
}

const toggle = (e: AgentWaEvent, enabled: boolean) => act(e.key, async () => replace(await updateAgentWaEvent(e.key, { enabled })))
const save = (e: AgentWaEvent) =>
  act(e.key, async () => {
    replace(await updateAgentWaEvent(e.key, { template: drafts.value[e.key] ?? '' }))
    notice.value = 'Template disimpan.'
  })
const reset = (e: AgentWaEvent) =>
  act(e.key, async () => {
    replace(await resetAgentWaTemplate(e.key))
    previews.value[e.key] = ''
    notice.value = 'Template dikembalikan ke bawaan.'
  })
const preview = (e: AgentWaEvent) =>
  act(e.key, async () => {
    previews.value[e.key] = await previewAgentWaTemplate(e.key, drafts.value[e.key] ?? '')
  })
const insert = (key: string, variable: string) => (drafts.value[key] = (drafts.value[key] ?? '') + `{${variable}}`)

const cardClass = 'rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900'
const inputClass = 'mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950'
const btnPrimary = 'rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50'
const btnGhost =
  'rounded-lg border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 disabled:opacity-50 dark:border-stone-700 dark:text-stone-200 dark:hover:bg-stone-800'
</script>

<template>
  <section aria-label="Notifikasi cabang" class="space-y-4" data-test="wa-events">
    <div>
      <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">Notifikasi &amp; template pesan</h2>
      <p class="text-xs text-stone-500 dark:text-stone-400">
        Notifikasi transaksi dikirim dari nomor WhatsApp cabang ini. Kode verifikasi (OTP), pemulihan akun, dan pemberitahuan pendaftaran bersifat tetap dan tidak dapat diubah
        di sini. Event yang belum Anda atur mengikuti pengaturan bawaan platform.
      </p>
    </div>
    <p v-if="notice" role="status" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">{{ notice }}</p>
    <p v-if="loading" class="text-sm text-stone-500">Memuat...</p>
    <p v-else-if="loadError" role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
      {{ loadError }} <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
    </p>
    <article v-for="e in events" v-else :key="e.key" :class="cardClass" :data-test="'event-' + e.key">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-semibold text-stone-800 dark:text-stone-100">{{ e.label }}</h3>
        <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-stone-200">
          <input
            type="checkbox"
            class="h-4 w-4 accent-brand-600"
            :checked="e.enabled"
            :disabled="busy === e.key"
            :aria-label="'Aktifkan ' + e.label"
            @change="toggle(e, ($event.target as HTMLInputElement).checked)"
          />
          Aktif <span v-if="e.enabled_source === 'default'" class="text-[11px] text-stone-400">(bawaan platform)</span>
        </label>
      </div>
      <label class="mt-3 block text-sm text-stone-600 dark:text-stone-300">Template
        <textarea v-model="drafts[e.key]" rows="6" maxlength="1000" :class="inputClass" :aria-label="'Template ' + e.label" />
      </label>
      <p class="mt-2 text-xs text-stone-500">
        Variabel (klik untuk menyisipkan):
        <button
          v-for="v in e.variables"
          :key="v"
          type="button"
          class="ml-1 rounded bg-stone-100 px-1.5 py-0.5 font-mono text-[11px] text-stone-700 hover:bg-stone-200 dark:bg-stone-800 dark:text-stone-200"
          @click="insert(e.key, v)"
        >{{ '{' + v + '}' }}</button>
      </p>
      <p class="mt-1 text-[11px] text-stone-400">Baris dengan variabel opsional yang kosong dihapus otomatis; data tidak pernah dikarang.</p>
      <p v-if="errors[e.key]" role="alert" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950 dark:text-red-300">{{ errors[e.key] }}</p>
      <div class="mt-3 flex flex-wrap gap-2">
        <button type="button" :disabled="busy === e.key" :class="btnGhost" @click="preview(e)">Preview</button>
        <button type="button" :disabled="busy === e.key" :class="btnPrimary" @click="save(e)">Simpan Template</button>
        <button type="button" :disabled="busy === e.key || e.template_source === 'default'" :class="btnGhost" @click="reset(e)">Kembalikan Bawaan</button>
      </div>
      <pre v-if="previews[e.key]" class="mt-3 whitespace-pre-wrap rounded-xl bg-stone-50 p-3 font-sans text-sm text-stone-700 dark:bg-stone-950 dark:text-stone-200" aria-label="Pratinjau">{{ previews[e.key] }}</pre>
    </article>
  </section>
</template>
