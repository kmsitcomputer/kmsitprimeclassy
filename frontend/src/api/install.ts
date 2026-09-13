import { http, ensureCsrfCookie } from './client'
import type { ApiEnvelope } from './client'

export interface InstallStatus {
  installed: boolean
  has_tables: boolean
  has_super_admin: boolean
}

/** Always reachable, even before the database has any tables — used to decide whether to show the installer at all. */
export async function getInstallStatus() {
  const { data } = await http.get<ApiEnvelope<InstallStatus>>('/install/status')
  return data.data
}

export interface RequirementCheck {
  label: string
  ok: boolean
}

export interface RequirementsReport {
  php: { version: string; ok: boolean; minimum: string }
  extensions: RequirementCheck[]
  permissions: RequirementCheck[]
  all_ok: boolean
}

export async function getRequirements() {
  const { data } = await http.get<ApiEnvelope<RequirementsReport>>('/install/requirements')
  return data.data
}

export interface DatabaseConfig {
  host: string
  port: number
  database: string
  username: string
  password: string
}

export async function testDatabaseConnection(payload: DatabaseConfig) {
  await ensureCsrfCookie()
  await http.post('/install/database/test', payload)
}

export async function saveDatabaseConfig(payload: DatabaseConfig) {
  await ensureCsrfCookie()
  await http.post('/install/database/save', payload)
}

export interface AppConfig {
  app_name: string
  /** The app's own URL (Laravel APP_URL) — the same origin as frontend_url in the single-domain layout. */
  app_url: string
  /** The user-facing site origin (single domain, e.g. https://namadomainkamu.com). */
  frontend_url: string
}

export async function configureApp(payload: AppConfig) {
  await ensureCsrfCookie()
  await http.post('/install/configure-app', payload)
}

export interface SuperAdminPayload {
  name: string
  email: string
  phone: string
  password: string
  password_confirmation: string
}

/** Migrates, seeds, and creates the one and only Super Admin — the wizard's "Install Database" step. */
export async function runInstall(payload: SuperAdminPayload) {
  await ensureCsrfCookie()
  const { data } = await http.post<ApiEnvelope<{ id: number; name: string; email: string }>>('/install/run', payload)
  return data.data
}

export async function finalizeInstall() {
  await ensureCsrfCookie()
  const { data } = await http.post<
    ApiEnvelope<{ has_tables: boolean; has_super_admin: boolean; storage_linked: boolean }>
  >(
    '/install/finalize',
  )
  return data.data
}

/** Permanently seals every /install/* write endpoint. */
export async function lockInstall() {
  await ensureCsrfCookie()
  await http.post('/install/lock')
}
