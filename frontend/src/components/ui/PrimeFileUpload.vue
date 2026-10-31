<script setup lang="ts">
/**
 * Universal Upload UI/UX Standard (Owner-locked, Phase 2) — the ONE file *selection* surface for
 * operational pages (payment/delivery/return/consent evidence, CSV import…). It owns choosing,
 * validating and previewing files; the PARENT keeps its own submit call, so every backend
 * endpoint and contract stays untouched. Selecting a file is never approval: verification
 * badges are shown only when a parent passes `existing[].status` for a workflow that has one.
 *
 * Progress is shown as a percentage ONLY when the parent passes a real `progress` (axios
 * onUploadProgress); while `uploading` without one it shows an indeterminate bar.
 * (ImageUploader.vue remains the immediate-upload /media widget for CMS/product forms.)
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import AppIcon from './AppIcon.vue'

export interface ExistingUpload {
  url?: string | null
  name: string
  /** Verification state of an already-submitted file, only for workflows that have one. */
  status?: 'pending' | 'verified' | 'rejected' | null
  rejectionReason?: string | null
}

const props = withDefaults(
  defineProps<{
    modelValue: File[]
    accept?: string
    maxSizeMb?: number
    maxFiles?: number
    /** Show a dedicated camera button (mobile `capture`). */
    capture?: 'environment' | 'user' | null
    label?: string
    hint?: string
    required?: boolean
    disabled?: boolean
    readonly?: boolean
    uploading?: boolean
    /** Real 0–100 transport progress; omit/null for an indeterminate state. */
    progress?: number | null
    error?: string | null
    existing?: ExistingUpload[]
    minWidth?: number
    minHeight?: number
    testId?: string
  }>(),
  {
    accept: 'image/jpeg,image/png,image/webp',
    maxSizeMb: 5,
    maxFiles: 1,
    capture: null,
    label: '',
    hint: '',
    required: false,
    disabled: false,
    readonly: false,
    uploading: false,
    progress: null,
    error: null,
    existing: () => [],
    minWidth: 0,
    minHeight: 0,
    testId: 'prime-file-upload',
  },
)

const emit = defineEmits<{
  'update:modelValue': [File[]]
  invalid: [string]
  retry: []
  cancel: []
}>()

const inputRef = ref<HTMLInputElement | null>(null)
const cameraRef = ref<HTMLInputElement | null>(null)
const isDragging = ref(false)
const localError = ref<string | null>(null)
const previews = ref<Map<File, string>>(new Map())
const inputId = `pfu-${Math.random().toString(36).slice(2, 9)}`

const multiple = computed(() => props.maxFiles > 1)
const interactive = computed(() => !props.disabled && !props.readonly && !props.uploading)
const shownError = computed(() => localError.value ?? props.error)
const acceptList = computed(() => props.accept.split(',').map((s) => s.trim().toLowerCase()).filter(Boolean))
const acceptLabel = computed(() =>
  acceptList.value.map((a) => (a.startsWith('.') ? a.slice(1) : (a.split('/')[1] ?? a))).join('/').toUpperCase(),
)

function isImage(file: File): boolean {
  return file.type.startsWith('image/')
}

function matchesAccept(file: File): boolean {
  const name = file.name.toLowerCase()
  return acceptList.value.some((a) => {
    if (a.startsWith('.')) return name.endsWith(a)
    if (a.endsWith('/*')) return file.type.startsWith(a.slice(0, -1))
    return file.type.toLowerCase() === a
  })
}

function readDimensions(file: File): Promise<{ w: number; h: number }> {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file)
    const img = new Image()
    img.onload = () => {
      URL.revokeObjectURL(url)
      resolve({ w: img.naturalWidth, h: img.naturalHeight })
    }
    img.onerror = () => {
      URL.revokeObjectURL(url)
      reject(new Error('unreadable'))
    }
    img.src = url
  })
}

async function validate(file: File): Promise<string | null> {
  const imagesOnly = acceptList.value.every((a) => a.startsWith('image/'))
  if (!matchesAccept(file)) return imagesOnly ? 'Format gambar yang diizinkan: JPG, JPEG, PNG, WebP.' : `Jenis file tidak didukung. Gunakan ${acceptLabel.value}.`
  if (file.size > props.maxSizeMb * 1024 * 1024) return `${imagesOnly ? 'Ukuran gambar' : 'Ukuran file'} maksimal ${props.maxSizeMb} MB.`
  if (isImage(file) && (props.minWidth || props.minHeight)) {
    try {
      const { w, h } = await readDimensions(file)
      if (w < props.minWidth || h < props.minHeight) return `Resolusi gambar minimal ${props.minWidth}×${props.minHeight}px.`
    } catch {
      return 'Gambar tidak dapat dibaca.'
    }
  }
  return null
}

async function addFiles(list: FileList | File[] | null | undefined): Promise<void> {
  if (!interactive.value || !list) return
  const incoming = Array.from(list)
  if (incoming.length === 0) return
  localError.value = null

  if (!multiple.value) {
    const first = incoming[0]!
    const err = await validate(first)
    if (err) return fail(err)
    emit('update:modelValue', [first])
    return
  }

  const next = [...props.modelValue]
  for (const file of incoming) {
    if (next.length >= props.maxFiles) return fail(`Maksimal ${props.maxFiles} file.`)
    const err = await validate(file)
    if (err) return fail(err)
    next.push(file)
  }
  emit('update:modelValue', next)
}

function fail(message: string): void {
  localError.value = message
  emit('invalid', message)
}

function onInputChange(event: Event): void {
  const el = event.target as HTMLInputElement
  void addFiles(el.files)
  el.value = ''
}

function onDrop(event: DragEvent): void {
  isDragging.value = false
  void addFiles(event.dataTransfer?.files)
}

function removeAt(index: number): void {
  localError.value = null
  emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
}

function previewFor(file: File): string | null {
  if (!isImage(file)) return null
  let url = previews.value.get(file)
  if (!url) {
    url = URL.createObjectURL(file)
    previews.value.set(file, url)
  }
  return url
}

function formatSize(bytes: number): string {
  return bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`
}

// Release object URLs for files that are no longer selected.
watch(
  () => props.modelValue,
  (files) => {
    for (const [file, url] of previews.value) {
      if (!files.includes(file)) {
        URL.revokeObjectURL(url)
        previews.value.delete(file)
      }
    }
  },
)
onBeforeUnmount(() => {
  previews.value.forEach((url) => URL.revokeObjectURL(url))
  previews.value.clear()
})

const statusMeta = {
  pending: { text: 'Menunggu verifikasi', cls: 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300' },
  verified: { text: 'Terverifikasi', cls: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300' },
  rejected: { text: 'Ditolak', cls: 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300' },
} as const

const showDropzone = computed(() => interactive.value && props.modelValue.length < props.maxFiles)
</script>

<template>
  <div class="space-y-2" :data-testid="testId">
    <label v-if="label" :for="inputId" class="block text-sm font-medium text-stone-700 dark:text-stone-300">
      {{ label }}<span v-if="required" class="text-brand-700" aria-hidden="true"> *</span>
    </label>

    <!-- Evidence already stored (historical files keep their URLs). -->
    <ul v-if="existing.length" class="space-y-2" data-testid="pfu-existing">
      <li
        v-for="(item, i) in existing"
        :key="`${item.name}-${i}`"
        class="flex items-start gap-3 rounded-xl border border-stone-200 bg-white p-2 dark:border-stone-700 dark:bg-stone-900"
      >
        <img v-if="item.url && /\.(jpe?g|png|webp|gif)(\?|$)/i.test(item.url)" :src="item.url" :alt="item.name" class="h-14 w-14 rounded-lg object-cover" />
        <AppIcon v-else name="box" :size="28" class="mt-1 text-stone-400" />
        <div class="min-w-0 flex-1 text-sm">
          <a v-if="item.url" :href="item.url" target="_blank" rel="noopener noreferrer" class="block truncate font-medium text-brand-700 hover:underline dark:text-brand-400">{{ item.name }}</a>
          <span v-else class="block truncate font-medium">{{ item.name }}</span>
          <span v-if="item.status" class="mt-1 inline-block rounded-full px-2 py-0.5 text-[11px] font-medium" :class="statusMeta[item.status].cls">{{ statusMeta[item.status].text }}</span>
          <p v-if="item.status === 'rejected' && item.rejectionReason" class="mt-1 text-xs text-red-700 dark:text-red-300">Alasan: {{ item.rejectionReason }}</p>
        </div>
      </li>
    </ul>

    <!-- Selected (not yet submitted) files with preview. -->
    <ul v-if="modelValue.length" class="space-y-2" data-testid="pfu-selected">
      <li
        v-for="(file, i) in modelValue"
        :key="`${file.name}-${file.size}-${i}`"
        class="flex items-center gap-3 rounded-xl border border-brand-200 bg-brand-50/40 p-2 dark:border-brand-900 dark:bg-brand-950/20"
      >
        <img v-if="previewFor(file)" :src="previewFor(file)!" :alt="file.name" class="h-16 w-16 rounded-lg object-cover" />
        <AppIcon v-else name="box" :size="32" class="text-stone-400" />
        <div class="min-w-0 flex-1 text-sm">
          <span class="block truncate font-medium text-stone-800 dark:text-stone-100">{{ file.name }}</span>
          <span class="text-xs text-stone-500">{{ formatSize(file.size) }}</span>
        </div>
        <button
          v-if="interactive"
          type="button"
          class="rounded-lg px-2 py-1 text-xs font-medium text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:text-red-300"
          :aria-label="`Hapus ${file.name}`"
          @click="removeAt(i)"
        >
          Hapus
        </button>
      </li>
    </ul>

    <!-- Dropzone: gallery picker + optional camera. Native inputs stay in the DOM (sr-only) for a11y. -->
    <div
      v-if="showDropzone"
      class="flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed p-4 text-center transition"
      :class="isDragging ? 'border-brand-600 bg-brand-50 dark:bg-brand-950/30' : 'border-stone-300 bg-stone-50 dark:border-stone-700 dark:bg-stone-900'"
      @dragover.prevent="isDragging = true"
      @dragleave.prevent="isDragging = false"
      @drop.prevent="onDrop"
    >
      <AppIcon name="box" :size="28" class="text-stone-400" />
      <p class="hidden text-sm text-stone-500 sm:block dark:text-stone-400">Seret file ke sini, atau</p>
      <div class="flex flex-wrap justify-center gap-2">
        <button
          type="button"
          class="rounded-lg bg-brand-700 px-3 py-2 text-sm font-medium text-white hover:bg-brand-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2"
          data-testid="pfu-pick"
          @click="inputRef?.click()"
        >
          {{ capture ? 'Pilih dari galeri' : 'Pilih file' }}
        </button>
        <button
          v-if="capture"
          type="button"
          class="rounded-lg border border-brand-700 px-3 py-2 text-sm font-medium text-brand-700 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:text-brand-300"
          data-testid="pfu-camera"
          @click="cameraRef?.click()"
        >
          Ambil foto
        </button>
      </div>
      <p class="text-xs text-stone-400">{{ hint || `Format: ${acceptLabel}. Maks ${maxSizeMb} MB${multiple ? `, hingga ${maxFiles} file` : ''}` }}</p>
    </div>

    <input
      :id="inputId"
      ref="inputRef"
      type="file"
      class="sr-only"
      :accept="accept"
      :multiple="multiple"
      :required="required && modelValue.length === 0 && existing.length === 0"
      :disabled="!interactive"
      data-testid="pfu-input"
      @change="onInputChange"
    />
    <input
      v-if="capture"
      ref="cameraRef"
      type="file"
      class="sr-only"
      accept="image/*"
      :capture="capture"
      tabindex="-1"
      :disabled="!interactive"
      data-testid="pfu-camera-input"
      @change="onInputChange"
    />

    <!-- Transport state: real % only when the parent measured it. -->
    <div v-if="uploading" class="space-y-1" role="status" aria-live="polite" data-testid="pfu-progress">
      <div class="h-2 overflow-hidden rounded-full bg-stone-200 dark:bg-stone-800">
        <div
          v-if="progress != null"
          class="h-full rounded-full bg-brand-700 transition-all"
          :style="{ width: `${Math.min(100, Math.max(0, progress))}%` }"
          :aria-valuenow="progress"
          role="progressbar"
        />
        <div v-else class="h-full w-1/3 animate-pulse rounded-full bg-brand-700" />
      </div>
      <div class="flex items-center justify-between text-xs text-stone-500">
        <span>{{ progress != null ? `Mengunggah… ${Math.round(progress)}%` : 'Mengunggah…' }}</span>
        <button type="button" class="font-medium text-red-700 hover:underline dark:text-red-300" data-testid="pfu-cancel" @click="emit('cancel')">Batalkan</button>
      </div>
    </div>

    <div v-if="shownError" class="flex items-center gap-2 text-sm text-red-600 dark:text-red-400" role="alert" data-testid="pfu-error">
      <span>{{ shownError }}</span>
      <button v-if="!uploading && modelValue.length" type="button" class="rounded border border-red-300 px-2 py-0.5 text-xs font-medium" data-testid="pfu-retry" @click="emit('retry')">Coba lagi</button>
    </div>
  </div>
</template>
