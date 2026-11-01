/**
 * Defence in depth for CMS links. The backend (App\Rules\SafeLinkUrl) already refuses anything that is not
 * http(s) / mailto: / tel: / a site-relative path / an in-page anchor, but blocks saved before that rule
 * existed are still in the database. Every CMS block therefore passes its admin-typed link through here
 * before putting it in an href, so a stored `javascript:` or `data:` link renders as "no link".
 */
const SAFE_LINK = /^(?:https?:\/\/[^\s<>"'\\]+|mailto:[^\s<>"'\\]+|tel:\+?[0-9][0-9\-\s().]{3,30}|\/(?!\/)[^\s<>"'\\]*|#[^\s<>"'\\]*)$/i

export function safeHref(url: string | null | undefined): string | undefined {
  if (typeof url !== 'string') return undefined
  const value = url.trim()
  if (!value || /[\u0000-\u001F\u007F]/.test(value)) return undefined
  return SAFE_LINK.test(value) ? value : undefined
}

/** True for absolute http(s) links, which should not be opened with an opener reference. */
export function isExternalHref(href: string | undefined): boolean {
  return !!href && /^https?:\/\//i.test(href)
}
