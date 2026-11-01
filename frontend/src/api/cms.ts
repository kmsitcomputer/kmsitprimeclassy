import { http } from './client'
import type { ApiEnvelope } from './client'
import type { Article, Page } from './cmsContent'

export interface HomepageContentArticle {
  id: number
  slug: string
  title: string
  cover_image_url?: string
  published_at?: string
}

export interface HomepageContentCategory {
  id: number
  slug: string
  name: string
}

export interface HomepageContentProduct {
  id: number
  slug: string
  name: string
  image_url?: string
  has_variations?: boolean
  base_price?: number
  /** "mulai Rp…": base price of a simple product, cheapest ACTIVE variation otherwise; null = no valid price. */
  starting_price?: string | number | null
}

export interface HomepageSlide {
  key: string
  image_url?: string | null
  mobile_image_url?: string | null
  alt?: string | null
  title?: string | null
  description?: string | null
  button_label?: string | null
  button_url?: string | null
  overlay?: 'none' | 'light' | 'dark' | null
  content_position?: 'left' | 'center' | 'right' | null
}

export interface HomepageBlockContent {
  heading?: string
  subheading?: string
  body?: string
  html?: string
  cta_label?: string
  cta_url?: string
  button_label?: string
  button_url?: string
  url?: string
  description?: string
  alt?: string
  image_url?: string
  category_id?: number
  category_ids?: number[]
  product_ids?: number[]
  article_ids?: number[]
  limit?: number
  articles?: HomepageContentArticle[]
  categories?: HomepageContentCategory[]
  products?: HomepageContentProduct[]

  // Hero / CTA presentation options (all optional; older blocks lack them)
  image_fit?: 'cover' | 'contain'
  image_position?: 'center' | 'top' | 'bottom' | 'left' | 'right'
  overlay?: 'none' | 'light' | 'dark'
  align?: 'left' | 'center'
  theme?: 'brand' | 'dark' | 'light' | 'sand'

  // Banner slider (`banner_slider`). Flags may arrive as 1/0/"1"/"0" or booleans.
  autoplay?: boolean | number | string
  interval_seconds?: number | string
  show_arrows?: boolean | number | string
  show_dots?: boolean | number | string
  slides?: HomepageSlide[]

  // Native CMS blocks: social media, WhatsApp, marketplace
  layout?: 'cards' | 'compact' | 'contained' | 'wide'
  mode?: 'card' | 'floating'
  phone?: string
  message?: string
  platforms?: Partial<Record<
    'instagram' | 'tiktok' | 'facebook' | 'tokopedia' | 'shopee',
    {
      url?: string
      enabled?: boolean
    }
  >>
}

export interface HomepageBlock {
  id: number
  type: string
  content: HomepageBlockContent
}

export async function getHomepage(): Promise<HomepageBlock[]> {
  const { data } = await http.get<ApiEnvelope<HomepageBlock[]>>('/homepage')
  return data.data
}

export interface PaginatedArticles {
  items: Article[]
  currentPage: number
  lastPage: number
  total: number
}

/** Public — published only. `type` optionally narrows to 'article' or 'news'. */
export async function listArticles(params?: { type?: string; per_page?: number; page?: number }): Promise<PaginatedArticles> {
  const { data } = await http.get<ApiEnvelope<Article[]>>('/articles', { params })
  const meta = data.meta ?? {}
  return {
    items: data.data,
    currentPage: Number(meta.current_page ?? 1),
    lastPage: Number(meta.last_page ?? 1),
    total: Number(meta.total ?? data.data.length),
  }
}

export async function getArticle(slug: string): Promise<Article> {
  const { data } = await http.get<ApiEnvelope<Article>>(`/articles/${slug}`)
  return data.data
}

/** Public — a published static page (About, Terms, ...) by slug. */
export async function getPage(slug: string): Promise<Page> {
  const { data } = await http.get<ApiEnvelope<Page>>(`/pages/${slug}`)
  return data.data
}
