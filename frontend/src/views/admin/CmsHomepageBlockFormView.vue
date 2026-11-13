<script setup lang="ts">
import PrimeFileUpload from '@/components/ui/PrimeFileUpload.vue'
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { getBlock, createBlock, updateBlock } from '@/api/cmsAdmin'
import { ApiError } from '@/api/client'
import HomepageBlockRenderer from '@/components/cms/HomepageBlockRenderer.vue'
import { secureUuid } from '@/utils/uuid'

const BLOCK_TYPES = [
  { value: 'hero', label: 'Hero' },
  { value: 'banner', label: 'Banner' },
  { value: 'category', label: 'Kategori' },
  { value: 'product', label: 'Produk' },
  { value: 'bestseller', label: 'Produk terlaris' },
  { value: 'promotional', label: 'Promosi' },
  { value: 'text', label: 'Teks' },
  { value: 'image', label: 'Gambar' },
  { value: 'article', label: 'Artikel' },
  { value: 'cta', label: 'CTA' },
  { value: 'custom', label: 'Custom HTML' },
  { value: 'social_media', label: 'Media Sosial' },
  { value: 'whatsapp_chat', label: 'WhatsApp Chat' },
  { value: 'marketplace_links', label: 'Marketplace' },
  { value: 'banner_slider', label: 'Banner Slider' },
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

const form = reactive<Record<string, any>>({
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
  period: '30',
  layout: 'cards',
  mode: 'card',
  phone: '',
  message: '',
  instagram_url: '', instagram_enabled: true,
  tiktok_url: '', tiktok_enabled: true,
  facebook_url: '', facebook_enabled: true,
  tokopedia_url: '', tokopedia_enabled: true,
  shopee_url: '', shopee_enabled: true,
  // Hero / CTA presentation
  image_fit: 'cover',
  image_position: 'center',
  overlay: 'dark',
  align: 'left',
  theme: 'dark',
  // Banner slider
  slider_layout: 'contained',
  autoplay: true,
  interval_seconds: 5,
  show_arrows: true,
  show_dots: true,
})

const MAX_SLIDES = 10
const previewDevice = ref<'desktop' | 'mobile'>('desktop')

interface SlideDraft {
  key: string
  title: string
  description: string
  alt: string
  button_label: string
  button_url: string
  overlay: 'none' | 'light' | 'dark'
  content_position: 'left' | 'center' | 'right'
  desktop: File | null
  mobile: File | null
  desktopPreview: string | null
  mobilePreview: string | null
  existingDesktop: string | null
  existingMobile: string | null
  removeMobile: boolean
}

const slides = ref<SlideDraft[]>([])

function newSlide(): SlideDraft {
  return {
    key: 's' + secureUuid().replace(/-/g, '').slice(0, 15),
    title: '', description: '', alt: '', button_label: '', button_url: '',
    overlay: 'dark', content_position: 'left',
    desktop: null, mobile: null, desktopPreview: null, mobilePreview: null,
    existingDesktop: null, existingMobile: null, removeMobile: false,
  }
}

function addSlide() {
  if (slides.value.length < MAX_SLIDES) slides.value.push(newSlide())
}

function revokeSlide(slide: SlideDraft) {
  if (slide.desktopPreview) URL.revokeObjectURL(slide.desktopPreview)
  if (slide.mobilePreview) URL.revokeObjectURL(slide.mobilePreview)
}

function removeSlide(index: number) {
  const [removed] = slides.value.splice(index, 1)
  if (removed) revokeSlide(removed)
}

function moveSlide(index: number, delta: -1 | 1) {
  const target = index + delta
  if (target < 0 || target >= slides.value.length) return
  const [moved] = slides.value.splice(index, 1)
  slides.value.splice(target, 0, moved!)
}

function setSlideImage(slide: SlideDraft, which: 'desktop' | 'mobile', files: File[]) {
  const previewKey = which === 'desktop' ? 'desktopPreview' : 'mobilePreview'
  if (slide[previewKey]) URL.revokeObjectURL(slide[previewKey]!)
  const file = files[0] ?? null
  slide[which] = file
  slide[previewKey] = file ? URL.createObjectURL(file) : null
  if (which === 'mobile' && file) slide.removeMobile = false
}

function firstError(key: string): string | undefined {
  return errors.value[key]?.[0]
}

function slideImageError(slide: SlideDraft): boolean {
  return !slide.desktop && !slide.existingDesktop
}

const imageFile = ref<File | null>(null)
const removeImage = ref(false)
const existingImageUrl = ref<string | null>(null)
const localPreview = ref<string | null>(null)
const isDragging = ref(false)

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
    if (block.type === 'banner_slider' && !c.slides?.length) slides.value = [newSlide()]
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
    form.category_id = c.category_id != null ? String(c.category_id) : ''
    form.article_ids = (c.article_ids ?? []).join(',')
    form.period = c.period ?? '30'
    form.limit = c.limit != null ? String(c.limit) : ''
    form.layout = c.layout ?? 'cards'
    form.mode = c.mode ?? 'card'
    form.phone = c.phone ?? ''
    form.message = c.message ?? ''
    for (const key of ['instagram', 'tiktok', 'facebook', 'tokopedia', 'shopee'] as const) {
      form[`${key}_url`] = c.platforms?.[key]?.url ?? ''
      form[`${key}_enabled`] = c.platforms?.[key]?.enabled ?? true
    }
    existingImageUrl.value = block.image_url
    form.image_fit = c.image_fit ?? 'cover'
    form.image_position = c.image_position ?? 'center'
    form.overlay = c.overlay ?? 'dark'
    form.align = c.align ?? 'left'
    form.theme = c.theme ?? 'dark'
    if (block.type === 'banner_slider') {
      const on = (v: unknown, d: boolean) => (v === undefined || v === null ? d : !(v === false || v === 0 || v === '0' || v === 'false' || v === ''))
      form.slider_layout = c.layout === 'wide' ? 'wide' : 'contained'
      form.autoplay = on(c.autoplay, true)
      form.interval_seconds = Number(c.interval_seconds) || 5
      form.show_arrows = on(c.show_arrows, true)
      form.show_dots = on(c.show_dots, true)
      slides.value = (c.slides ?? []).map((sl) => ({
        ...newSlide(),
        key: sl.key,
        title: sl.title ?? '', description: sl.description ?? '', alt: sl.alt ?? '',
        button_label: sl.button_label ?? '', button_url: sl.button_url ?? '',
        overlay: sl.overlay ?? 'dark', content_position: sl.content_position ?? 'left',
        existingDesktop: sl.image_url ?? null, existingMobile: sl.mobile_image_url ?? null,
      }))
    }
  }

  loading.value = false
}
onMounted(load)
watch(() => form.type, (t) => {
  if (t === 'banner_slider' && !slides.value.length) addSlide()
})
onBeforeUnmount(() => {
  if (localPreview.value) URL.revokeObjectURL(localPreview.value)
  slides.value.forEach(revokeSlide)
})

function stageFile(file: File) {
  if (localPreview.value) URL.revokeObjectURL(localPreview.value)
  imageFile.value = file
  localPreview.value = URL.createObjectURL(file)
  removeImage.value = false
}

function onImageChange(files: File[]) {
  if (files[0]) stageFile(files[0])
  else {
    if (localPreview.value) URL.revokeObjectURL(localPreview.value)
    imageFile.value = null
    localPreview.value = null
  }
}

function clearImage() {
  if (localPreview.value) URL.revokeObjectURL(localPreview.value)
  imageFile.value = null
  localPreview.value = null
  removeImage.value = true
}

function normalizeWhatsappPhone(raw: string): string {
  // Backend requires normalized international digits (^[1-9][0-9]{7,14}$):
  // strip separators/signs and repair the common local prefix (08… → 628…).
  // Invalid shapes still fail validation server-side — never guessed away.
  const digits = raw.replace(/[^\d]/g, '')
  if (digits.startsWith('0')) return '62' + digits.slice(1)
  return digits
}

function buildContent(): Record<string, unknown> {
  const toIntArray = (s: string) => s.split(',').map((v) => v.trim()).filter(Boolean).map(Number)

  switch (form.type) {
    case 'hero':
      return {
        heading: form.heading, subheading: form.subheading || undefined, cta_label: form.cta_label || undefined, cta_url: form.cta_url || undefined,
        image_fit: form.image_fit, image_position: form.image_position, overlay: form.overlay, align: form.align,
      }
    case 'banner':
    case 'image':
      return { url: form.url || undefined, alt: form.alt || undefined }
    case 'category':
      return { heading: form.heading || undefined, category_ids: toIntArray(form.category_ids) }
    case 'bestseller':
      return { heading: form.heading || undefined, period: form.period, limit: form.limit ? Number(form.limit) : 8 }
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
      return { heading: form.heading, description: form.description, button_label: form.button_label, button_url: form.button_url, theme: form.theme, align: form.align }
    case 'banner_slider':
      return {
        layout: form.slider_layout,
        autoplay: form.autoplay,
        interval_seconds: Number(form.interval_seconds) || 5,
        show_arrows: form.show_arrows,
        show_dots: form.show_dots,
        slides: slides.value.map((sl) => ({
          key: sl.key,
          title: sl.title || undefined, description: sl.description || undefined, alt: sl.alt || undefined,
          button_label: sl.button_label || undefined, button_url: sl.button_url || undefined,
          overlay: sl.overlay, content_position: sl.content_position,
          remove_mobile: sl.removeMobile || undefined,
        })),
      }
    case 'custom':
      return { html: form.html }
    case 'social_media':
      return { heading: form.heading || undefined, description: form.description || undefined, layout: form.layout, platforms: {
        instagram: { enabled: form.instagram_enabled, url: form.instagram_url || undefined },
        tiktok: { enabled: form.tiktok_enabled, url: form.tiktok_url || undefined },
        facebook: { enabled: form.facebook_enabled, url: form.facebook_url || undefined },
      } }
    case 'whatsapp_chat':
      // Backend requires normalized international digits (^[1-9][0-9]{7,14}$):
      // strip separators/signs and repair a common local prefix (08… → 628…).
      // Invalid shapes still fail validation server-side — never guessed away.
      return { heading: form.heading || undefined, description: form.description || undefined, phone: normalizeWhatsappPhone(form.phone), message: form.message || undefined, button_label: form.button_label, mode: form.mode }
    case 'marketplace_links':
      return { heading: form.heading || undefined, description: form.description || undefined, layout: form.layout, platforms: {
        tokopedia: { enabled: form.tokopedia_enabled, url: form.tokopedia_url || undefined },
        shopee: { enabled: form.shopee_enabled, url: form.shopee_url || undefined },
      } }
    default:
      return {}
  }
}

const PREVIEW_TYPES = new Set(['hero', 'cta', 'banner_slider'])

/** The block exactly as the public page will receive it, with local (not yet uploaded) images. */
const previewBlock = computed(() => {
  if (!PREVIEW_TYPES.has(form.type)) return null
  const content: Record<string, any> = { ...buildContent() }
  if (form.type === 'hero') content.image_url = previewUrl.value ?? undefined
  if (form.type === 'banner_slider') {
    content.slides = slides.value.map((sl) => ({
      key: sl.key, title: sl.title, description: sl.description, alt: sl.alt,
      button_label: sl.button_label, button_url: sl.button_url,
      overlay: sl.overlay, content_position: sl.content_position,
      image_url: sl.desktopPreview ?? sl.existingDesktop,
      mobile_image_url: sl.removeMobile && !sl.mobile ? null : sl.mobilePreview ?? sl.existingMobile,
    }))
  }
  return { id: 0, type: form.type, content }
})

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
  } else if (typeof value === 'boolean') {
    // Laravel's `boolean` rule rejects the strings "true"/"false" (String(true)).
    fd.append(key, value ? '1' : '0')
  } else {
    fd.append(key, String(value))
  }
}

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  if (form.type === 'banner_slider') {
    const missing = slides.value.findIndex(slideImageError)
    if (!slides.value.length || missing !== -1) {
      generalError.value = slides.value.length ? `Slide ${missing + 1} belum memiliki gambar desktop.` : 'Tambahkan minimal satu slide.'
      submitting.value = false
      return
    }
  }

  const fd = new FormData()
  fd.append('type', form.type)
  appendNested(fd, 'content', buildContent())
  if (form.type === 'banner_slider') {
    for (const sl of slides.value) {
      if (sl.desktop) fd.append(`slide_images[${sl.key}][desktop]`, sl.desktop)
      if (sl.mobile) fd.append(`slide_images[${sl.key}][mobile]`, sl.mobile)
    }
  }
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

          <template v-if="['hero', 'promotional', 'text', 'category', 'product', 'bestseller', 'article', 'cta', 'social_media', 'whatsapp_chat', 'marketplace_links'].includes(form.type)">
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
              <input v-model="form.cta_url" type="text" placeholder="Tautan tombol (/path, https://…)" class="rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            </div>
            <p v-if="errors['content.cta_url']" class="mt-1 text-xs text-red-600">{{ errors['content.cta_url'][0] }}</p>
            <div class="mt-3 grid grid-cols-2 gap-2">
              <label class="text-xs font-medium text-stone-600 dark:text-stone-300">Tampilan gambar
                <select v-model="form.image_fit" class="mt-1 w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm font-normal dark:border-stone-700 dark:bg-stone-950">
                  <option value="cover">Penuhi banner (cover)</option>
                  <option value="contain">Tampilkan utuh (logo / contain)</option>
                </select>
              </label>
              <label class="text-xs font-medium text-stone-600 dark:text-stone-300">Posisi gambar
                <select v-model="form.image_position" class="mt-1 w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm font-normal dark:border-stone-700 dark:bg-stone-950">
                  <option value="center">Tengah</option><option value="top">Atas</option><option value="bottom">Bawah</option><option value="left">Kiri</option><option value="right">Kanan</option>
                </select>
              </label>
              <label class="text-xs font-medium text-stone-600 dark:text-stone-300">Overlay
                <select v-model="form.overlay" class="mt-1 w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm font-normal dark:border-stone-700 dark:bg-stone-950">
                  <option value="dark">Gelap (teks putih)</option><option value="light">Terang (teks gelap)</option><option value="none">Tanpa overlay</option>
                </select>
              </label>
              <label class="text-xs font-medium text-stone-600 dark:text-stone-300">Posisi teks
                <select v-model="form.align" class="mt-1 w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm font-normal dark:border-stone-700 dark:bg-stone-950">
                  <option value="left">Kiri</option><option value="center">Tengah</option>
                </select>
              </label>
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

          <div v-if="['social_media', 'whatsapp_chat', 'marketplace_links'].includes(form.type)" class="space-y-3">
            <div><label class="mb-1 block text-sm font-medium">Deskripsi</label><textarea v-model="form.description" rows="2" class="w-full rounded-lg border px-3 py-2 text-sm dark:bg-stone-950" /></div>
            <template v-if="form.type === 'social_media'">
              <select v-model="form.layout" class="w-full rounded-lg border px-3 py-2 dark:bg-stone-950"><option value="cards">3 kartu</option><option value="compact">Ikon horizontal</option></select>
              <div v-for="key in (['instagram','tiktok','facebook'] as const)" :key="key" class="flex items-center gap-2"><input v-model="form[`${key}_enabled`]" type="checkbox" /><span class="w-24 capitalize">{{ key }}</span><input v-model="form[`${key}_url`]" type="url" placeholder="https://..." class="flex-1 rounded-lg border px-3 py-2 dark:bg-stone-950" /></div>
            </template>
            <template v-else-if="form.type === 'whatsapp_chat'">
              <input v-model="form.phone" type="text" placeholder="628123456789" class="w-full rounded-lg border px-3 py-2 dark:bg-stone-950" />
              <textarea v-model="form.message" placeholder="Pesan awal" class="w-full rounded-lg border px-3 py-2 dark:bg-stone-950" />
              <input v-model="form.button_label" type="text" placeholder="Chat via WhatsApp" class="w-full rounded-lg border px-3 py-2 dark:bg-stone-950" />
              <select v-model="form.mode" class="w-full rounded-lg border px-3 py-2 dark:bg-stone-950"><option value="card">Kartu homepage</option><option value="floating">Tombol floating sitewide</option></select>
            </template>
            <template v-else>
              <select v-model="form.layout" class="w-full rounded-lg border px-3 py-2 dark:bg-stone-950"><option value="cards">Kartu</option><option value="horizontal">Tombol horizontal</option></select>
              <div v-for="key in (['tokopedia','shopee'] as const)" :key="key" class="flex items-center gap-2"><input v-model="form[`${key}_enabled`]" type="checkbox" /><span class="w-24 capitalize">{{ key }}</span><input v-model="form[`${key}_url`]" type="url" placeholder="https://..." class="flex-1 rounded-lg border px-3 py-2 dark:bg-stone-950" /></div>
            </template>
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

          <div v-if="form.type === 'bestseller'" class="space-y-2">
            <label class="block text-sm">Periode
              <select v-model="form.period" class="w-full rounded-lg border p-2">
                <option value="7">7 hari</option><option value="30">30 hari</option><option value="90">90 hari</option><option value="all">Sepanjang waktu</option>
              </select>
            </label>
            <label class="block text-sm">Jumlah produk
              <select v-model="form.limit" class="w-full rounded-lg border p-2">
                <option value="4">4</option><option value="">8</option><option value="12">12</option>
              </select>
            </label>
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
            <input v-model="form.button_url" type="text" placeholder="Tautan tombol (/path, https://…)" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            <p v-if="errors['content.button_url']" class="mt-1 text-xs text-red-600">{{ errors['content.button_url'][0] }}</p>
            <textarea v-model="form.description" rows="2" maxlength="300" placeholder="Deskripsi singkat (opsional)" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            <div class="grid grid-cols-2 gap-2">
              <label class="text-xs font-medium text-stone-600 dark:text-stone-300">Warna
                <select v-model="form.theme" class="mt-1 w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm font-normal dark:border-stone-700 dark:bg-stone-950">
                  <option value="brand">Brand (burgundy)</option><option value="dark">Gelap</option><option value="light">Terang</option><option value="sand">Sand</option>
                </select>
              </label>
              <label class="text-xs font-medium text-stone-600 dark:text-stone-300">Perataan
                <select v-model="form.align" class="mt-1 w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm font-normal dark:border-stone-700 dark:bg-stone-950">
                  <option value="left">Kiri</option><option value="center">Tengah</option>
                </select>
              </label>
            </div>
          </div>

          <div v-if="form.type === 'banner_slider'" class="space-y-4" data-testid="slider-editor">
            <div class="grid grid-cols-2 gap-2">
              <label class="text-xs font-medium text-stone-600 dark:text-stone-300">Layout
                <select v-model="form.slider_layout" class="mt-1 w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm font-normal dark:border-stone-700 dark:bg-stone-950">
                  <option value="contained">Contained (terpusat)</option><option value="wide">Wide (selebar halaman)</option>
                </select>
              </label>
              <label class="text-xs font-medium text-stone-600 dark:text-stone-300">Interval (detik, 3–10)
                <input v-model.number="form.interval_seconds" type="number" min="3" max="10" step="1" class="mt-1 w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm font-normal dark:border-stone-700 dark:bg-stone-950" />
              </label>
            </div>
            <p v-if="errors['content.interval_seconds']" class="text-xs text-red-600">{{ errors['content.interval_seconds'][0] }}</p>
            <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
              <label class="flex items-center gap-2"><input v-model="form.autoplay" type="checkbox" /> Autoplay</label>
              <label class="flex items-center gap-2"><input v-model="form.show_arrows" type="checkbox" /> Tombol panah</label>
              <label class="flex items-center gap-2"><input v-model="form.show_dots" type="checkbox" /> Indikator titik</label>
            </div>

            <p v-if="errors['content.slides']" class="text-xs text-red-600">{{ errors['content.slides'][0] }}</p>

            <ol class="space-y-4">
              <li v-for="(slide, i) in slides" :key="slide.key" class="rounded-xl border border-stone-200 p-4 dark:border-stone-700" :data-testid="`slide-${i}`">
                <div class="mb-3 flex items-center justify-between gap-2">
                  <h3 class="text-sm font-semibold text-stone-800 dark:text-stone-100">Slide {{ i + 1 }}</h3>
                  <div class="flex items-center gap-1">
                    <button type="button" class="rounded-lg border border-stone-200 px-2 py-1 text-xs disabled:opacity-40 dark:border-stone-700" :disabled="i === 0" :aria-label="`Naikkan slide ${i + 1}`" @click="moveSlide(i, -1)">&uarr;</button>
                    <button type="button" class="rounded-lg border border-stone-200 px-2 py-1 text-xs disabled:opacity-40 dark:border-stone-700" :disabled="i === slides.length - 1" :aria-label="`Turunkan slide ${i + 1}`" @click="moveSlide(i, 1)">&darr;</button>
                    <button type="button" class="rounded-lg border border-red-200 px-2 py-1 text-xs text-red-700 dark:border-red-900 dark:text-red-400" :aria-label="`Hapus slide ${i + 1}`" @click="removeSlide(i)">Hapus</button>
                  </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                  <div>
                    <PrimeFileUpload
                      :model-value="slide.desktop ? [slide.desktop] : []"
                      label="Gambar desktop (wajib)"
                      hint="Disarankan rasio 21:9 (mis. 2100×900). Maks 10 MB."
                      accept="image/jpeg,image/png,image/webp"
                      :max-size-mb="10"
                      :existing="slide.existingDesktop && !slide.desktop ? [{ url: slide.existingDesktop, name: 'Gambar saat ini' }] : []"
                      :test-id="`slide-${i}-desktop`"
                      @update:model-value="(f: File[]) => setSlideImage(slide, 'desktop', f)"
                    />
                    <p v-if="slideImageError(slide)" class="mt-1 text-xs text-amber-700">Gambar desktop wajib diisi.</p>
                    <p v-if="firstError(`slide_images.${slide.key}.desktop`)" class="mt-1 text-xs text-red-600">{{ firstError(`slide_images.${slide.key}.desktop`) }}</p>
                  </div>
                  <div>
                    <PrimeFileUpload
                      :model-value="slide.mobile ? [slide.mobile] : []"
                      label="Gambar mobile (opsional)"
                      hint="Disarankan rasio 4:3 (mis. 1200×900). Tanpa gambar mobile, gambar desktop ditampilkan utuh di HP."
                      accept="image/jpeg,image/png,image/webp"
                      :max-size-mb="10"
                      :existing="slide.existingMobile && !slide.mobile && !slide.removeMobile ? [{ url: slide.existingMobile, name: 'Gambar mobile saat ini' }] : []"
                      :test-id="`slide-${i}-mobile`"
                      @update:model-value="(f: File[]) => setSlideImage(slide, 'mobile', f)"
                    />
                    <button v-if="slide.existingMobile && !slide.mobile && !slide.removeMobile" type="button" class="mt-1 text-xs font-medium text-red-700 hover:underline" @click="slide.removeMobile = true">Hapus gambar mobile</button>
                    <p v-if="firstError(`slide_images.${slide.key}.mobile`)" class="mt-1 text-xs text-red-600">{{ firstError(`slide_images.${slide.key}.mobile`) }}</p>
                  </div>
                </div>

                <div class="mt-3 space-y-2">
                  <input v-model="slide.title" type="text" maxlength="150" placeholder="Judul (opsional)" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
                  <textarea v-model="slide.description" rows="2" maxlength="300" placeholder="Deskripsi (opsional)" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
                  <input v-model="slide.alt" type="text" maxlength="150" placeholder="Teks alternatif gambar (aksesibilitas)" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
                  <div class="grid grid-cols-2 gap-2">
                    <input v-model="slide.button_label" type="text" maxlength="50" placeholder="Label tombol (opsional)" class="rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
                    <input v-model="slide.button_url" type="text" placeholder="Tautan (/path, https://…)" class="rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
                  </div>
                  <p v-if="firstError(`content.slides.${i}.button_url`)" class="text-xs text-red-600">{{ firstError(`content.slides.${i}.button_url`) }}</p>
                  <div class="grid grid-cols-2 gap-2">
                    <label class="text-xs font-medium text-stone-600 dark:text-stone-300">Posisi konten
                      <select v-model="slide.content_position" class="mt-1 w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm font-normal dark:border-stone-700 dark:bg-stone-950">
                        <option value="left">Kiri</option><option value="center">Tengah</option><option value="right">Kanan</option>
                      </select>
                    </label>
                    <label class="text-xs font-medium text-stone-600 dark:text-stone-300">Overlay
                      <select v-model="slide.overlay" class="mt-1 w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm font-normal dark:border-stone-700 dark:bg-stone-950">
                        <option value="dark">Gelap</option><option value="light">Terang</option><option value="none">Tanpa overlay</option>
                      </select>
                    </label>
                  </div>
                </div>
              </li>
            </ol>

            <button type="button" class="w-full rounded-xl border border-dashed border-stone-300 px-4 py-3 text-sm font-medium text-stone-700 hover:bg-stone-50 disabled:opacity-50 dark:border-stone-600 dark:text-stone-200 dark:hover:bg-stone-800" :disabled="slides.length >= MAX_SLIDES" data-testid="add-slide" @click="addSlide">
              + Tambah slide ({{ slides.length }}/{{ MAX_SLIDES }})
            </button>
          </div>

          <div v-if="IMAGE_TYPES.has(form.type)" class="space-y-2">
            <PrimeFileUpload
              :model-value="imageFile ? [imageFile] : []"
              label="Gambar"
              accept="image/jpeg,image/png,image/webp"
              :max-size-mb="10"
              :existing="existingImageUrl && !removeImage && !imageFile ? [{ url: existingImageUrl, name: 'Gambar saat ini' }] : []"
              test-id="cms-block-image-upload"
              @update:model-value="onImageChange"
            />
            <button v-if="existingImageUrl && !removeImage && !imageFile" type="button" class="text-xs font-medium text-red-700 hover:underline" @click="clearImage">Hapus gambar</button>
            <p v-if="errors.image" class="text-xs text-red-600">{{ errors.image[0] }}</p>
          </div>
        </div>

        <section v-if="previewBlock" class="mt-6 rounded-xl border border-dashed border-stone-300 p-3 dark:border-stone-700" aria-label="Pratinjau" data-testid="block-preview">
          <div class="mb-2 flex items-center justify-between gap-2">
            <p class="text-xs font-medium uppercase tracking-wide text-stone-500">Pratinjau</p>
            <div class="inline-flex rounded-lg border border-stone-200 p-0.5 text-xs dark:border-stone-700" role="group" aria-label="Perangkat pratinjau">
              <button type="button" class="rounded-md px-3 py-1 font-medium" :class="previewDevice === 'desktop' ? 'bg-stone-900 text-white dark:bg-stone-100 dark:text-stone-900' : 'text-stone-600 dark:text-stone-300'" :aria-pressed="previewDevice === 'desktop'" data-testid="preview-desktop" @click="previewDevice = 'desktop'">Desktop</button>
              <button type="button" class="rounded-md px-3 py-1 font-medium" :class="previewDevice === 'mobile' ? 'bg-stone-900 text-white dark:bg-stone-100 dark:text-stone-900' : 'text-stone-600 dark:text-stone-300'" :aria-pressed="previewDevice === 'mobile'" data-testid="preview-mobile" @click="previewDevice = 'mobile'">Mobile (390 px)</button>
            </div>
          </div>
          <!-- The block lays itself out by the width of this frame, so the 390px frame is a real phone layout. -->
          <div :class="previewDevice === 'mobile' ? 'mx-auto w-[390px] max-w-full rounded-2xl border border-stone-200 p-2 dark:border-stone-700' : ''" data-testid="preview-frame">
            <HomepageBlockRenderer :block="previewBlock as any" :preview-device="previewDevice" />
          </div>
        </section>

        <AppButton type="submit" size="lg" block class="mt-6" :disabled="submitting">
          {{ submitting ? 'Menyimpan...' : 'Simpan' }}
        </AppButton>
      </form>
    </div>
  </DashboardLayout>
</template>
