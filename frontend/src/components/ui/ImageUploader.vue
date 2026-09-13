<script setup lang="ts">
/**
 * The one reusable image input for the whole dashboard (Blueprint §Media
 * Management) — upload / preview / delete / drag-drop / replace, all backed
 * by POST/DELETE /media. Every admin form binds `v-model:media-id` and
 * `v-model:url` here instead of hand-rolling its own <input type="file">.
 */
import { ref, computed } from 'vue'
import { uploadMedia, deleteMedia } from '@/api/media'
import { ApiError } from '@/api/client'
import AppIcon from './AppIcon.vue'

const props = withDefaults(
  defineProps<{
    mediaId?: number | null
    url?: string | null
    collection: string
    maxSizeMb?: number
    accept?: string
    label?: string
  }>(),
  { mediaId: null, url: null, maxSizeMb: 4, accept: 'image/jpeg,image/png,image/webp', label: 'Gambar' },
)

const emit = defineEmits<{
  'update:mediaId': [number | null]
  'update:url': [string | null]
}>()

const isDragging = ref(false)
const isUploading = ref(false)
const error = ref<string | null>(null)
const localPreview = ref<string | null>(null)
const inputRef = ref<HTMLInputElement | null>(null)

const previewUrl = computed(() => localPreview.value ?? props.url)

function validate(file: File): string | null {
  const allowed = props.accept.split(',').map((s) => s.trim())
  if (!allowed.includes(file.type)) {
    return 'Jenis file tidak didukung. Gunakan JPG, PNG, atau WEBP.'
  }
  if (file.size > props.maxSizeMb * 1024 * 1024) {
    return `Ukuran file maksimal ${props.maxSizeMb}MB.`
  }
  return null
}

async function handleFile(file: File): Promise<void> {
  error.value = null
  const validationError = validate(file)
  if (validationError) {
    error.value = validationError
    return
  }

  // Instant local preview while the real upload is in flight.
  localPreview.value = URL.createObjectURL(file)
  isUploading.value = true

  try {
    // Replacing an existing image: delete the old one first so it's never orphaned.
    if (props.mediaId) {
      await deleteMedia(props.mediaId).catch(() => undefined)
    }
    const media = await uploadMedia(file, props.collection)
    emit('update:mediaId', media.id)
    emit('update:url', media.url)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Upload gagal, coba lagi.'
    localPreview.value = null
  } finally {
    isUploading.value = false
    if (inputRef.value) inputRef.value.value = ''
  }
}

function onInputChange(event: Event): void {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (file) handleFile(file)
}

function onDrop(event: DragEvent): void {
  isDragging.value = false
  const file = event.dataTransfer?.files?.[0]
  if (file) handleFile(file)
}

async function removeImage(): Promise<void> {
  if (props.mediaId) {
    await deleteMedia(props.mediaId).catch(() => undefined)
  }
  localPreview.value = null
  error.value = null
  emit('update:mediaId', null)
  emit('update:url', null)
}
</script>

<template>
  <div class="space-y-2">
    <label v-if="label" class="block text-sm font-medium text-stone-700 dark:text-stone-300">{{ label }}</label>

    <div
      class="relative flex min-h-40 flex-col items-center justify-center rounded-xl border-2 border-dashed p-4 transition"
      :class="
        isDragging
          ? 'border-brand-500 bg-brand-50 dark:bg-brand-950/30'
          : 'border-stone-300 bg-stone-50 dark:border-stone-700 dark:bg-stone-900'
      "
      @dragover.prevent="isDragging = true"
      @dragleave.prevent="isDragging = false"
      @drop.prevent="onDrop"
    >
      <template v-if="previewUrl">
        <img :src="previewUrl" :alt="label" class="max-h-48 rounded-lg object-contain" />
        <div class="mt-3 flex gap-2">
          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-lg bg-stone-200 px-3 py-1.5 text-sm font-medium text-stone-800 hover:bg-stone-300 dark:bg-stone-800 dark:text-stone-100 dark:hover:bg-stone-700"
            :disabled="isUploading"
            @click="inputRef?.click()"
          >
            <AppIcon name="plus" :size="16" /> Ganti
          </button>
          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-lg bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100 dark:bg-red-950/40 dark:text-red-300"
            :disabled="isUploading"
            @click="removeImage"
          >
            <AppIcon name="trash" :size="16" /> Hapus
          </button>
        </div>
      </template>

      <template v-else>
        <AppIcon name="box" :size="32" class="text-stone-400" />
        <p class="mt-2 text-center text-sm text-stone-500 dark:text-stone-400">
          Seret gambar ke sini, atau
          <button type="button" class="font-medium text-brand-600 hover:underline dark:text-brand-400" @click="inputRef?.click()">
            pilih file
          </button>
        </p>
        <p class="mt-1 text-xs text-stone-400">JPG/PNG/WEBP, maks {{ maxSizeMb }}MB</p>
      </template>

      <div
        v-if="isUploading"
        class="absolute inset-0 flex items-center justify-center rounded-xl bg-white/70 text-sm font-medium text-stone-600 dark:bg-stone-950/70 dark:text-stone-300"
      >
        Mengunggah...
      </div>
    </div>

    <input ref="inputRef" type="file" :accept="accept" class="hidden" @change="onInputChange" />

    <p v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
  </div>
</template>
