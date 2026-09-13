import { defineStore } from 'pinia'
import * as authApi from '@/api/auth'
import type { AuthUser } from '@/api/types'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null as AuthUser | null,
    permissions: [] as string[],
    ready: false, // true once the initial "am I logged in?" check has resolved
  }),
  getters: {
    isLoggedIn: (state) => state.user !== null,
    isKonsumen: (state) => state.user?.role === 'konsumen',
    can: (state) => (capability: string) => state.permissions.includes(capability),
  },
  actions: {
    async bootstrap() {
      try {
        const payload = await authApi.me()
        this.user = payload.user
        this.permissions = payload.permissions
      } catch {
        this.user = null
        this.permissions = []
      } finally {
        this.ready = true
      }
    },
    async login(email: string, password: string) {
      const payload = await authApi.login(email, password)
      this.user = payload.user
      this.permissions = payload.permissions
    },
    async register(data: authApi.RegisterPayload) {
      const payload = await authApi.register(data)
      this.user = payload.user
      this.permissions = payload.permissions
    },
    async logout() {
      await authApi.logout()
      this.user = null
      this.permissions = []
    },
    /** Re-fetches the current user (e.g. after profile/avatar edits) without disturbing `ready`. */
    async refresh() {
      const payload = await authApi.me()
      this.user = payload.user
      this.permissions = payload.permissions
    },
  },
})
