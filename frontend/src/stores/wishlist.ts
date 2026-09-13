import { defineStore } from 'pinia'

export interface WishlistItem {
  productId: number
  slug: string
}

/**
 * The backend has no wishlist table (see backend/database.sql) — this is a
 * client-only convenience, per-browser, not synced across devices. If a
 * server-side wishlist is added later, this store's shape can stay the same
 * and just swap persist()/load() for API calls.
 */
const STORAGE_KEY = 'pc-wishlist'

function loadFromStorage(): WishlistItem[] {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    return raw ? (JSON.parse(raw) as WishlistItem[]) : []
  } catch {
    return []
  }
}

function persist(items: WishlistItem[]) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(items))
  } catch {
    /* non-fatal */
  }
}

export const useWishlistStore = defineStore('wishlist', {
  state: () => ({
    items: loadFromStorage() as WishlistItem[],
  }),
  getters: {
    count: (state) => state.items.length,
    has: (state) => (productId: number) => state.items.some((i) => i.productId === productId),
  },
  actions: {
    toggle(item: WishlistItem) {
      if (this.has(item.productId)) {
        this.items = this.items.filter((i) => i.productId !== item.productId)
      } else {
        this.items = [...this.items, item]
      }
      persist(this.items)
    },
  },
})
