<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { applyUpdate, useServiceWorkerUpdate } from '@/pwa/registerServiceWorker'

const { t } = useI18n()
const { updateAvailable } = useServiceWorkerUpdate()
const applying = ref(false)

async function reload() {
  if (applying.value) return
  applying.value = true
  try {
    await applyUpdate()
  } finally {
    applying.value = false
  }
}
</script>

<template>
  <div
    v-if="updateAvailable"
    role="status"
    class="flex items-center justify-between gap-3 bg-[#8f1d3c] px-4 py-2 text-sm text-white"
  >
    <p>
      <strong>{{ t('pwa.updateTitle') }}</strong>
      <span class="opacity-90">{{ t('pwa.updateMessage') }}</span>
    </p>
    <button
      type="button"
      :disabled="applying"
      class="shrink-0 rounded bg-white px-3 py-1 font-semibold text-[#8f1d3c] disabled:opacity-60"
      @click="reload"
    >
      {{ t('pwa.reload') }}
    </button>
  </div>
</template>
