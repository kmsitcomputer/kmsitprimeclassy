import { http } from './client'
import type { ApiEnvelope } from './client'
import type { PaginationMeta } from './types'

export interface CommissionRow {
  id: number
  order_id: number
  order_item_id: number
  beneficiary_user_id: number
  beneficiary_role: string
  amount: string
  status: string
  earned_at: string
  paid_at: string | null
}

export async function listCommissions(page = 1, filters: { from?: string; to?: string; status?: string; agent_id?: number } = {}) {
  const { data } = await http.get<ApiEnvelope<CommissionRow[]>>('/commissions', { params: { page, ...filters } })
  return { rows: data.data, meta: data.meta as unknown as PaginationMeta }
}

export interface CommissionSummary {
  breakdown: { beneficiary_role: string; status: string; total_amount: string; count: number }[]
  // Each key is present only when the viewer's role is allowed to see that
  // fee type at all (see backend CommissionController::allowedBeneficiaryRoles)
  // — absence means "never show this category", not "zero".
  total_agent_fee?: number
  total_sales_fee?: number
  total_courier_fee?: number
}

export async function commissionSummary(filters: { from?: string; to?: string; agent_id?: number } = {}) {
  const { data } = await http.get<ApiEnvelope<CommissionSummary>>('/commissions/summary', { params: filters })
  return data.data
}
