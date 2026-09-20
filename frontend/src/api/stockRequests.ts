import { http } from './client'
import type { ApiEnvelope } from './client'

export interface StockRequest { id: number; request_number: string; order_id: number; status: string; items: { id: number; sku_snapshot: string | null; requested_qty: number; fulfilled_qty: number; remaining_qty: number }[] }
export async function listStockRequests() { return (await http.get<ApiEnvelope<StockRequest[]>>('/warehouse/stock-requests')).data.data }
export async function fulfillStockRequest(id: number, idempotencyKey: string, items: { item_id: number; quantity: number }[]) { return (await http.post<ApiEnvelope<StockRequest>>(`/warehouse/stock-requests/${id}/fulfill`, { idempotency_key: idempotencyKey, items })).data.data }