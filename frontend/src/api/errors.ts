/**
 * Canonical API error type (neutral module).
 *
 * Lives here — not in `api/client.ts` — so modules that only need the error
 * type (e.g. `pwa/connectivity.ts`) never import the Axios client, keeping
 * the dependency graph acyclic:
 *
 *   api/errors.ts  <-  api/client.ts  <-  api/*.ts, views/*
 *   api/errors.ts  <-  pwa/connectivity.ts  <-  api/client.ts
 *
 * This module MUST NOT import client.ts or connectivity.ts.
 */
export class ApiError extends Error {
  status: number
  errors: Record<string, string[]> | null

  constructor(message: string, status: number, errors: Record<string, string[]> | null) {
    super(message)
    this.status = status
    this.errors = errors
  }
}
