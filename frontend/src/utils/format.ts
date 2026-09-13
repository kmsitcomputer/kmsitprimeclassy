import { i18n } from '@/i18n'

export function formatRupiah(value: string | number): string {
  const num = typeof value === 'string' ? parseFloat(value) : value
  if (Number.isNaN(num)) return 'Rp0'
  // Always Indonesian Rupiah regardless of UI language — this is a currency
  // fact of the business, not a translation choice.
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(num)
}

/** Maps our UI locale to an Intl locale tag; Arabic pinned to Latin digits so dates don't mix numeral systems with the rest of the (LTR-number) UI. */
const INTL_LOCALES: Record<string, string> = {
  id: 'id-ID',
  en: 'en-US',
  zh: 'zh-CN',
  ar: 'ar-u-nu-latn',
}

/**
 * Human-readable business/report date — "13 September 2026" in Indonesian
 * (default locale), never a time-of-day. Database timestamps stay untouched;
 * this is presentation only. Use formatDateTime() for audit/security/technical
 * logs where the exact time matters.
 */
export function formatDate(value: string | null | undefined): string {
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '-'
  const locale = INTL_LOCALES[i18n.global.locale.value] ?? 'id-ID'
  return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long', year: 'numeric' }).format(date)
}

/**
 * Date + time — reserved for audit/security/technical logs (audit trail, sync
 * logs, payment callbacks) where precision matters. Business dashboards and
 * reports must use formatDate() instead.
 */
export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '-'
  const locale = INTL_LOCALES[i18n.global.locale.value] ?? 'id-ID'
  return new Intl.DateTimeFormat(locale, {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}

/** Product display helper: always pair a product name with its SKU (or '-' when historical data has none). */
export function skuLabel(sku: string | null | undefined): string {
  return `SKU: ${sku && sku.trim() !== '' ? sku : '-'}`
}

export function orderStatusLabel(status: string): string {
  const key = `orders.status.${status}`
  const translated = i18n.global.t(key)
  return translated === key ? status : translated
}

export function paymentStatusLabel(status: string): string {
  const key = `orders.paymentStatus.${status}`
  const translated = i18n.global.t(key)
  return translated === key ? status : translated
}
