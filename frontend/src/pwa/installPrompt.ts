import { computed, ref, shallowRef } from 'vue'

/**
 * Central PWA install-prompt state (PWA V1.1 install button).
 *
 * Frontend-only: captures the browser-native `beforeinstallprompt` event
 * once, exposes install availability to Vue components, and clears the
 * saved prompt after `userChoice` settles. No APK/download — the native
 * browser install mechanism is used everywhere.
 *
 * Hiding rules (locked):
 * - Standalone display mode (installed PWA window) → hidden.
 * - `appinstalled` has fired → hidden.
 * - No saved `beforeinstallprompt` → hidden, except the conservative iOS
 *   guidance path (iOS has no `beforeinstallprompt`; the button opens a
 *   small "Share → Add to Home Screen" instruction instead).
 */

export interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>
}

export type InstallOutcome = 'accepted' | 'dismissed' | 'unavailable'

const deferredPrompt = shallowRef<BeforeInstallPromptEvent | null>(null)
const installed = ref(false)
const standalone = ref(false)
const showIosGuide = ref(false)

let listenersRegistered = false

function detectStandalone(): boolean {
  if (typeof window === 'undefined') return false
  try {
    if (typeof window.matchMedia === 'function') {
      if (window.matchMedia('(display-mode: standalone)').matches) return true
      if (window.matchMedia('(display-mode: fullscreen)').matches) return true
    }
  } catch {
    // matchMedia unavailable — fall through to the iOS flag below.
  }
  // iOS Safari standalone flag (not in the TS DOM lib).
  return (window.navigator as Navigator & { standalone?: boolean }).standalone === true
}

/**
 * Conservative iPhone/iPad detection. iPadOS 13+ reports as MacIntel with
 * touch support, so that case is covered too. This only gates the manual
 * "Add to Home Screen" guidance — it never fakes an installation.
 */
export function isIosDevice(): boolean {
  if (typeof navigator === 'undefined') return false
  const ua = navigator.userAgent || ''
  if (/iPhone|iPad|iPod/i.test(ua)) return true
  try {
    if (
      navigator.platform === 'MacIntel' &&
      (navigator as Navigator & { maxTouchPoints?: number }).maxTouchPoints !== undefined &&
      ((navigator as Navigator & { maxTouchPoints?: number }).maxTouchPoints ?? 0) > 1
    ) {
      return true
    }
  } catch {
    // Platform detection unavailable — treat as non-iOS.
  }
  return false
}

/**
 * Registers the global `beforeinstallprompt` / `appinstalled` listeners.
 * Idempotent — safe to call once from `main.ts`. Must run before any
 * component reads the composable for the prompt to be captured reliably.
 */
export function setupPwaInstallListeners(): void {
  if (listenersRegistered || typeof window === 'undefined') return
  listenersRegistered = true

  standalone.value = detectStandalone()
  if (standalone.value) installed.value = true

  window.addEventListener('beforeinstallprompt', (event) => {
    // Retain the event so the install button can trigger it later on
    // explicit user gesture. Never auto-prompt.
    event.preventDefault()
    if (installed.value || standalone.value) return
    deferredPrompt.value = event as BeforeInstallPromptEvent
  })

  window.addEventListener('appinstalled', () => {
    installed.value = true
    deferredPrompt.value = null
    showIosGuide.value = false
  })
}

/** Native install is available: a prompt was captured and we are not installed/standalone. */
export const canInstall = computed(
  () => deferredPrompt.value !== null && !installed.value && !standalone.value,
)

/** iOS fallback: no native prompt exists, so offer manual guidance instead. */
export const showIosInstallOption = computed(
  () =>
    deferredPrompt.value === null &&
    !installed.value &&
    !standalone.value &&
    isIosDevice(),
)

// Visible whenever either the native prompt or the iOS guidance applies.
export const showInstallAction = computed(
  () => canInstall.value || showIosInstallOption.value,
)

/**
 * Invokes the saved native install prompt and awaits `userChoice`.
 * Clears the saved prompt afterwards in every outcome so a stale event
 * is never reused. Returns the user outcome (`unavailable` when there is
 * no saved prompt to invoke).
 */
export async function promptInstall(): Promise<InstallOutcome> {
  const event = deferredPrompt.value
  if (!event) return 'unavailable'
  try {
    await event.prompt()
    const choice = await event.userChoice
    if (choice?.outcome === 'accepted') installed.value = true
    return choice?.outcome ?? 'dismissed'
  } catch {
    return 'dismissed'
  } finally {
    deferredPrompt.value = null
  }
}

export function openIosGuide(): void {
  showIosGuide.value = true
}

export function closeIosGuide(): void {
  showIosGuide.value = false
}

/** Clean state access for Vue components. All refs are module singletons. */
export function usePwaInstall() {
  return {
    canInstall,
    showIosInstallOption,
    showInstallAction,
    isInstalled: installed,
    isStandalone: standalone,
    showIosGuide,
    promptInstall,
    openIosGuide,
    closeIosGuide,
  }
}
