import { http } from './client'
import type { ApiEnvelope } from './client'
import type { HomepageBlock } from './cms'

export interface AdminHomepageBlock extends HomepageBlock {
  image_url: string | null
  sort_order: number
  is_active: boolean
  starts_at: string | null
  ends_at: string | null
}

export async function listAdminBlocks(): Promise<AdminHomepageBlock[]> {
  const { data } = await http.get<ApiEnvelope<AdminHomepageBlock[]>>('/cms/homepage-blocks')
  return data.data
}

/** No dedicated show-by-id admin endpoint exists — the admin list is small, so an edit page just filters it by id. */
export async function getBlock(id: number): Promise<AdminHomepageBlock | undefined> {
  const blocks = await listAdminBlocks()
  return blocks.find((b) => b.id === id)
}

export async function createBlock(form: FormData): Promise<AdminHomepageBlock> {
  const { data } = await http.post<ApiEnvelope<AdminHomepageBlock>>('/cms/homepage-blocks', form)
  return data.data
}

export async function updateBlock(id: number, form: FormData): Promise<AdminHomepageBlock> {
  form.append('_method', 'PATCH')
  const { data } = await http.post<ApiEnvelope<AdminHomepageBlock>>(`/cms/homepage-blocks/${id}`, form)
  return data.data
}

export async function deleteBlock(id: number): Promise<void> {
  await http.delete(`/cms/homepage-blocks/${id}`)
}

export async function toggleBlock(id: number): Promise<AdminHomepageBlock> {
  const { data } = await http.patch<ApiEnvelope<AdminHomepageBlock>>(`/cms/homepage-blocks/${id}/toggle`)
  return data.data
}

export async function reorderBlocks(blockIds: number[]): Promise<AdminHomepageBlock[]> {
  const { data } = await http.patch<ApiEnvelope<AdminHomepageBlock[]>>('/cms/homepage-blocks/reorder', { block_ids: blockIds })
  return data.data
}
