import { http } from './client'
import type { ApiEnvelope } from './client'

export interface Language {
  id: number
  code: string
  name: string
  is_default: boolean
  is_active?: boolean
  sort_order?: number
}

export async function listLanguages(): Promise<Language[]> {
  const { data } = await http.get<ApiEnvelope<Language[]>>('/languages')
  return data.data
}

export async function listAdminLanguages(): Promise<Language[]> {
  const { data } = await http.get<ApiEnvelope<Language[]>>('/admin/languages')
  return data.data
}

export async function createLanguage(payload: { code: string; name: string; sort_order?: number }): Promise<Language> {
  const { data } = await http.post<ApiEnvelope<Language>>('/admin/languages', payload)
  return data.data
}

export async function updateLanguage(id: number, payload: Partial<{ code: string; name: string; sort_order: number }>): Promise<Language> {
  const { data } = await http.patch<ApiEnvelope<Language>>(`/admin/languages/${id}`, payload)
  return data.data
}

export async function toggleLanguageActive(id: number): Promise<Language> {
  const { data } = await http.patch<ApiEnvelope<Language>>(`/admin/languages/${id}/toggle-active`)
  return data.data
}

export async function setDefaultLanguage(id: number): Promise<Language> {
  const { data } = await http.patch<ApiEnvelope<Language>>(`/admin/languages/${id}/set-default`)
  return data.data
}
