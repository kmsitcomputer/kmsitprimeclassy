import { getArticle, getPage } from '@/api/cms'
import { getProduct, listRelatedProducts } from '@/api/catalog'
import { listLanguages, type Language } from '@/api/languages'
import type { Article, Page } from '@/api/cmsContent'
import type { Product } from '@/api/types'

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
