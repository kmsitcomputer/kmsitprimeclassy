/**
 * Request identity for "only the newest response may apply" flows (checkout delivery quote).
 * `begin()` issues a token; `invalidate()` (destination changed) retires every outstanding token
 * so a late response can neither overwrite newer state nor leave a loading flag stuck.
 */
export function createLatestRequestGuard() {
  let seq = 0
  return {
    begin: (): number => ++seq,
    isCurrent: (token: number): boolean => token === seq,
    invalidate: (): void => {
      seq++
    },
  }
}
