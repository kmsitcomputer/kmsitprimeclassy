import { defineStore } from 'pinia'
import * as installApi from '@/api/install'

export const useInstallStore = defineStore('install', {
  state: () => ({
    installed: true, // safe default — never flash the installer while the real check is in flight
    checked: false,
  }),
  actions: {
    async check() {
      try {
        const status = await installApi.getInstallStatus()
        this.installed = status.installed
      } catch {
        // If the status endpoint itself is unreachable, assume installed
        // rather than trapping a live site behind a broken installer check.
        this.installed = true
      } finally {
        this.checked = true
      }
    },
  },
})
