import { http } from './client'
import type { ApiEnvelope } from './client'

export interface Media {
  id: number
  url: string
  collection: string
  original_filename: string | null
  mime_type: string
  size: number
  width: number | null
  height: number | null
  created_at: string
}

export async function uploadMedia(file: File, collection: string): Promise<Media> {
  const form = new FormData()
  form.append('file', file)
  form.append('collection', collection)
  const { data } = await http.post<ApiEnvelope<Media>>('/media', form)
  return data.data
}

export async function deleteMedia(id: number): Promise<void> {
  await http.delete(`/media/${id}`)
}

export interface PaginatedMedia {
  items: Media[]
  currentPage: number
  lastPage: number
  total: number
}

export async function listMedia(params?: { collection?: string; page?: number }): Promise<PaginatedMedia> {
  const { data } = await http.get<ApiEnvelope<Media[]>>('/media', { params })
  const meta = data.meta ?? {}
  return {
    items: data.data,
    currentPage: Number(meta.current_page ?? 1),
    lastPage: Number(meta.last_page ?? 1),
    total: Number(meta.total ?? data.data.length),
  }
}
