import { http } from './client'
import type { ApiEnvelope } from './client'

/**
 * IMP-001 — Google Auth configuration surfaces.
 *
 * Super Admin manages the GLOBAL/default config via /admin/google-auth
 * (GoogleAuthSettingController — `manage-system-config` gate). Each Agen
 * manages its OWN branch config via /agent/google-auth
 * (AgentGoogleAuthConfigController — the agent_id is always the
 * authenticated user's own branch; never from input, so one branch can
 * never read/write another's).
 *
 * Both endpoints share the exact same response shape (below) and both never
 * echo the client secret — the API only reports whether one is stored, via
 * `has_secret`. A blank `client_secret` on save PRESERVES the stored one;
 * `clear_secret: true` explicitly removes it. The frontend must never
 * display or store the secret itself.
 */
export interface GoogleAuthConfigView {
  /** Whether Google sign-in is enabled for this scope (global / branch). */
  is_enabled: boolean
  /** The OAuth Client ID (not secret — displayed plain). */
  client_id: string | null
  /** Browser-facing callback/redirect path for the Google OAuth app. */
  redirect_uri: string | null
  /** SPA origin the browser lands on after the callback ('' = same origin). */
  frontend_url: string | null
  /** True when a client secret is already stored server-side — the secret itself is NEVER returned. */
  has_secret: boolean
}

export interface GoogleAuthConfigPayload {
  is_enabled?: boolean
  client_id?: string | null
  client_secret?: string | null
  redirect_uri?: string | null
  frontend_url?: string | null
  /** Explicit opt-in to remove the stored secret (blank field alone never clears it). */
  clear_secret?: boolean
}

/** Super Admin only — the global/default Google Auth configuration (backend gate: manage-system-config). */
export async function getGlobalGoogleAuthConfig() {
  const { data } = await http.get<ApiEnvelope<GoogleAuthConfigView>>('/admin/google-auth')
  return data.data
}

/** Super Admin only — save the global/default Google Auth configuration. */
export async function saveGlobalGoogleAuthConfig(payload: GoogleAuthConfigPayload) {
  const { data } = await http.put<ApiEnvelope<GoogleAuthConfigView>>('/admin/google-auth', payload)
  return data.data
}

/** An Agen's own branch Google Auth configuration (never another branch's). */
export async function getAgentGoogleAuthConfig() {
  const { data } = await http.get<ApiEnvelope<GoogleAuthConfigView>>('/agent/google-auth')
  return data.data
}

/** An Agen's own branch Google Auth configuration (never another branch's). */
export async function saveAgentGoogleAuthConfig(payload: GoogleAuthConfigPayload) {
  const { data } = await http.put<ApiEnvelope<GoogleAuthConfigView>>('/agent/google-auth', payload)
  return data.data
}