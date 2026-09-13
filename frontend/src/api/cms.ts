import { http } from './client'
import type { ApiEnvelope } from './client'
import type { Article, Page } from './cmsContent'

export interface HomepageBlock {
  id: number
  type: string
  content: Record<string, any>
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
