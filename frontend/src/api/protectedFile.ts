import axios from 'axios'
import { http, ApiError } from './client'

export type ProtectedFileErrorKind = 'unauthenticated' | 'forbidden' | 'not_found' | 'network' | 'unknown'

export class ProtectedFileError extends Error {
  constructor(public readonly kind: ProtectedFileErrorKind) {
    super(kind)
  }
}

export interface ProtectedFile {
  blob: Blob
  mime: string
  filename: string
}

/**
 * Server-generated file URLs may be absolute (http://host/api/v1/...) which breaks behind HTTPS
 * (mixed content) and can leak the wrong origin. Reduce them to a path relative to the
 * authenticated client's `/api/v1` base, so the request is always same-origin with the session.
 */
export function toApiRelativePath(url: string): string {
  const path = new URL(url, window.location.origin).pathname
  const match = path.match(/^\/api\/v1(\/payment-proofs\/\d+)$/)
  if (!match) throw new ProtectedFileError('unknown')
  return match[1]!
}

function extensionFor(mime: string): string {
  if (mime === 'application/pdf') return 'pdf'
  if (mime === 'image/png') return 'png'
  if (mime === 'image/webp') return 'webp'
  if (mime.startsWith('image/')) return 'jpg'
  return 'bin'
}

/** Fetch a private file through the authenticated API client (session cookie, authorization re-checked by the server). */
export async function fetchProtectedFile(url: string, baseName = 'bukti'): Promise<ProtectedFile> {
  try {
    const { data } = await http.get<Blob>(toApiRelativePath(url), { responseType: 'blob' })
    const mime = data.type || 'application/octet-stream'
    return { blob: data, mime, filename: `${baseName}.${extensionFor(mime)}` }
  } catch (e) {
    if (e instanceof ApiError || axios.isAxiosError(e)) {
      const status = e instanceof ApiError ? e.status : e.response?.status
      if (status === 401) throw new ProtectedFileError('unauthenticated')
      if (status === 403) throw new ProtectedFileError('forbidden')
      if (status === 404) throw new ProtectedFileError('not_found')
      if (!status) throw new ProtectedFileError('network')
    }
    throw new ProtectedFileError('unknown')
  }
}
