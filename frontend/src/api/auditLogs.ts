import { http } from './client'
import type { ApiEnvelope } from './client'

export interface AuditLogEntry {
  id: number
  causer: { id: number; name: string; email: string } | null
  event: string
  description: string | null
  subject_type: string | null
  subject_id: number | null
  properties: Record<string, unknown> | null
  ip_address: string | null
  user_agent: string | null
  created_at: string
}

export interface AuditLogFilters {
  event?: string
  causer_id?: number
  from?: string
  to?: string
  page?: number
}

export interface PaginatedAuditLogs {
  items: AuditLogEntry[]
  currentPage: number
  lastPage: number
  total: number
}

export async function listAuditLogs(filters: AuditLogFilters = {}): Promise<PaginatedAuditLogs> {
  const { data } = await http.get<ApiEnvelope<AuditLogEntry[]>>('/admin/audit-logs', { params: filters })
  const meta = data.meta ?? {}
  return {
    items: data.data,
    currentPage: Number(meta.current_page ?? 1),
    lastPage: Number(meta.last_page ?? 1),
    total: Number(meta.total ?? data.data.length),
  }
}
