import { defineStore } from 'pinia'

/**
 * Cart is a client-side *shopping list* only — product/variation id + qty,
 * plus a display snapshot (name/price/image) so the cart page can render
 * without refetching everything. It is never the source of truth for price,
 * fee, stock, or total: checkout sends only {product_id, product_variation_id,
 * quantity} to the backend, which recomputes and validates everything fresh
 * (see backend OrderService). This localStorage copy is a UI convenience,
 * not a stock/price ledger.
 */
export interface CartLine {
  key: string // `${productId}:${variationId ?? 'base'}`
  productId: number
  variationId: number | null
  productSlug: string
  name: string
  variationLabel: string | null
  unitPrice: number
  image: string | null
  quantity: number
  stockLimit: number | null // best-known ceiling at the moment it was added; re-validated server-side regardless
}

const STORAGE_KEY = 'pc-cart'

function loadFromStorage(): CartLine[] {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    return raw ? (JSON.parse(raw) as CartLine[]) : []
  } catch {
    return []
  }
}

function persist(lines: CartLine[]) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(lines))
  } catch {
    /* storage unavailable (private mode, quota) — cart just won't survive a refresh */
  }
}

export const useCartStore = defineStore('cart', {
  state: () => ({
    lines: loadFromStorage() as CartLine[],
  }),
  getters: {
    count: (state) => state.lines.reduce((sum, l) => sum + l.quantity, 0),
    subtotal: (state) => state.lines.reduce((sum, l) => sum + l.unitPrice * l.quantity, 0),
    isEmpty: (state) => state.lines.length === 0,
  },
  actions: {
    add(line: Omit<CartLine, 'quantity'>, quantity = 1) {
      const existing = this.lines.find((l) => l.key === line.key)
      if (existing) {
        existing.quantity = Math.min(existing.quantity + quantity, existing.stockLimit ?? 999)
      } else {
        this.lines.push({ ...line, quantity })
      }
      persist(this.lines)
    },
    updateQuantity(key: string, quantity: number) {
      const line = this.lines.find((l) => l.key === key)
      if (!line) return
      if (quantity <= 0) {
        this.remove(key)
        return
      }
      line.quantity = line.stockLimit ? Math.min(quantity, line.stockLimit) : quantity
      persist(this.lines)
    },
    remove(key: string) {
      this.lines = this.lines.filter((l) => l.key !== key)
      persist(this.lines)
    },
    clear() {
      this.lines = []
      persist(this.lines)
    },
  },
})
