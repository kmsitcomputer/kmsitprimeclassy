// Runtime configuration — edited directly on the server after upload, no rebuild needed.
// The app runs on a SINGLE domain: the SPA and /api share one origin, so the default is
// an empty string (relative, same-origin requests). Only set a full URL here if you
// deliberately serve the API from a different origin — the single-domain deployment
// layout shipped by deploy/ does not need it.
window.__APP_CONFIG__ = {
  API_URL: '',
}
