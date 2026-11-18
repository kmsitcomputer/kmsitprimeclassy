/**
 * /healthz — liveness for process supervisors and the front proxy. Cheap and dependency-free by default (the process
 * answers ⇒ the renderer is up). `?deep=1` additionally probes the Laravel API through the same internal base the
 * renderer uses (GET /up), for readiness checks. Never exposes configuration, versions or user data.
 */
export default defineEventHandler(async (event) => {
  setResponseHeader(event, 'cache-control', 'no-store')
  setResponseHeader(event, 'content-type', 'application/json; charset=utf-8')
  if (getQuery(event).deep !== '1') return { status: 'ok' }

  const base = String(useRuntimeConfig(event).apiInternalBase).replace(/\/+$/, '')
  try {
    await $fetch(`${base}/up`, { timeout: 3000, retry: 0, responseType: 'text' })
    return { status: 'ok', backend: 'ok' }
  } catch {
    setResponseStatus(event, 503)
    return { status: 'degraded', backend: 'unreachable' }
  }
})
