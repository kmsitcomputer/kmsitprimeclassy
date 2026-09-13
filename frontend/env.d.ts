/// <reference types="vite/client" />

// See public/config.js — a plain script tag loaded before the app bundle so
// the backend API URL can be changed on the deployed server without a rebuild.
interface Window {
  __APP_CONFIG__?: { API_URL?: string }
}
