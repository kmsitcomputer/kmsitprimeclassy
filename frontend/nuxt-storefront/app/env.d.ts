// Same runtime-config global the SPA declares in ../env.d.ts (public/config.js, loaded by a blocking <script> in <head>).
export {}
declare global {
  interface Window {
    __APP_CONFIG__?: { API_URL?: string }
  }
}
