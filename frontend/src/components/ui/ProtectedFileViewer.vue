<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { fetchProtectedFile, ProtectedFileError, type ProtectedFile } from '@/api/protectedFile'

/**
 * Opens a PRIVATE (authorization-checked) file. The click only fetches the blob with the session;
 * the preview and the Download link are shown in-page and downloading is a separate, user-triggered
 * click — so browser popup protection / installed-PWA restrictions never swallow the action.
 */
const props = defineProps<{ url: string; label: string; filenameBase?: string }>()

const { t } = useI18n()
const open = ref(false)
const loading = ref(false)
const errorKind = ref<string | null>(null)
const file = ref<ProtectedFile | null>(null)
const objectUrl = ref<string | null>(null)

const isImage = computed(() => file.value?.mime.startsWith('image/') ?? false)
const isPdf = computed(() => file.value?.mime === 'application/pdf')
const errorText = computed(() => (errorKind.value ? t(`orders.proofViewer.errors.${errorKind.value}`) : null))

let requestSeq = 0

function release() {
  if (objectUrl.value) URL.revokeObjectURL(objectUrl.value)
  objectUrl.value = null
  file.value = null
}

async function load() {
  const seq = ++requestSeq
  open.value = true
  loading.value = true
  errorKind.value = null
  release()
  try {
    const result = await fetchProtectedFile(props.url, props.filenameBase ?? 'bukti')
    if (seq !== requestSeq) return
    file.value = result
    objectUrl.value = URL.createObjectURL(file.value.blob)
  } catch (e) {
    if (seq !== requestSeq) return
    errorKind.value = e instanceof ProtectedFileError ? e.kind : 'unknown'
  } finally {
    if (seq === requestSeq) loading.value = false
  }
}

function close() {
  requestSeq++
  loading.value = false
  open.value = false
  errorKind.value = null
  release()
}

onBeforeUnmount(close)
</script>

<template>
  <button
    type="button"
    class="inline-flex items-center rounded-lg border border-brand-200 px-2.5 py-1 text-xs font-medium text-brand-700 hover:bg-brand-50 disabled:opacity-60 dark:border-brand-800 dark:text-brand-300 dark:hover:bg-brand-950"
    :disabled="loading"
    @click="load"
  >
    {{ loading ? t('orders.proofViewer.loading') : label }}
  </button>

  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-3"
      role="dialog"
      aria-modal="true"
      @click.self="close"
    >
      <div class="flex max-h-[92vh] w-full max-w-2xl flex-col rounded-2xl bg-white p-4 shadow-xl dark:bg-stone-900">
        <div class="mb-3 flex items-center justify-between gap-2">
          <h3 class="text-sm font-semibold text-stone-900 dark:text-stone-100">{{ label }}</h3>
          <button type="button" class="text-sm text-stone-500 hover:text-stone-800 dark:hover:text-stone-200" @click="close">
            {{ t('orders.proofViewer.close') }}
          </button>
        </div>

        <p v-if="loading" class="py-10 text-center text-sm text-stone-500" role="status">{{ t('orders.proofViewer.loading') }}</p>

        <div v-else-if="errorText" class="space-y-3 py-6 text-center">
          <p class="text-sm text-red-600" role="alert">{{ errorText }}</p>
          <button type="button" class="text-xs font-medium text-brand-600 dark:text-brand-400" @click="load">
            {{ t('orders.proofViewer.retry') }}
          </button>
        </div>

        <template v-else-if="file && objectUrl">
          <div class="min-h-0 flex-1 overflow-auto rounded-lg bg-stone-50 dark:bg-stone-950">
            <img v-if="isImage" :src="objectUrl" :alt="label" class="mx-auto max-h-[70vh] w-auto max-w-full object-contain" />
            <iframe v-else-if="isPdf" :src="objectUrl" :title="label" class="h-[70vh] w-full" />
            <p v-else class="p-6 text-center text-sm text-stone-500">{{ t('orders.proofViewer.noPreview') }}</p>
          </div>
          <div class="mt-3 flex justify-end">
            <a
              :href="objectUrl"
              :download="file.filename"
              class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700"
            >
              {{ t('orders.proofViewer.download') }}
            </a>
          </div>
        </template>
      </div>
    </div>
  </Teleport>
</template>
