<script setup lang="ts">
/**
 * DS-PBR-001 — the one reusable "pick a product/variant" control for every
 * warehouse operation form (receive/plan, opname, transfer). Staff search by
 * product name or SKU over the existing server-side searchable catalog
 * (GET /products?search= — small paged slice, never the whole catalog) and
 * pick a human-readable row: thumbnail, Product Name, variant options, SKU.
 * Database IDs stay internal: the form submits exactly one of product_id /
 * product_variation_id through the existing API contracts. The shared
 * catalog itself is untouched — only the caller's own agent stock moves.
 */
import { computed, ref } from 'vue'
import { listProducts } from '@/api/catalog'
import type { Product, ProductVariation } from '@/api/types'
import { skuLabel } from '@/utils/format'

const productId = defineModel<number | null>('productId', { default: null })
const variationId = defineModel<number | null>('variationId', { default: null })

const query = ref('')
const results = ref<Product[]>([])
const searching = ref(false)
const open = ref(false)
const selectedProduct = ref<Product | null>(null)
let timer: ReturnType<typeof setTimeout> | null = null

const selectedVariation = computed<ProductVariation | null>(() => {
  if (!selectedProduct.value?.has_variations) return null
  return selectedProduct.value.variations.find((v) => v.id === variationId.value) ?? null
})

function thumb(product: Product): string | null {
  return product.images[0]?.url ?? null
}

function variationCaption(variation: ProductVariation): string {
  return `${variation.label} · ${skuLabel(variation.sku)}`
}

function onInput() {
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => void search(), 300)
}

async function search() {
  const term = query.value.trim()
  if (term.length < 2) {
    results.value = []
    open.value = false
    return
  }
  searching.value = true
  try {
    const { products } = await listProducts({ search: term, per_page: 8 })
    results.value = products
    open.value = true
  } catch {
    results.value = []
    open.value = false
  } finally {
    searching.value = false
  }
}

function choose(product: Product) {
  selectedProduct.value = product
  // Exactly-one-target invariant for the backend contract: a variant-bearing
  // product submits variation-only (productId stays null until a variant is
  // picked); a simple product submits product-only.
  productId.value = product.has_variations ? null : product.id
  variationId.value = null
  query.value = ''
  results.value = []
  open.value = false
}

function onVariationChange(event: Event) {
  const value = (event.target as HTMLSelectElement).value
  variationId.value = value ? Number(value) : null
}

function clear() {
  selectedProduct.value = null
  productId.value = null
  variationId.value = null
  query.value = ''
  results.value = []
  open.value = false
}
</script>

<template>
  <div>
    <div v-if="!selectedProduct">
      <input
        v-model="query"
        type="text"
        placeholder="Cari produk... (nama / SKU)"
        autocomplete="off"
        @input="onInput"
        @focus="() => { if (results.length) open = true }"
      />
      <p v-if="searching">Mencari...</p>
      <ul v-if="open && results.length">
        <li v-for="product in results" :key="product.id">
          <button type="button" @click="choose(product)">
            <img v-if="thumb(product)" :src="thumb(product) ?? ''" :alt="product.name" />
            <span>
              <span>{{ product.name }}</span>
              <span v-if="product.has_variations">{{ product.variations.length }} varian</span>
              <span v-else>Tanpa Varian</span>
              <span>{{ skuLabel(product.sku) }}</span>
            </span>
          </button>
        </li>
      </ul>
      <p v-else-if="open && query.trim().length >= 2 && !searching">Tidak ada produk yang cocok.</p>
    </div>
    <div v-else>
      <div>
        <img v-if="thumb(selectedProduct)" :src="thumb(selectedProduct) ?? ''" :alt="selectedProduct.name" />
        <span>
          <span>{{ selectedProduct.name }}</span>
          <span v-if="selectedVariation">{{ selectedVariation.label }}</span>
          <span v-else-if="!selectedProduct.has_variations">Tanpa Varian</span>
          <span v-else>Pilih varian di bawah</span>
          <span>{{ skuLabel(selectedVariation?.sku ?? selectedProduct.sku) }}</span>
        </span>
        <button type="button" @click="clear">Ganti</button>
      </div>
      <label v-if="selectedProduct.has_variations">
        Varian
        <select :value="variationId ?? ''" required @change="onVariationChange">
          <option value="" disabled>Pilih varian...</option>
          <option v-for="variation in selectedProduct.variations" :key="variation.id" :value="variation.id">
            {{ variationCaption(variation) }}
          </option>
        </select>
      </label>
    </div>
  </div>
</template>
