import { http } from './client'
import type { ApiEnvelope } from './client'
import type { Category, Product, ProductVariation, ProductImage, PaginationMeta } from './types'

export async function listCategories() {
  const { data } = await http.get<ApiEnvelope<Category[]>>('/categories')
  return data.data
}

export async function createCategory(payload: {
  parent_id?: number | null
  name: string
  slug?: string
  is_active?: boolean
  sort_order?: number
}) {
  const { data } = await http.post<ApiEnvelope<Category>>('/categories', payload)
  return data.data
}

export async function updateCategory(id: number, payload: Partial<Omit<Category, 'id'>>) {
  const { data } = await http.patch<ApiEnvelope<Category>>(`/categories/${id}`, payload)
  return data.data
}

export async function deleteCategory(id: number) {
  await http.delete(`/categories/${id}`)
}

export interface ListProductsFilters {
  search?: string
  category?: string
  sort?: 'newest' | 'price_asc' | 'price_desc'
  min_price?: number
  max_price?: number
  page?: number
  per_page?: number
  random?: boolean
  exclude?: number
}

export async function listProducts(filters: ListProductsFilters = {}) {
  const { data } = await http.get<ApiEnvelope<Product[]>>('/products', { params: filters })
  return { products: data.data, meta: data.meta as unknown as PaginationMeta }
}

/** "Produk lainnya" on the product detail page — a small random slice of the active catalog, excluding the product being viewed. */
export async function listRelatedProducts(excludeProductId: number, limit = 8) {
  const { data } = await http.get<ApiEnvelope<Product[]>>('/products', {
    params: { random: true, exclude: excludeProductId, per_page: limit },
  })
  return data.data
}

export async function getProduct(slug: string) {
  const { data } = await http.get<ApiEnvelope<Product>>(`/products/${slug}`)
  return data.data
}

export interface ProductPayload {
  sku?: string | null
  category_id?: number | null
  name: string
  slug?: string
  description?: string | null
  short_description?: string | null
  has_variations?: boolean
  base_price?: number | null
  weight_grams?: number | null
  status?: 'draft' | 'active' | 'inactive'
}

export async function createProduct(payload: ProductPayload & { has_variations: boolean }) {
  const { data } = await http.post<ApiEnvelope<Product>>('/products', payload)
  return data.data
}

export async function updateProduct(id: number, payload: Partial<ProductPayload>) {
  const { data } = await http.patch<ApiEnvelope<Product>>(`/products/${id}`, payload)
  return data.data
}

export async function deleteProduct(id: number) {
  await http.delete(`/products/${id}`)
}

export async function createVariation(
  productId: number,
  payload: {
    sku: string
    price: number
    weight_grams: number
    is_active?: boolean
    attributes: Record<string, string>
  },
) {
  const { data } = await http.post<ApiEnvelope<ProductVariation>>(
    `/products/${productId}/variations`,
    payload,
  )
  return data.data
}

export async function updateVariation(
  productId: number,
  variationId: number,
  payload: Partial<{ sku: string; price: number; weight_grams: number; is_active: boolean }>,
) {
  const { data } = await http.patch<ApiEnvelope<ProductVariation>>(
    `/products/${productId}/variations/${variationId}`,
    payload,
  )
  return data.data
}

export async function deleteVariation(productId: number, variationId: number) {
  await http.delete(`/products/${productId}/variations/${variationId}`)
}

export async function uploadProductImage(
  productId: number,
  file: File,
  opts: { product_variation_id?: number | null; is_primary?: boolean; sort_order?: number } = {},
) {
  const form = new FormData()
  form.append('image', file)
  if (opts.product_variation_id)
    form.append('product_variation_id', String(opts.product_variation_id))
  if (opts.is_primary) form.append('is_primary', '1')
  if (opts.sort_order !== undefined) form.append('sort_order', String(opts.sort_order))
  const { data } = await http.post<ApiEnvelope<ProductImage>>(
    `/products/${productId}/images`,
    form,
    {
      headers: { 'Content-Type': 'multipart/form-data' },
    },
  )
  return data.data
}

export async function deleteProductImage(productId: number, imageId: number) {
  await http.delete(`/products/${productId}/images/${imageId}`)
}

export async function setPrimaryProductImage(productId: number, imageId: number) {
  const { data } = await http.patch<ApiEnvelope<ProductImage>>(
    `/products/${productId}/images/${imageId}`,
    { is_primary: true },
  )
  return data.data
}

export interface FeeSet {
  agent_fee: number
  sales_fee: number
  courier_fee?: number
}

export async function getProductFees(productId: number) {
  const { data } = await http.get<ApiEnvelope<FeeSet>>(`/products/${productId}/fees`)
  return data.data
}

export async function setProductFees(
  productId: number,
  payload: { agent_fee: number; sales_fee: number; courier_fee: number },
) {
  const { data } = await http.put<ApiEnvelope<FeeSet>>(`/products/${productId}/fees`, payload)
  return data.data
}

export async function getVariationFees(productId: number, variationId: number) {
  const { data } = await http.get<ApiEnvelope<FeeSet>>(
    `/products/${productId}/variations/${variationId}/fees`,
  )
  return data.data
}

export async function setVariationFees(
  productId: number,
  variationId: number,
  payload: { agent_fee: number; sales_fee: number; courier_fee: number },
) {
  const { data } = await http.put<ApiEnvelope<FeeSet>>(
    `/products/${productId}/variations/${variationId}/fees`,
    payload,
  )
  return data.data
}
