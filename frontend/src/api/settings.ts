import { http } from './client'
import type { ApiEnvelope } from './client'

export interface WebsiteSettings {
  site_title: string
  site_slogan: string
  site_contact_email: string
  site_contact_phone: string
  site_address: string
  site_seo_title: string
  site_seo_description: string
  site_seo_keywords: string
  site_about: string
  site_logo_media_id: number | null
  site_favicon_media_id: number | null
  logo_url: string | null
  favicon_url: string | null
}

/** Public, unauthenticated, cached server-side — the same shape as admin settings (none of these fields are secret). */
export async function getPublicSettings() {
  const { data } = await http.get<ApiEnvelope<WebsiteSettings>>('/settings')
  return data.data
}

export async function getAdminSettings() {
  const { data } = await http.get<ApiEnvelope<WebsiteSettings>>('/admin/settings')
  return data.data
}

export async function updateSettings(payload: Partial<Omit<WebsiteSettings, 'site_contact_email'>> & { site_contact_email?: string | null }) {
  const { data } = await http.put<ApiEnvelope<WebsiteSettings>>('/admin/settings', payload)
  return data.data
}
