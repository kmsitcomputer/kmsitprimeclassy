/**
 * Lazily loads the Google Maps JS API (Places + Marker) exactly once per
 * page load. Invoked whenever a Google Maps API key is configured — see
 * CheckoutView's Address step (AddressMapPicker), available regardless of
 * which shipping method is active. The API key is a build-time Vite env var
 * (VITE_GOOGLE_MAPS_API_KEY); it is not a secret (Google Maps JS keys are
 * restricted by HTTP referrer, not by staying hidden) — see .env.example.
 *
 * Typed loosely (no @types/google.maps dependency) since this project only
 * touches a handful of Maps/Places APIs directly.
 */
type GoogleNamespace = Record<string, any>

let loadPromise: Promise<GoogleNamespace> | null = null

export function isGoogleMapsConfigured(): boolean {
  return Boolean(import.meta.env.VITE_GOOGLE_MAPS_API_KEY)
}

export function loadGoogleMaps(): Promise<GoogleNamespace> {
  if (loadPromise) return loadPromise

  loadPromise = new Promise((resolve, reject) => {
    const key = import.meta.env.VITE_GOOGLE_MAPS_API_KEY as string | undefined
    if (!key) {
      reject(new Error('VITE_GOOGLE_MAPS_API_KEY is not configured'))
      return
    }

    const callbackName = '__onGoogleMapsLoaded'
    ;(window as unknown as Record<string, () => void>)[callbackName] = () => {
      resolve((window as unknown as { google: GoogleNamespace }).google)
    }

    const script = document.createElement('script')
    script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(key)}&libraries=places,marker&callback=${callbackName}&loading=async`
    script.async = true
    script.onerror = () => reject(new Error('Failed to load Google Maps JS API'))
    document.head.appendChild(script)
  })

  return loadPromise
}
