<script setup lang="ts">
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { getBlock, createBlock, updateBlock } from '@/api/cmsAdmin'
import { ApiError } from '@/api/client'

const BLOCK_TYPES = [
  { value: 'hero', label: 'Hero' },
  { value: 'banner', label: 'Banner' },
  { value: 'category', label: 'Kategori' },
  { value: 'product', label: 'Produk' },
  { value: 'promotional', label: 'Promosi' },
  { value: 'text', label: 'Teks' },
  { value: 'image', label: 'Gambar' },
  { value: 'article', label: 'Artikel' },
  { value: 'cta', label: 'CTA' },
  { value: 'custom', label: 'Custom HTML' },
] as const

const IMAGE_TYPES = new Set(['hero', 'banner', 'promotional', 'image'])

const route = useRoute()
const router = useRouter()
const editingId = computed(() => (route.params.id ? Number(route.params.id) : null))

const loading = ref(true)
const notFound = ref(false)
const submitting = ref(false)
const generalError = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})

const form = reactive({
  type: 'text' as string,
  heading: '',
  subheading: '',
  body: '',
  cta_label: '',
  cta_url: '',
  url: '',
  description: '',
  alt: '',
  button_label: '',
  button_url: '',
  html: '',
  category_ids: '', // comma-separated
  product_ids: '',
  category_id: '',
  article_ids: '',
  limit: '',
})

const imageFile = ref<File | null>(null)
const removeImage = ref(false)
const existingImageUrl = ref<string | null>(null)
const localPreview = ref<string | null>(null)
const isDragging = ref(false)
const fileInputRef = ref<HTMLInputElement | null>(null)

const previewUrl = computed(() => localPreview.value ?? (removeImage.value ? null : existingImageUrl.value))

async function load() {
  loading.value = true

  if (editingId.value) {
    const block = await getBlock(editingId.value)
    if (!block) {
      notFound.value = true
      loading.value = false
      return
    }
    form.type = block.type
    const c = block.content ?? {}
    form.heading = c.heading ?? ''
    form.subheading = c.subheading ?? ''
    form.body = c.body ?? ''
    form.cta_label = c.cta_label ?? ''
    form.cta_url = c.cta_url ?? ''
    form.url = c.url ?? ''
    form.description = c.description ?? ''
    form.alt = c.alt ?? ''
    form.button_label = c.button_label ?? ''
    form.button_url = c.button_url ?? ''
    form.html = c.html ?? ''
    form.category_ids = (c.category_ids ?? []).join(',')
    form.product_ids = (c.product_ids ?? []).join(',')
    form.category_id = c.category_id ?? ''
    form.article_ids = (c.article_ids ?? []).join(',')
    form.limit = c.limit ?? ''
    existingImageUrl.value = block.image_url
  }

  loading.value = false
}
onMounted(load)
onBeforeUnmount(() => {
  if (localPreview.value) URL.revokeObjectURL(localPreview.value)
})

function stageFile(file: File) {
  if (localPreview.value) URL.revokeObjectURL(localPreview.value)
  imageFile.value = file
  localPreview.value = URL.createObjectURL(file)
  removeImage.value = false
}

function onFileChange(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (file) stageFile(file)
}

function onDrop(e: DragEvent) {
  isDragging.value = false
  const file = e.dataTransfer?.files?.[0]
  if (file) stageFile(file)
}

function clearImage() {
  if (localPreview.value) URL.revokeObjectURL(localPreview.value)
  imageFile.value = null
  localPreview.value = null
  removeImage.value = true
  if (fileInputRef.value) fileInputRef.value.value = ''
}

function buildContent(): Record<string, unknown> {
  const toIntArray = (s: string) => s.split(',').map((v) => v.trim()).filter(Boolean).map(Number)

  switch (form.type) {
    case 'hero':
      return { heading: form.heading, subheading: form.subheading || undefined, cta_label: form.cta_label || undefined, cta_url: form.cta_url || undefined }
    case 'banner':
    case 'image':
      return { url: form.url || undefined, alt: form.alt || undefined }
    case 'category':
      return { heading: form.heading || undefined, category_ids: toIntArray(form.category_ids) }
    case 'product':
      return {
        heading: form.heading || undefined,
        category_id: form.category_id ? Number(form.category_id) : undefined,
        product_ids: form.product_ids ? toIntArray(form.product_ids) : undefined,
        limit: form.limit ? Number(form.limit) : undefined,
      }
    case 'promotional':
      return { heading: form.heading, description: form.description || undefined, url: form.url || undefined }
    case 'text':
      return { heading: form.heading || undefined, body: form.body }
    case 'article':
      return { heading: form.heading || undefined, article_ids: toIntArray(form.article_ids) }
    case 'cta':
      return { heading: form.heading, button_label: form.button_label, button_url: form.button_url }
    case 'custom':
      return { html: form.html }
    default:
      return {}
  }
}

/**
 * multipart/form-data has no native nested-object syntax — a single field
 * holding a JSON string is never parsed back into an array by Laravel's
 * validator, so `content.heading` etc. would always read as "missing".
 * PHP-style bracket keys (content[heading], content[category_ids][0], ...)
 * are what Laravel actually understands as nested request data.
 */
function appendNested(fd: FormData, key: string, value: unknown): void {
  if (value === undefined || value === null) return
  if (Array.isArray(value)) {
    value.forEach((v, i) => appendNested(fd, `${key}[${i}]`, v))
  } else if (typeof value === 'object') {
    Object.entries(value as Record<string, unknown>).forEach(([k, v]) => appendNested(fd, `${key}[${k}]`, v))
  } else {
    fd.append(key, String(value))
  }
}

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  const fd = new FormData()
  fd.append('type', form.type)
  appendNested(fd, 'content', buildContent())
  if (imageFile.value) fd.append('image', imageFile.value)
  if (removeImage.value) fd.append('remove_image', '1')

  try {
    if (editingId.value) {
      await updateBlock(editingId.value, fd)
    } else {
      await createBlock(fd)
    }
    router.push({ name: 'admin-homepage-blocks' })
  } catch (e) {
    if (e instanceof ApiError) {
      generalError.value = e.message
      errors.value = e.errors ?? {}
    } else {
      generalError.value = 'Gagal menyimpan block.'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <DashboardLayout>
    <div class="mx-auto max-w-2xl px-4 py-8">
      <div class="mb-6 flex items-center justify-between">
        <h1 class="text-xl font-semibold text-stone-900 dark:text-stone-50">
          {{ editingId ? 'Edit Block' : 'Tambah Block' }}
        </h1>
        <router-link :to="{ name: 'admin-homepage-blocks' }" class="text-sm text-stone-500 hover:underline dark:text-stone-400">
          &larr; Kembali ke daftar
        </router-link>
      </div>

      <div v-if="loading" class="text-sm text-stone-500">Memuat...</div>
      <div v-else-if="notFound" class="rounded-xl bg-red-50 p-6 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">
        Block tidak ditemukan.
      </div>

      <form v-else class="rounded-2xl border border-stone-200 bg-white p-6 dark:border-stone-800 dark:bg-stone-900" @submit.prevent="submit">
        <p v-if="generalError" class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-400">{{ generalError }}</p>

        <div class="space-y-3">
          <div>
            <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Tipe Block</label>
            <select v-model="form.type" :disabled="!!editingId" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm disabled:opacity-60 dark:border-stone-700 dark:bg-stone-950">
              <option v-for="t in BLOCK_TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
          </div>

          <template v-if="['hero', 'promotional', 'text', 'category', 'product', 'article', 'cta'].includes(form.type)">
            <div>
              <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Judul</label>
              <input v-model="form.heading" type="text" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
              <p v-if="errors['content.heading']" class="mt-1 text-xs text-red-600">{{ errors['content.heading'][0] }}</p>
            </div>
          </template>

          <div v-if="form.type === 'hero'">
            <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Sub-judul</label>
            <input v-model="form.subheading" type="text" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            <div class="mt-2 grid grid-cols-2 gap-2">
              <input v-model="form.cta_label" type="text" placeholder="Label tombol" class="rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
              <input v-model="form.cta_url" type="text" placeholder="Tautan tombol" class="rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            </div>
          </div>

          <div v-if="form.type === 'text'">
            <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Isi</label>
            <textarea v-model="form.body" rows="4" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            <p v-if="errors['content.body']" class="mt-1 text-xs text-red-600">{{ errors['content.body'][0] }}</p>
          </div>

          <div v-if="form.type === 'custom'">
            <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">HTML (akan disaring server-side)</label>
            <textarea v-model="form.html" rows="5" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 font-mono text-xs dark:border-stone-700 dark:bg-stone-950" />
          </div>

          <div v-if="form.type === 'promotional'">
            <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Deskripsi</label>
            <textarea v-model="form.description" rows="2" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </div>

          <div v-if="['banner', 'image', 'promotional'].includes(form.type)">
            <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Tautan (opsional)</label>
            <input v-model="form.url" type="text" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </div>

          <div v-if="form.type === 'image'">
            <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Teks alternatif</label>
            <input v-model="form.alt" type="text" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </div>

          <div v-if="form.type === 'category'">
            <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">ID Kategori (pisahkan koma)</label>
            <input v-model="form.category_ids" type="text" placeholder="1,2,3" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            <p v-if="errors['content.category_ids']" class="mt-1 text-xs text-red-600">{{ errors['content.category_ids'][0] }}</p>
          </div>

          <div v-if="form.type === 'product'" class="space-y-2">
            <div>
              <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">ID Kategori (opsional — ambil produk dari kategori ini)</label>
              <input v-model="form.category_id" type="text" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Atau ID Produk spesifik (pisahkan koma)</label>
              <input v-model="form.product_ids" type="text" placeholder="1,2,3" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Jumlah maksimal</label>
              <input v-model="form.limit" type="number" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            </div>
          </div>

          <div v-if="form.type === 'article'">
            <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">ID Artikel (pisahkan koma)</label>
            <input v-model="form.article_ids" type="text" placeholder="1,2,3" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </div>

          <div v-if="form.type === 'cta'" class="space-y-2">
            <input v-model="form.button_label" type="text" placeholder="Label tombol" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            <input v-model="form.button_url" type="text" placeholder="Tautan tombol" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </div>

          <div v-if="IMAGE_TYPES.has(form.type)" class="space-y-2">
            <label class="block text-sm font-medium text-stone-700 dark:text-stone-200">Gambar</label>
            <div
              class="relative flex min-h-40 flex-col items-center justify-center rounded-xl border-2 border-dashed p-4 transition"
              :class="isDragging ? 'border-brand-500 bg-brand-50 dark:bg-brand-950/30' : 'border-stone-300 bg-stone-50 dark:border-stone-700 dark:bg-stone-900'"
              @dragover.prevent="isDragging = true"
              @dragleave.prevent="isDragging = false"
              @drop.prevent="onDrop"
            >
              <template v-if="previewUrl">
                <img :src="previewUrl" alt="Gambar block" class="max-h-48 rounded-lg object-contain" />
                <div class="mt-3 flex gap-2">
                  <button type="button" class="inline-flex items-center gap-1.5 rounded-lg bg-stone-200 px-3 py-1.5 text-sm font-medium text-stone-800 hover:bg-stone-300 dark:bg-stone-800 dark:text-stone-100 dark:hover:bg-stone-700" @click="fileInputRef?.click()">
                    <AppIcon name="plus" :size="16" /> Ganti
                  </button>
                  <button type="button" class="inline-flex items-center gap-1.5 rounded-lg bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100 dark:bg-red-950/40 dark:text-red-300" @click="clearImage">
                    <AppIcon name="trash" :size="16" /> Hapus
                  </button>
                </div>
              </template>
              <template v-else>
                <AppIcon name="box" :size="32" class="text-stone-400" />
                <p class="mt-2 text-center text-sm text-stone-500 dark:text-stone-400">
                  Seret gambar ke sini, atau
                  <button type="button" class="font-medium text-brand-600 hover:underline dark:text-brand-400" @click="fileInputRef?.click()">pilih file</button>
                </p>
                <p class="mt-1 text-xs text-stone-400">JPG/PNG/WEBP, maks 2MB</p>
              </template>
            </div>
            <input ref="fileInputRef" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onFileChange" />
            <p v-if="errors.image" class="text-xs text-red-600">{{ errors.image[0] }}</p>
          </div>
        </div>

        <AppButton type="submit" size="lg" block class="mt-6" :disabled="submitting">
          {{ submitting ? 'Menyimpan...' : 'Simpan' }}
        </AppButton>
      </form>
    </div>
  </DashboardLayout>
</template>
