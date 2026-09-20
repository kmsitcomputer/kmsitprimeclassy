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
export interface GoogleMapsLatLng {
  lat: number
  lng: number
}

export interface GoogleMapsClickEvent {
  latLng: {
    lat(): number
    lng(): number
  }
}

export interface GoogleMapInstance {
  panTo(position: GoogleMapsLatLng): void
  setCenter(position: GoogleMapsLatLng): void
  addListener(event: 'click', handler: (e: GoogleMapsClickEvent) => void): void
}

export interface GoogleMarkerInstance {
  position: GoogleMapsLatLng
  addListener(event: string, handler: () => void): void
}

export interface GooglePlaceLocation {
  lat(): number
  lng(): number
}

export interface GooglePlace {
  fetchFields(options: { fields: string[] }): Promise<{
    location?: GooglePlaceLocation | null
    formattedAddress?: string
    displayName?: string
  } | null>
  location?: GooglePlaceLocation | null
  formattedAddress?: string
  displayName?: string
}

export type PlaceAutocompleteElement = HTMLElement & {
  addEventListener(type: 'gmp-select', listener: (event: Event) => void): void
}

export interface GoogleMapsNamespace {
  maps: {
    Map: new (element: HTMLElement, options?: Record<string, unknown>) => GoogleMapInstance
    importLibrary(name: string): Promise<Record<string, unknown>>
    marker: {
      AdvancedMarkerElement: new (options: {
        map: GoogleMapInstance
        position: GoogleMapsLatLng
        gmpDraggable?: boolean
      }) => GoogleMarkerInstance
    }
  }
}

type GoogleNamespace = GoogleMapsNamespace

let loadPromise: Promise<GoogleNamespace> | null = null

export function isGoogleMapsConfigured(): boolean {
  return Boolean(import.meta.env.VITE_GOOGLE_MAPS_API_KEY)
}

/**
 * Google's auth-failure callback (`window.gm_authFailure`) fires for
 * RefererNotAllowedMapError / InvalidKeyMapError / ApiNotActivatedMapError /
 * billing-disabled — all AFTER the base script has already loaded
 * successfully (loadGoogleMaps()'s own promise already resolved by then), so
 * this is the only hook that ever tells us about them. It fires as a side
 * effect of the page's first `new google.maps.Map(...)` call, asynchronously
 * and outside any promise/try-catch a caller could wrap around that call —
 * consumers (AddressMapPicker) subscribe here instead of expecting an
 * exception that Google never throws for this failure class.
 */
const authFailureListeners = new Set<() => void>()

export function onGoogleMapsAuthFailure(listener: () => void): () => void {
  authFailureListeners.add(listener)
  return () => authFailureListeners.delete(listener)
}

export function loadGoogleMaps(): Promise<GoogleNamespace> {
  if (loadPromise) return loadPromise

  loadPromise = new Promise((resolve, reject) => {
    const key = import.meta.env.VITE_GOOGLE_MAPS_API_KEY as string | undefined
    if (!key) {
      reject(new Error('VITE_GOOGLE_MAPS_API_KEY is not configured'))
      return
    }

    ;(window as unknown as { gm_authFailure?: () => void }).gm_authFailure = () => {
      authFailureListeners.forEach((listener) => listener())
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
