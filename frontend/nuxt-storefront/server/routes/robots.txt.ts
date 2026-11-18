/** /robots.txt — keep crawlers out of the authenticated/operational surfaces and the API; advertise the sitemap. */
export default defineEventHandler((event) => {
  const config = useRuntimeConfig(event)
  const origin = String(config.public.siteUrl || '').replace(/\/+$/, '') || getRequestURL(event).origin
  setResponseHeader(event, 'content-type', 'text/plain; charset=utf-8')
  return [
    'User-agent: *',
    'Disallow: /api/',
    'Disallow: /sanctum/',
    'Disallow: /dashboard',
    'Disallow: /admin',
    'Disallow: /setup',
    'Disallow: /login',
    'Disallow: /register',
    'Disallow: /forgot-password',
    'Disallow: /cart',
    'Disallow: /checkout',
    'Disallow: /wishlist',
    'Disallow: /profile',
    'Disallow: /orders',
    'Disallow: /account',
    'Disallow: /print',
    'Allow: /',
    `Sitemap: ${origin}/sitemap.xml`,
    '',
  ].join('\n')
})
