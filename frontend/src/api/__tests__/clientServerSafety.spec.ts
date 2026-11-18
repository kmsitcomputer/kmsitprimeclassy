// @vitest-environment node
import { describe, expect, it } from 'vitest'

// No `window` here: this is how @/api/client is evaluated on the Nuxt server.
describe('API client during server-side rendering', () => {
  it('has no window, reads the configured internal base, and sends no credentials', async () => {
    expect(typeof window).toBe('undefined')
    const { http, configureServerApi } = await import('../client')
    configureServerApi('http://backend.internal:8000/')
    const seen: Record<string, unknown> = {}
    await http.get('/products', {
      adapter: async (config) => {
        seen.baseURL = config.baseURL
        seen.withCredentials = config.withCredentials
        seen.xLocale = config.headers?.['X-Locale']
        return { data: { success: true }, status: 200, statusText: 'OK', headers: {}, config }
      },
    })
    expect(seen.baseURL).toBe('http://backend.internal:8000/api/v1')
    expect(seen.withCredentials).toBe(false)
    expect(seen.xLocale).toBeUndefined()
  }, 30000)

  it('refuses every mutating request on the server before it can reach the network', async () => {
    const { http } = await import('../client')
    let reachedNetwork = 0
    for (const method of ['post', 'put', 'patch', 'delete'] as const) {
      await expect(
        http.request({ method, url: '/auth/login', data: {}, adapter: async () => { reachedNetwork++; throw new Error('must not reach the network') } }),
      ).rejects.toBeTruthy()
    }
    expect(reachedNetwork).toBe(0)
  })

  it('sends the CURRENT request locale as X-Locale (per-request, from the request context)', async () => {
    const { http } = await import('../client')
    const { setRequestContextResolver } = await import('@/utils/requestContext')
    setRequestContextResolver(() => ({ locale: 'en', i18n: {} }))
    let sent: unknown
    await http.get('/products', { adapter: async (config) => { sent = config.headers?.['X-Locale']; return { data: {}, status: 200, statusText: 'OK', headers: {}, config } } })
    setRequestContextResolver(null)
    expect(sent).toBe('en')
  }, 30000)
})
