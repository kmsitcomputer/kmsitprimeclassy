// Runtime configuration for the SINGLE-DOMAIN deployment layout — frontend
// and backend share one document root, so the API is same-origin and no
// base URL is needed. Edited directly on the server after upload if this
// ever needs to change; no rebuild required (see frontend/src/api/client.ts).
window.__APP_CONFIG__ = {
  API_URL: '',
}
