import { http } from './client'
import type { ApiEnvelope } from './client'

/** Operator in-app notification centre (Super Admin, Agen, Admin). The API only ever returns the caller's own rows. */

export type AdminNotificationSeverity = 'info' | 'warning' | 'critical'

export interface AdminNotification {
  id: number
  type: string
  severity: AdminNotificationSeverity
  title: string
  body: string
  order_id: number | null
  read_at: string | null
  created_at: string | null
}

export interface AdminNotificationMeta {
  unread_count: number
  total: number
  current_page?: number
  last_page?: number
  migration_pending?: boolean
}

export const ADMIN_NOTIFICATION_ROLES = ['super_admin', 'agen', 'admin'] as const

const base = '/notifications/admin'

export async function listAdminNotifications(params: { unread?: boolean; page?: number; per_page?: number } = {}) {
  const { data } = await http.get<ApiEnvelope<AdminNotification[], AdminNotificationMeta>>(base, {
    params: { ...params, unread: params.unread ? 1 : undefined },
  })
  return { items: data.data, meta: data.meta ?? { unread_count: 0, total: 0 } }
}

export async function getAdminNotificationUnreadCount() {
  const { data } = await http.get<ApiEnvelope<{ unread_count: number }>>(`${base}/unread-count`)
  return data.data.unread_count
}

export async function markAdminNotificationRead(id: number) {
  const { data } = await http.post<ApiEnvelope<{ id: number; read_at: string }>>(`${base}/${id}/read`)
  return data.data
}

export async function markAllAdminNotificationsRead() {
  const { data } = await http.post<ApiEnvelope<{ updated: number }>>(`${base}/read-all`)
  return data.data.updated
}
