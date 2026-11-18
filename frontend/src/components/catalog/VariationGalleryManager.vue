<script setup lang="ts">
/**
 * REM-02/03 — manages ONE gallery scope of a product: the parent gallery (variationId = null) or a
 * single variation's gallery. It only ever receives and mutates the images of its own scope, so
 * editing variation A can never touch variation B. Every action calls a dedicated endpoint
 * (upload / primary / reorder / delete) and then asks the parent to reload the persisted state —
 * nothing is re-uploaded implicitly and nothing is mutated optimistically.
 */
import { computed, ref } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { ApiError } from '@/api/client'
import { deleteProductImage, reorderProductImages, setPrimaryProductImage, uploadProductImage } from '@/api/catalog'
import type { ProductImage } from '@/api/types'
import { formatApiError } from '@/utils/apiError'

const props = defineProps<{
  productId: number
  variationId: number | null
  images: ProductImage[]
  title: string
}>()
const emit = defineEmits<{ changed: [] }>()

const busy = ref(false)
const error = ref('')
const input = ref<HTMLInputElement | null>(null)
const ordered = computed(() => [...props.images].sort((a, b) => a.sort_order - b.sort_order || a.id - b.id))
const inputId = computed(() => `gallery-upload-${props.variationId ?? 'parent'}`)

async function run(action: () => Promise<unknown>, failure: string) {
  busy.value = true
  error.value = ''
  try {
    await action()
    emit('changed')
  } catch (e) {
    error.value = e instanceof ApiError ? formatApiError(e) : failure
    emit('changed') // re-sync with the persisted state after a failed/partial action
  } finally {
    busy.value = false
  }
}

async function onFiles(ev: Event) {
  const el = ev.target as HTMLInputElement
  const files = Array.from(el.files ?? []).filter((f) => f.type.startsWith('image/'))
  el.value = ''
  if (!files.length) return
  await run(async () => {
    let first = ordered.value.length === 0
    for (const file of files) {
      await uploadProductImage(props.productId, file, { product_variation_id: props.variationId, is_primary: first })
      first = false
    }
  }, 'Gagal mengunggah gambar.')
}
const makePrimary = (img: ProductImage) => run(() => setPrimaryProductImage(props.productId, img.id), 'Gagal mengubah gambar utama.')
const remove = (img: ProductImage) => run(() => deleteProductImage(props.productId, img.id), 'Gagal menghapus gambar.')
function move(index: number, delta: -1 | 1) {
  const ids = ordered.value.map((i) => i.id)
  const target = index + delta
  if (target < 0 || target >= ids.length) return
  ;[ids[index], ids[target]] = [ids[target]!, ids[index]!]
  return run(() => reorderProductImages(props.productId, props.variationId, ids), 'Gagal mengubah urutan gambar.')
}
</script>

<template>
  <section :aria-label="`Galeri ${title}`" class="rounded-xl border border-stone-100 p-3 dark:border-stone-800" :data-testid="`gallery-${variationId ?? 'parent'}`">
    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
      <h4 class="text-sm font-medium text-stone-700 dark:text-stone-200">{{ title }} <span class="text-xs text-stone-400">({{ ordered.length }} gambar)</span></h4>
      <label :for="inputId" class="cursor-pointer rounded-lg bg-stone-100 px-2.5 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-200 dark:bg-stone-800 dark:text-stone-200" :class="{ 'pointer-events-none opacity-50': busy }">
        + Tambah gambar
      </label>
      <input :id="inputId" ref="input" type="file" class="sr-only" multiple accept="image/jpeg,image/png,image/webp" :disabled="busy" data-testid="gallery-input" @change="onFiles" />
    </div>
    <p v-if="error" class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950 dark:text-red-300" role="alert" data-testid="gallery-error">{{ error }}</p>
    <p v-if="ordered.length === 0" class="text-xs text-stone-400" data-testid="gallery-empty">Belum ada gambar.</p>
    <ul class="grid grid-cols-3 gap-2 sm:grid-cols-4">
      <li v-for="(img, i) in ordered" :key="img.id" class="relative overflow-hidden rounded-lg border border-stone-200 dark:border-stone-700" data-testid="gallery-item" :data-image-id="img.id">
        <img :src="img.url" :alt="`${title} ${i + 1}`" class="h-24 w-full object-cover" />
        <span v-if="img.is_primary" class="absolute left-1.5 top-1.5 rounded-full bg-brand-600 px-2 py-0.5 text-[10px] font-medium text-white" data-testid="gallery-primary">Utama</span>
        <div class="absolute inset-x-0 bottom-0 flex items-center justify-between gap-1 bg-black/60 p-1">
          <span class="flex gap-0.5">
            <button type="button" class="rounded bg-white/90 px-1.5 py-0.5 text-[10px] font-medium text-stone-700 disabled:opacity-40" :disabled="busy || i === 0" :aria-label="`Geser gambar ${i + 1} ke kiri`" data-testid="gallery-left" @click="move(i, -1)">←</button>
            <button type="button" class="rounded bg-white/90 px-1.5 py-0.5 text-[10px] font-medium text-stone-700 disabled:opacity-40" :disabled="busy || i === ordered.length - 1" :aria-label="`Geser gambar ${i + 1} ke kanan`" data-testid="gallery-right" @click="move(i, 1)">→</button>
          </span>
          <span class="flex gap-0.5">
            <button v-if="!img.is_primary" type="button" class="rounded bg-white/90 px-1.5 py-0.5 text-[10px] font-medium text-stone-700 disabled:opacity-40" :disabled="busy" data-testid="gallery-make-primary" @click="makePrimary(img)">Utama</button>
            <button type="button" class="rounded bg-red-600 px-1.5 py-0.5 text-white disabled:opacity-40" :disabled="busy" :aria-label="`Hapus gambar ${i + 1}`" data-testid="gallery-remove" @click="remove(img)"><AppIcon name="trash" :size="11" /></button>
          </span>
        </div>
      </li>
    </ul>
  </section>
</template>
