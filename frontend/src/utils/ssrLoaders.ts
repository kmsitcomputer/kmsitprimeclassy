import { getArticle, getPage } from '@/api/cms'
import { getProduct, listCategories, listProducts, listRelatedProducts } from '@/api/catalog'
import { listPublicAgents, type PublicAgentContact } from '@/api/agents'
import { listLanguages, type Language } from '@/api/languages'
import type { Article, Page } from '@/api/cmsContent'
import type { Category, PaginationMeta, Product } from '@/api/types'

/**
 * Loaders shared by the public views (which seed themselves from them) and by the Nuxt storefront
 * wrappers (which need the same data for <head>/JSON-LD). Both go through the SSR data seam with the
 * SAME key, so each request hits the API once. Anonymous/public data only.
 */
export type ProductSeed = { product: Product; related: Product[] }
export type ArticleSeed = { article: Article; languages: Language[] }
export type PageSeed = { page: Page; languages: Language[] }

export const productKey = (slug: string) => `product:${slug}`
export const articleKey = (slug: string) => `article:${slug}`
export const pageKey = (slug: string) => `page:${slug}`

export async function fetchProductSeed(slug: string): Promise<ProductSeed> {
  const product = await getProduct(slug)
  const related = await listRelatedProducts(product.id, 8).catch(() => [] as Product[])
  return { product, related }
}

export async function fetchArticleSeed(slug: string): Promise<ArticleSeed> {
  const [article, languages] = await Promise.all([getArticle(slug), listLanguages()])
  return { article, languages }
}

export async function fetchPageSeed(slug: string): Promise<PageSeed> {
  const [page, languages] = await Promise.all([getPage(slug), listLanguages()])
  return { page, languages }
}

// ── Catalogue listing (/products) ────────────────────────────────────────────────────────────────────────────
export type ListingQuery = Record<string, unknown>
export type ProductListingSeed = { products: Product[]; meta: PaginationMeta; categories: Category[] }
export const PRODUCT_PAGE_SIZE = 12

const str = (v: unknown): string => (Array.isArray(v) ? String(v[0] ?? '') : v == null ? '' : String(v))

/** The URL query → API params contract of the listing (identical for the SPA view and the SSR loader). */
export function listingParamsFromQuery(query: ListingQuery, stockSource?: 'agent' | 'sub') {
  const sort = str(query.sort) || 'newest'
  return {
    stock_source: stockSource,
    search: str(query.search) || undefined,
    category: str(query.category) || undefined,
    sort: sort as 'newest' | 'price_asc' | 'price_desc',
    min_price: str(query.min_price) ? Number(str(query.min_price)) : undefined,
    max_price: str(query.max_price) ? Number(str(query.max_price)) : undefined,
    page: Number(str(query.page) || 1),
    per_page: PRODUCT_PAGE_SIZE,
  }
}

/** Key = the page itself (path + query minus the presentation-only `lang`), so every paginated/filtered URL seeds its own data. */
export function productListingKey(query: ListingQuery): string {
  const keep = ['search', 'category', 'sort', 'min_price', 'max_price', 'page']
  return `products:${keep.map((k) => `${k}=${str(query[k])}`).join('&')}`
}

export async function fetchProductListingSeed(query: ListingQuery): Promise<ProductListingSeed> {
  const [categories, listed] = await Promise.all([listCategories(), listProducts(listingParamsFromQuery(query))])
  return { products: listed.products, meta: listed.meta, categories }
}

// ── Articles listing (/articles) ─────────────────────────────────────────────────────────────────────────────
export type ArticlesPageSeed = { items: Article[]; currentPage: number; lastPage: number; languages: Language[] }
export const articlesKey = (page: number) => `articles:${page}`

export async function fetchArticlesPageSeed(page: number): Promise<ArticlesPageSeed> {
  const { listArticles } = await import('@/api/cms')
  const [result, languages] = await Promise.all([listArticles({ page }), listLanguages()])
  return { items: result.items, currentPage: result.currentPage, lastPage: result.lastPage, languages }
}

// ── Store locator (/toko-kami) ───────────────────────────────────────────────────────────────────────────────
/** Server-rendered subset. Phone numbers and referral codes are deliberately NOT part of it (see docs/20 §6a). */
export const AGENTS_SSR_LIMIT = 50
export const agentsKey = 'agents:public'

export async function fetchAgentsSeed(): Promise<PublicAgentContact[]> {
  const all = await listPublicAgents()
  return all.slice(0, AGENTS_SSR_LIMIT).map((a) => ({
    name: a.name,
    address: a.address,
    latitude: a.latitude,
    longitude: a.longitude,
    phone: null,
    referral_code: null,
  }))
}
