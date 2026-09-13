import { http } from './client'
import type { ApiEnvelope } from './client'

export interface ArticleTranslation {
  language_id: number
  title: string
  excerpt?: string | null
  body: string
}

export interface Article {
  id: number
  type: 'article' | 'news'
  slug: string
  status: 'draft' | 'published'
  published_at: string | null
  cover_image_url: string | null
  author_name: string | null
  translations: ArticleTranslation[]
  created_at: string
  updated_at: string
}

export interface ArticlePayload {
  type: 'article' | 'news'
  slug: string
  status?: 'draft' | 'published'
  published_at?: string | null
  cover_media_id?: number | null
  remove_cover?: boolean
  translations: ArticleTranslation[]
}

export async function listAdminArticles(params?: { type?: string; status?: string }): Promise<Article[]> {
  const { data } = await http.get<ApiEnvelope<Article[]>>('/cms/articles', { params })
  return data.data
}

/** No dedicated show-by-id admin endpoint exists — the admin list is small, so an edit page just filters it by id. */
export async function getArticle(id: number): Promise<Article | undefined> {
  const articles = await listAdminArticles()
  return articles.find((a) => a.id === id)
}

export async function createArticle(payload: ArticlePayload): Promise<Article> {
  const { data } = await http.post<ApiEnvelope<Article>>('/cms/articles', payload)
  return data.data
}

export async function updateArticle(id: number, payload: Partial<ArticlePayload>): Promise<Article> {
  const { data } = await http.patch<ApiEnvelope<Article>>(`/cms/articles/${id}`, payload)
  return data.data
}

export async function deleteArticle(id: number): Promise<void> {
  await http.delete(`/cms/articles/${id}`)
}

export interface PageTranslation {
  language_id: number
  title: string
  body: string | null
  seo_title?: string | null
  seo_description?: string | null
}

export interface Page {
  id: number
  slug: string
  status: 'draft' | 'published'
  published_at: string | null
  cover_image_url: string | null
  translations: PageTranslation[]
  created_at: string
  updated_at: string
}

export interface PagePayload {
  slug: string
  status?: 'draft' | 'published'
  published_at?: string | null
  cover_media_id?: number | null
  remove_cover?: boolean
  translations: PageTranslation[]
}

export async function listAdminPages(params?: { status?: string }): Promise<Page[]> {
  const { data } = await http.get<ApiEnvelope<Page[]>>('/cms/pages', { params })
  return data.data
}

/** No dedicated show-by-id admin endpoint exists — the admin list is small, so an edit page just filters it by id. */
export async function getPage(id: number): Promise<Page | undefined> {
  const pages = await listAdminPages()
  return pages.find((p) => p.id === id)
}

export async function createPage(payload: PagePayload): Promise<Page> {
  const { data } = await http.post<ApiEnvelope<Page>>('/cms/pages', payload)
  return data.data
}

export async function updatePage(id: number, payload: Partial<PagePayload>): Promise<Page> {
  const { data } = await http.patch<ApiEnvelope<Page>>(`/cms/pages/${id}`, payload)
  return data.data
}

export async function deletePage(id: number): Promise<void> {
  await http.delete(`/cms/pages/${id}`)
}
