<script setup lang="ts">
/**
 * Product create/edit — standard e-commerce single-page form: basic info,
 * image gallery (multi-upload with previews + default image), and optional
 * variations, all on one page (no "Fisik/Digital" product-type choice —
 * every product here ships physically).
 *
 * Stock/variations/images all need a real product_id server-side. On
 * CREATE, everything is staged in memory and flushed in order right after
 * the product itself is created (single "Simpan Produk" click). On EDIT,
 * basic info + stock save together via the same button; images/variants
 * (which already have their own persisted identity) act immediately per
 * action, same as any CMS media/variant panel.
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import RichTextEditor from '@/components/ui/RichTextEditor.vue'
import { useAuthStore } from '@/stores/auth'
import {
  listCategories,
  listProducts,
  createProduct,
  updateProduct,
  createVariation,
  updateVariation,
  deleteVariation,
  uploadProductImage,
  deleteProductImage,
  setPrimaryProductImage,
  getProductFees,
  setProductFees,
  getVariationFees,
  setVariationFees,
} from '@/api/catalog'
import { adjustStock } from '@/api/stock'
import type { Category, Product, ProductVariation } from '@/api/types'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

/** "Stok hanya untuk agen dan admin di bawah jaringan agen tersebut" — super_admin never sees a stock control here. */
const canManageStock = computed(() => auth.user?.role === 'agen' || auth.user?.role === 'admin')
/** Fee (agen/sales/kurir) configuration — write access is super_admin/agen only (see ProductPolicy::manage). */
const canManageFees = computed(() => auth.user?.role === 'super_admin' || auth.user?.role === 'agen')

const productId = computed(() => (route.params.id ? Number(route.params.id) : null))
const isEditing = computed(() => productId.value !== null)

const loading = ref(true)
const categories = ref<Category[]>([])
const product = ref<Product | null>(null)

const form = reactive({
  sku: '',
  category_id: null as number | null,
  name: '',
  short_description: '',
  description: '' as string | null,
  status: 'draft' as 'draft' | 'active' | 'inactive',
  base_price: 0,
  weight_grams: 0,
  stock: 0,
  agent_fee: 0,
  sales_fee: 0,
  courier_fee: 0,
})
const currentStock = ref(0) // baseline to diff against on save (edit mode only)

const formError = ref('')
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)

/* ---------- Variants: staged (create) vs persisted (edit) ---------- */
interface AttributeRow { key: string; value: string }
interface StagedVariant {
  sku: string; price: number; weight_grams: number; stock: number; attributes: AttributeRow[]
  agent_fee: number; sales_fee: number; courier_fee: number
}
const showVariantBuilder = ref(false)
const stagedVariants = ref<StagedVariant[]>([])
/** create-mode only: as soon as one variant is staged, base price/weight/stock become irrelevant (backend prohibits them together). */
const willHaveVariations = computed(() => !isEditing.value && stagedVariants.value.length > 0)
const hasVariations = computed(() => (isEditing.value ? !!product.value?.has_variations : willHaveVariations.value))

function blankVariant(): StagedVariant {
  return {
    sku: '', price: 0, weight_grams: 0, stock: 0, attributes: [{ key: '', value: '' }],
    agent_fee: 0, sales_fee: 0, courier_fee: 0,
  }
}
const newVariant = ref<StagedVariant>(blankVariant())

function addAttributeRow(target: StagedVariant) {
  target.attributes.push({ key: '', value: '' })
}
function removeAttributeRow(target: StagedVariant, index: number) {
  target.attributes.splice(index, 1)
}
function attributesToMap(rows: AttributeRow[]): Record<string, string> {
  const map: Record<string, string> = {}
  for (const row of rows) {
    if (row.key.trim() && row.value.trim()) map[row.key.trim()] = row.value.trim()
  }
  return map
}

function stageVariant() {
  variationError.value = ''
  if (!newVariant.value.sku.trim() || Object.keys(attributesToMap(newVariant.value.attributes)).length === 0) {
    variationError.value = 'SKU dan minimal satu atribut (mis. Ukuran: 1kg) wajib diisi.'
    return
  }
  stagedVariants.value.push(newVariant.value)
  newVariant.value = blankVariant()
}
function unstageVariant(index: number) {
  stagedVariants.value.splice(index, 1)
}

/* Edit-mode variant CRUD (product already has_variations=true, immediate actions) */
const variationError = ref('')
const variationSaving = ref(false)
const editingVariationId = ref<number | null>(null)
const variationEditForm = reactive({
  sku: '', price: 0, weight_grams: 0, is_active: true, stock: 0,
  agent_fee: 0, sales_fee: 0, courier_fee: 0,
})

async function openEditVariation(variation: ProductVariation) {
  editingVariationId.value = variation.id
  variationEditForm.sku = variation.sku
  variationEditForm.price = Number(variation.price)
  variationEditForm.weight_grams = variation.weight_grams
  variationEditForm.is_active = variation.is_active
  variationEditForm.stock = variation.agent_available_quantity ?? 0
  variationEditForm.agent_fee = 0
  variationEditForm.sales_fee = 0
  variationEditForm.courier_fee = 0
  if (canManageFees.value && product.value) {
    const fees = await getVariationFees(product.value.id, variation.id)
    variationEditForm.agent_fee = fees.agent_fee
    variationEditForm.sales_fee = fees.sales_fee
    variationEditForm.courier_fee = fees.courier_fee ?? 0
  }
}

async function saveVariationEdit() {
  if (!product.value || editingVariationId.value === null) return
  variationSaving.value = true
  variationError.value = ''
  try {
    await updateVariation(product.value.id, editingVariationId.value, {
      sku: variationEditForm.sku, price: variationEditForm.price,
      weight_grams: variationEditForm.weight_grams, is_active: variationEditForm.is_active,
    })
    if (canManageFees.value) {
      await setVariationFees(product.value.id, editingVariationId.value, {
        agent_fee: variationEditForm.agent_fee, sales_fee: variationEditForm.sales_fee, courier_fee: variationEditForm.courier_fee,
      })
    }
    if (canManageStock.value) {
      const baseline = product.value.variations.find((v) => v.id === editingVariationId.value)?.agent_available_quantity ?? 0
      const delta = variationEditForm.stock - baseline
      if (delta !== 0) {
        await adjustStock({ product_variation_id: editingVariationId.value, delta, reason: 'Diperbarui dari form produk' })
      }
    }
    editingVariationId.value = null
    await load()
  } catch (e) {
    variationError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menyimpan perubahan varian.'
  } finally {
    variationSaving.value = false
  }
}

async function removeVariation(variationId: number) {
  if (!product.value) return
  if (!confirm('Hapus varian ini?')) return
  await deleteVariation(product.value.id, variationId)
  await load()
}

async function addVariationNow() {
  if (!product.value) return
  variationError.value = ''
  const attributes = attributesToMap(newVariant.value.attributes)
  if (!newVariant.value.sku.trim() || Object.keys(attributes).length === 0) {
    variationError.value = 'SKU dan minimal satu atribut (mis. Ukuran: 1kg) wajib diisi.'
    return
  }
  variationSaving.value = true
  try {
    const created = await createVariation(product.value.id, {
      sku: newVariant.value.sku.trim(), price: newVariant.value.price,
      weight_grams: newVariant.value.weight_grams, attributes,
    })
    if (canManageFees.value) {
      await setVariationFees(product.value.id, created.id, {
        agent_fee: newVariant.value.agent_fee, sales_fee: newVariant.value.sales_fee, courier_fee: newVariant.value.courier_fee,
      })
    }
    if (canManageStock.value && newVariant.value.stock > 0) {
      await adjustStock({ product_variation_id: created.id, delta: newVariant.value.stock, reason: 'Stok awal' })
    }
    newVariant.value = blankVariant()
    showVariantBuilder.value = false
    await load()
  } catch (e) {
    variationError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menambah varian.'
  } finally {
    variationSaving.value = false
  }
}

/* ---------- Images: staged (create) vs persisted (edit) ---------- */
interface StagedImage { file: File; previewUrl: string; isDefault: boolean }
const stagedImages = ref<StagedImage[]>([])
const imageError = ref('')
const imageBusy = ref(false)
const dragOver = ref(false)

function handleFiles(files: FileList | null) {
  if (!files) return
  for (const file of Array.from(files)) {
    if (!file.type.startsWith('image/')) continue
    stagedImages.value.push({
      file,
      previewUrl: URL.createObjectURL(file),
      isDefault: stagedImages.value.length === 0 && !product.value?.images.length,
    })
  }
}

function onFileInput(event: Event) {
  handleFiles((event.target as HTMLInputElement).files)
  ;(event.target as HTMLInputElement).value = ''
}
function onDrop(event: DragEvent) {
  dragOver.value = false
  handleFiles(event.dataTransfer?.files ?? null)
}

function setStagedDefault(index: number) {
  stagedImages.value.forEach((img, i) => (img.isDefault = i === index))
}
function unstageImage(index: number) {
  URL.revokeObjectURL(stagedImages.value[index]!.previewUrl)
  stagedImages.value.splice(index, 1)
}

async function uploadNow(event: Event) {
  const files = (event.target as HTMLInputElement).files
  if (!files || !product.value) return
  imageBusy.value = true
  imageError.value = ''
  try {
    for (const file of Array.from(files)) {
      await uploadProductImage(product.value.id, file, { is_primary: !product.value.images.length })
    }
    await load()
  } catch (e) {
    imageError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal mengunggah gambar.'
  } finally {
    imageBusy.value = false
    ;(event.target as HTMLInputElement).value = ''
  }
}
async function dropNow(event: DragEvent) {
  dragOver.value = false
  const files = event.dataTransfer?.files
  if (!files || !product.value) return
  imageBusy.value = true
  imageError.value = ''
  try {
    for (const file of Array.from(files)) {
      if (!file.type.startsWith('image/')) continue
      await uploadProductImage(product.value.id, file, { is_primary: !product.value.images.length })
    }
    await load()
  } catch (e) {
    imageError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal mengunggah gambar.'
  } finally {
    imageBusy.value = false
  }
}
async function removeImage(imageId: number) {
  if (!product.value) return
  await deleteProductImage(product.value.id, imageId)
  await load()
}
async function makePrimary(imageId: number) {
  if (!product.value) return
  await setPrimaryProductImage(product.value.id, imageId)
  await load()
}

/* ---------- Load ---------- */
async function load() {
  loading.value = true
  categories.value = await listCategories()

  if (isEditing.value) {
    const { products } = await listProducts({ per_page: 100 })
    const found = products.find((p) => p.id === productId.value)
    if (found) {
      product.value = found
      form.category_id = found.category?.id ?? null
      form.sku = found.sku ?? ''
      form.name = found.name
      form.short_description = found.short_description ?? ''
      form.description = found.description
      form.status = found.status as typeof form.status
      form.base_price = found.base_price ? Number(found.base_price) : 0
      form.weight_grams = found.weight_grams ?? 0
      form.stock = found.agent_available_quantity ?? 0
      currentStock.value = form.stock
      if (canManageFees.value && !found.has_variations) {
        const fees = await getProductFees(found.id)
        form.agent_fee = fees.agent_fee
        form.sales_fee = fees.sales_fee
        form.courier_fee = fees.courier_fee ?? 0
      }
    }
  }
  loading.value = false
}

onMounted(load)

/* ---------- Submit ---------- */
async function submit() {
  formError.value = ''
  errors.value = {}
  saving.value = true
  try {
    if (isEditing.value && productId.value !== null) {
      await updateProduct(productId.value, {
        category_id: form.category_id,
        name: form.name,
        description: form.description,
        short_description: form.short_description || null,
        status: form.status,
        ...(!hasVariations.value ? { sku: form.sku, base_price: form.base_price, weight_grams: form.weight_grams } : {}),
      })
      if (canManageFees.value && !hasVariations.value) {
        await setProductFees(productId.value, {
          agent_fee: form.agent_fee, sales_fee: form.sales_fee, courier_fee: form.courier_fee,
        })
      }
      if (canManageStock.value && !hasVariations.value) {
        const delta = form.stock - currentStock.value
        if (delta !== 0) {
          await adjustStock({ product_id: productId.value, delta, reason: 'Diperbarui dari form produk' })
        }
      }
      router.push({ name: 'product-management' })
    } else {
      const has_variations = willHaveVariations.value
      const created = await createProduct({
        category_id: form.category_id,
        name: form.name,
        description: form.description,
        short_description: form.short_description || null,
        status: form.status,
        has_variations,
        ...(!has_variations ? { sku: form.sku, base_price: form.base_price, weight_grams: form.weight_grams } : {}),
      })

      // Flush staged pieces. If any step fails partway, the product itself
      // already exists — send the user into edit mode to finish manually
      // rather than losing track of what succeeded.
      try {
        if (!has_variations && canManageFees.value) {
          await setProductFees(created.id, {
            agent_fee: form.agent_fee, sales_fee: form.sales_fee, courier_fee: form.courier_fee,
          })
        }
        if (!has_variations && canManageStock.value && form.stock > 0) {
          await adjustStock({ product_id: created.id, delta: form.stock, reason: 'Stok awal' })
        }
        for (const variant of stagedVariants.value) {
          const createdVariant = await createVariation(created.id, {
            sku: variant.sku.trim(), price: variant.price,
            weight_grams: variant.weight_grams, attributes: attributesToMap(variant.attributes),
          })
          if (canManageFees.value) {
            await setVariationFees(created.id, createdVariant.id, {
              agent_fee: variant.agent_fee, sales_fee: variant.sales_fee, courier_fee: variant.courier_fee,
            })
          }
          if (canManageStock.value && variant.stock > 0) {
            await adjustStock({ product_variation_id: createdVariant.id, delta: variant.stock, reason: 'Stok awal' })
          }
        }
        let defaultSet = false
        for (const img of stagedImages.value) {
          await uploadProductImage(created.id, img.file, { is_primary: img.isDefault })
          if (img.isDefault) defaultSet = true
        }
        void defaultSet
        router.push({ name: 'product-management' })
      } catch (flushError) {
        formError.value = flushError instanceof ApiError ? formatApiError(flushError) : 'Produk tersimpan, tapi sebagian data varian/gambar/stok gagal disimpan. Lengkapi di halaman edit.'
        router.push({ name: 'product-edit', params: { id: created.id } })
      }
    }
  } catch (e) {
    if (e instanceof ApiError) {
      formError.value = formatApiError(e)
      errors.value = e.errors ?? {}
    } else {
      formError.value = 'Gagal menyimpan produk. Periksa kembali data yang diisi.'
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <DashboardLayout>
    <div class="mx-auto max-w-3xl">
      <RouterLink :to="{ name: 'product-management' }" class="mb-4 inline-flex items-center gap-1.5 text-sm text-stone-500 hover:text-stone-800 dark:text-stone-400 dark:hover:text-stone-200">
        <AppIcon name="chevron-left" :size="16" /> Kembali ke Produk & Kategori
      </RouterLink>

      <h1 class="mb-1 font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">
        {{ isEditing ? 'Edit Produk' : 'Produk Baru' }}
      </h1>
      <p class="mb-6 text-sm text-stone-500 dark:text-stone-400">
        {{ isEditing ? 'Perbarui informasi produk ini.' : 'Lengkapi informasi produk, gambar, dan varian (jika ada) di bawah ini.' }}
      </p>

      <div v-if="loading" class="text-sm text-stone-500">Memuat...</div>

      <form v-else class="space-y-5 rounded-2xl border border-stone-200 bg-white p-6 dark:border-stone-800 dark:bg-stone-900" @submit.prevent="submit">
        <label v-if="!hasVariations" class="block text-sm">
          SKU (wajib)
          <input v-model="form.sku" required maxlength="60" class="mt-1 block w-full rounded-lg border p-2" />
          <span v-if="errors.sku" class="text-red-600">{{ errors.sku.join(' ') }}</span>
        </label>
        <p v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ formError }}</p>

        <label class="block text-sm text-stone-600 dark:text-stone-300">
          Nama Produk
          <input v-model="form.name" type="text" required class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          <p v-if="errors.name" class="mt-1 text-xs text-red-600">{{ errors.name[0] }}</p>
        </label>

        <label class="block text-sm text-stone-600 dark:text-stone-300">
          Kategori
          <select v-model="form.category_id" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950">
            <option :value="null">— Tanpa kategori —</option>
            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
          </select>
        </label>

        <label class="block text-sm text-stone-600 dark:text-stone-300">
          Deskripsi Singkat
          <textarea
            v-model="form.short_description"
            rows="2"
            maxlength="300"
            placeholder="Ringkasan singkat, tampil di kartu produk"
            class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
          />
        </label>

        <div>
          <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Deskripsi Lengkap</label>
          <RichTextEditor v-model="form.description as string" image-collection="cms_content" />
        </div>

        <div v-if="!hasVariations" class="grid grid-cols-2 gap-4">
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Harga Dasar (Rp)
            <input v-model.number="form.base_price" type="number" min="0" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            <p v-if="errors.base_price" class="mt-1 text-xs text-red-600">{{ errors.base_price[0] }}</p>
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Berat (gram)
            <input v-model.number="form.weight_grams" type="number" min="0" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
        </div>
        <div v-else class="rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-500 dark:bg-stone-800/60 dark:text-stone-400">
          Harga, berat, stok, dan fee diatur per varian di bawah.
        </div>

        <div v-if="!hasVariations && canManageFees">
          <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Fee per Transaksi (Rp)</label>
          <p class="mb-2 text-xs text-stone-400">Komisi yang didapat masing-masing pihak setiap produk ini terjual.</p>
          <div class="grid grid-cols-3 gap-3">
            <label class="block text-xs text-stone-500 dark:text-stone-400">
              Fee Agen
              <input v-model.number="form.agent_fee" type="number" min="0" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            </label>
            <label class="block text-xs text-stone-500 dark:text-stone-400">
              Fee Sales
              <input v-model.number="form.sales_fee" type="number" min="0" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            </label>
            <label class="block text-xs text-stone-500 dark:text-stone-400">
              Fee Kurir
              <input v-model.number="form.courier_fee" type="number" min="0" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            </label>
          </div>
        </div>

        <label v-if="!hasVariations && canManageStock" class="block text-sm text-stone-600 dark:text-stone-300">
          Stok
          <input v-model.number="form.stock" type="number" min="0" class="mt-1 block w-40 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
        </label>

        <label class="block text-sm text-stone-600 dark:text-stone-300">
          Status
          <select v-model="form.status" class="mt-1 block w-48 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950">
            <option value="draft">Draft</option>
            <option value="active">Aktif</option>
            <option value="inactive">Nonaktif</option>
          </select>
        </label>

        <!-- Images -->
        <div>
          <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Gambar Produk (bisa lebih dari satu)</label>
          <p v-if="imageError" class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950 dark:text-red-300">{{ imageError }}</p>

          <!-- Existing images (edit mode, immediate actions) -->
          <div v-if="isEditing && product && product.images.length" class="mb-3 grid grid-cols-3 gap-3 sm:grid-cols-4">
            <div v-for="img in product.images" :key="img.id" class="relative overflow-hidden rounded-xl border border-stone-200 dark:border-stone-700">
              <img :src="img.url" class="h-24 w-full object-cover" />
              <span v-if="img.is_primary" class="absolute left-1.5 top-1.5 rounded-full bg-brand-600 px-2 py-0.5 text-[10px] font-medium text-white">Default</span>
              <div class="absolute inset-x-0 bottom-0 flex justify-between gap-1 bg-black/50 p-1.5">
                <button v-if="!img.is_primary" type="button" class="rounded bg-white/90 px-1.5 py-0.5 text-[10px] font-medium text-stone-700" @click="makePrimary(img.id)">
                  Jadikan Default
                </button>
                <span v-else class="flex-1"></span>
                <button type="button" class="rounded bg-red-600 px-1.5 py-0.5 text-[10px] font-medium text-white" @click="removeImage(img.id)">
                  <AppIcon name="trash" :size="11" />
                </button>
              </div>
            </div>
          </div>

          <!-- Staged images (create mode, or newly added in edit before... actually edit uploads immediately below) -->
          <div v-if="!isEditing && stagedImages.length" class="mb-3 grid grid-cols-3 gap-3 sm:grid-cols-4">
            <div v-for="(img, i) in stagedImages" :key="img.previewUrl" class="relative overflow-hidden rounded-xl border border-stone-200 dark:border-stone-700">
              <img :src="img.previewUrl" class="h-24 w-full object-cover" />
              <span v-if="img.isDefault" class="absolute left-1.5 top-1.5 rounded-full bg-brand-600 px-2 py-0.5 text-[10px] font-medium text-white">Default</span>
              <div class="absolute inset-x-0 bottom-0 flex justify-between gap-1 bg-black/50 p-1.5">
                <button v-if="!img.isDefault" type="button" class="rounded bg-white/90 px-1.5 py-0.5 text-[10px] font-medium text-stone-700" @click="setStagedDefault(i)">
                  Jadikan Default
                </button>
                <span v-else class="flex-1"></span>
                <button type="button" class="rounded bg-red-600 px-1.5 py-0.5 text-[10px] font-medium text-white" @click="unstageImage(i)">
                  <AppIcon name="trash" :size="11" />
                </button>
              </div>
            </div>
          </div>

          <!-- Drop zone -->
          <label
            class="flex min-h-24 cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed px-4 py-6 text-center text-sm transition"
            :class="dragOver ? 'border-brand-500 bg-brand-50 dark:bg-brand-950/30' : 'border-stone-300 text-stone-500 dark:border-stone-700 dark:text-stone-400'"
            @dragover.prevent="dragOver = true"
            @dragleave.prevent="dragOver = false"
            @drop.prevent="isEditing ? dropNow($event) : onDrop($event)"
          >
            <AppIcon name="upload" :size="20" class="mb-1" />
            {{ imageBusy ? 'Mengunggah...' : 'Klik atau seret beberapa gambar ke sini' }}
            <input type="file" accept="image/jpeg,image/png,image/webp" multiple class="hidden" :disabled="imageBusy" @change="isEditing ? uploadNow($event) : onFileInput($event)" />
          </label>
        </div>

        <!-- Variants -->
        <div>
          <div class="flex items-center justify-between">
            <label class="block text-sm font-medium text-stone-700 dark:text-stone-200">Varian Produk (opsional — misal ukuran/warna)</label>
            <button
              v-if="!isEditing || hasVariations"
              type="button"
              class="text-xs font-medium text-brand-600 dark:text-brand-400"
              @click="showVariantBuilder = !showVariantBuilder"
            >
              + Tambah Varian
            </button>
          </div>
          <p v-if="isEditing && !hasVariations" class="mt-1 text-xs text-stone-400">
            Produk ini dibuat tanpa varian — tidak dapat diubah setelah dibuat.
          </p>
          <p v-if="variationError" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950 dark:text-red-300">{{ variationError }}</p>

          <!-- Existing variants (edit mode) -->
          <div v-if="isEditing && product?.variations.length" class="mt-3 space-y-2">
            <div v-for="variation in product.variations" :key="variation.id" class="rounded-xl border border-stone-100 p-3 dark:border-stone-800">
              <template v-if="editingVariationId === variation.id">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                  <label class="block text-xs text-stone-500 dark:text-stone-400">
                    SKU
                    <input v-model="variationEditForm.sku" type="text" class="mt-0.5 block w-full rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
                  </label>
                  <label class="block text-xs text-stone-500 dark:text-stone-400">
                    Harga (Rp)
                    <input v-model.number="variationEditForm.price" type="number" min="0" class="mt-0.5 block w-full rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
                  </label>
                  <label class="block text-xs text-stone-500 dark:text-stone-400">
                    Berat (gram)
                    <input v-model.number="variationEditForm.weight_grams" type="number" min="0" class="mt-0.5 block w-full rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
                  </label>
                  <label v-if="canManageStock" class="block text-xs text-stone-500 dark:text-stone-400">
                    Stok
                    <input v-model.number="variationEditForm.stock" type="number" min="0" class="mt-0.5 block w-full rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
                  </label>
                </div>
                <div v-if="canManageFees" class="mt-2 grid grid-cols-3 gap-2">
                  <label class="block text-xs text-stone-500 dark:text-stone-400">
                    Fee Agen
                    <input v-model.number="variationEditForm.agent_fee" type="number" min="0" class="mt-0.5 block w-full rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
                  </label>
                  <label class="block text-xs text-stone-500 dark:text-stone-400">
                    Fee Sales
                    <input v-model.number="variationEditForm.sales_fee" type="number" min="0" class="mt-0.5 block w-full rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
                  </label>
                  <label class="block text-xs text-stone-500 dark:text-stone-400">
                    Fee Kurir
                    <input v-model.number="variationEditForm.courier_fee" type="number" min="0" class="mt-0.5 block w-full rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
                  </label>
                </div>
                <label class="mt-2 flex items-center gap-1.5 text-xs text-stone-600 dark:text-stone-300">
                  <input v-model="variationEditForm.is_active" type="checkbox" /> Aktif
                </label>
                <div class="mt-2 flex gap-2">
                  <AppButton size="sm" type="button" :disabled="variationSaving" @click="saveVariationEdit">Simpan</AppButton>
                  <AppButton size="sm" variant="ghost" type="button" @click="editingVariationId = null">Batal</AppButton>
                </div>
              </template>
              <template v-else>
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <div>
                    <p class="text-sm font-medium text-stone-800 dark:text-stone-100">{{ variation.label }} <span class="text-xs text-stone-400">({{ variation.sku }})</span></p>
                    <p class="text-xs text-stone-500 dark:text-stone-400">
                      Rp{{ Number(variation.price).toLocaleString('id-ID') }} &middot; {{ variation.weight_grams }}g &middot; {{ variation.is_active ? 'Aktif' : 'Nonaktif' }}
                      <span v-if="canManageStock"> &middot; Stok: {{ variation.agent_available_quantity ?? 0 }}</span>
                    </p>
                  </div>
                  <div class="flex gap-1.5">
                    <button type="button" class="rounded-lg bg-stone-100 px-2.5 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-200 dark:bg-stone-800 dark:text-stone-200" @click="openEditVariation(variation)">
                      Edit
                    </button>
                    <button type="button" class="rounded-lg bg-red-50 px-2.5 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100 dark:bg-red-950 dark:text-red-300" @click="removeVariation(variation.id)">
                      <AppIcon name="trash" :size="13" />
                    </button>
                  </div>
                </div>
              </template>
            </div>
          </div>

          <!-- Staged variants (create mode) -->
          <div v-if="!isEditing && stagedVariants.length" class="mt-3 space-y-2">
            <div v-for="(variant, i) in stagedVariants" :key="i" class="flex items-center justify-between rounded-xl border border-stone-100 p-3 text-sm dark:border-stone-800">
              <span>
                <strong>{{ variant.sku }}</strong> — {{ Object.entries(attributesToMap(variant.attributes)).map(([k, v]) => `${k}: ${v}`).join(', ') }}
                &middot; Rp{{ variant.price.toLocaleString('id-ID') }} &middot; {{ variant.weight_grams }}g
                <span v-if="canManageStock"> &middot; Stok: {{ variant.stock }}</span>
              </span>
              <button type="button" class="text-stone-400 hover:text-red-600" @click="unstageVariant(i)"><AppIcon name="trash" :size="14" /></button>
            </div>
          </div>

          <!-- Add-variant builder -->
          <div v-if="showVariantBuilder" class="mt-3 rounded-xl bg-stone-50 p-4 dark:bg-stone-800/60">
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
              <label class="block text-xs text-stone-500 dark:text-stone-400">
                SKU
                <input v-model="newVariant.sku" type="text" class="mt-0.5 block w-full rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
              </label>
              <label class="block text-xs text-stone-500 dark:text-stone-400">
                Harga (Rp)
                <input v-model.number="newVariant.price" type="number" min="0" class="mt-0.5 block w-full rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
              </label>
              <label class="block text-xs text-stone-500 dark:text-stone-400">
                Berat (gram)
                <input v-model.number="newVariant.weight_grams" type="number" min="0" class="mt-0.5 block w-full rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
              </label>
              <label v-if="canManageStock" class="block text-xs text-stone-500 dark:text-stone-400">
                Stok
                <input v-model.number="newVariant.stock" type="number" min="0" class="mt-0.5 block w-full rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
              </label>
            </div>
            <div v-if="canManageFees" class="mt-2 grid grid-cols-3 gap-2">
              <label class="block text-xs text-stone-500 dark:text-stone-400">
                Fee Agen
                <input v-model.number="newVariant.agent_fee" type="number" min="0" class="mt-0.5 block w-full rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
              </label>
              <label class="block text-xs text-stone-500 dark:text-stone-400">
                Fee Sales
                <input v-model.number="newVariant.sales_fee" type="number" min="0" class="mt-0.5 block w-full rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
              </label>
              <label class="block text-xs text-stone-500 dark:text-stone-400">
                Fee Kurir
                <input v-model.number="newVariant.courier_fee" type="number" min="0" class="mt-0.5 block w-full rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
              </label>
            </div>
            <p class="mb-1 mt-3 text-xs font-medium text-stone-500 dark:text-stone-400">Atribut (mis. Ukuran = 1kg, Rasa = Coklat)</p>
            <div v-for="(row, i) in newVariant.attributes" :key="i" class="mb-1.5 flex gap-2">
              <input v-model="row.key" type="text" placeholder="Nama atribut" class="w-1/2 rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
              <input v-model="row.value" type="text" placeholder="Nilai" class="w-1/2 rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
              <button type="button" class="shrink-0 text-stone-400 hover:text-red-600" @click="removeAttributeRow(newVariant, i)"><AppIcon name="trash" :size="14" /></button>
            </div>
            <button type="button" class="mb-3 flex items-center gap-1 text-xs font-medium text-brand-600 dark:text-brand-400" @click="addAttributeRow(newVariant)">
              <AppIcon name="plus" :size="12" /> Tambah atribut
            </button>
            <div class="flex gap-2">
              <AppButton size="sm" type="button" :disabled="variationSaving" @click="isEditing ? addVariationNow() : stageVariant()">
                {{ isEditing ? (variationSaving ? 'Menyimpan...' : 'Tambah Varian') : 'Tambahkan ke Daftar' }}
              </AppButton>
              <AppButton size="sm" variant="ghost" type="button" @click="showVariantBuilder = false">Tutup</AppButton>
            </div>
          </div>
        </div>

        <div class="flex justify-end gap-2 border-t border-stone-100 pt-4 dark:border-stone-800">
          <RouterLink :to="{ name: 'product-management' }" class="rounded-lg px-3 py-2 text-sm text-stone-500 dark:text-stone-400">Batal</RouterLink>
          <AppButton type="submit" :disabled="saving">{{ saving ? 'Menyimpan...' : 'Simpan Produk' }}</AppButton>
        </div>
      </form>
    </div>
  </DashboardLayout>
</template>
