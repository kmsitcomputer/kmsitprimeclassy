#!/usr/bin/env node
/**
 * PrimeClassy PWA build step (ZERO-DEPENDENCY — no vite-plugin-pwa/Workbox).
 *
 * Runs AFTER `vite build` (see package.json `build-only`). Reads the emitted
 * `dist/`, builds the version-pinned precache list, stamps it into the
 * service-worker template (`src-pwa/sw.js`) and writes `dist/sw.js`.
 *
 * Fails the build if the manifest references an icon missing from `dist/`.
 * `src-pwa/` and this script are outside `tsconfig.app.json`, so
 * `vue-tsc --build` is unaffected.
 */
import { createHash } from 'node:crypto'
import { existsSync, mkdirSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs'
import { dirname, join, relative, sep } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const frontendDir = dirname(here)
const distDir = join(frontendDir, 'dist')
const templatePath = join(frontendDir, 'src-pwa', 'sw.js')

function fail(message) {
  console.error(`[build-pwa] ERROR: ${message}`)
  process.exit(1)
}

if (!existsSync(distDir)) fail('dist/ not found — run `vite build` first.')
if (!existsSync(templatePath)) fail('src-pwa/sw.js template not found.')

// Collect every emitted file under dist/assets (hashed, content-addressed)
// plus the fixed shell entries. All URLs are same-origin absolute paths.
//
// PWA icons are release derivatives of the SINGLE canonical branding
// source: Admin → Pengaturan Website → Favicon (site_favicon_media_id).
// public/icons/* must be regenerated from the current canonical favicon on
// the frontend release after any favicon change — see docs/OPERATIONS.md.
// There is no second PWA branding setting.
// NOTE (audit F-02): public/config.js is RUNTIME production configuration —
// edited on the deployed server without rebuilding. It is NEVER precached
// and is bypassed network-only by the service worker, so it stays
// runtime-editable.
const precache = ['/', '/index.html', '/favicon.ico', '/manifest.webmanifest']
const fixedShell = [
  'icons/icon-192.png',
  'icons/icon-512.png',
  'icons/icon-maskable-192.png',
  'icons/icon-maskable-512.png',
  'icons/apple-touch-icon.png',
]

function walk(dir) {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry)
    if (statSync(full).isDirectory()) {
      walk(full)
    } else {
      const rel = relative(distDir, full).split(sep).join('/')
      if (rel.startsWith('assets/')) precache.push(`/${rel}`)
    }
  }
}
walk(distDir)

for (const file of fixedShell) {
  if (!existsSync(join(distDir, file))) fail(`shell file missing from dist/: ${file}`)
  precache.push(`/${file}`)
}

// The manifest must only reference icons that actually shipped.
const manifestRaw = readFileSync(join(distDir, 'manifest.webmanifest'), 'utf8')
let manifest
try {
  manifest = JSON.parse(manifestRaw)
} catch {
  fail('dist/manifest.webmanifest is not valid JSON.')
}
for (const icon of manifest.icons ?? []) {
  const diskPath = join(distDir, String(icon.src).replace(/^\//, ''))
  if (!existsSync(diskPath)) fail(`manifest icon missing from dist/: ${icon.src}`)
}

// Version-pin the cache: the fingerprint mixes the emitted hashed-asset
// identities (content-addressed names) with the ACTUAL CONTENT BYTES of
// every fixed-name precached artifact (audit F-03). A fixed-name file
// (index.html, favicon, manifest, icons) can change content without its URL
// changing, so URL strings alone are insufficient — any byte change in any
// of these files yields a new CACHE_NAME, and a new build can never serve
// a mixed old/new shell. config.js is NOT precached (audit F-02) and is
// therefore excluded from the fingerprint.
const fixedContentFiles = ['index.html', 'favicon.ico', 'manifest.webmanifest', ...fixedShell]
const fixedBuffers = fixedContentFiles.map((file) => {
  const diskPath = join(distDir, file)
  if (!existsSync(diskPath)) fail(`versioned shell file missing from dist/: ${file}`)
  return { file, bytes: readFileSync(diskPath) }
})

function fingerprint(flipFirstByte) {
  const hash = createHash('sha256')
  for (const url of [...precache].sort()) hash.update(`url:${url}\n`)
  for (const { file, bytes } of fixedBuffers) {
    hash.update(`file:${file}:${bytes.length}\n`)
    if (flipFirstByte && bytes.length > 0) {
      const flipped = Buffer.from(bytes)
      flipped[0] ^= 0xff
      hash.update(flipped)
    } else {
      hash.update(bytes)
    }
  }
  return hash.digest('hex').slice(0, 16)
}

const version = fingerprint(false)

// Build-time self-check (in-memory only — no repository file is mutated):
// flipping a single byte of fixed shell content MUST change the version,
// proving the fingerprint is content-sensitive.
if (fingerprint(true) === version) {
  fail('version fingerprint is insensitive to fixed shell content.')
}

const template = readFileSync(templatePath, 'utf8')
if (!template.includes('__SHELL_VERSION__') || !template.includes('__PRECACHE_URLS__')) {
  fail('sw.js template placeholders missing.')
}
const sw = template
  .replaceAll('__SHELL_VERSION__', version)
  .replaceAll('__PRECACHE_URLS__', JSON.stringify(precache, null, 2))
if (sw.includes('__SHELL_VERSION__') || sw.includes('__PRECACHE_URLS__')) {
  fail('placeholder replacement incomplete.')
}

mkdirSync(distDir, { recursive: true })
writeFileSync(join(distDir, 'sw.js'), sw)
console.log(`[build-pwa] sw.js written (version ${version}, ${precache.length} precache entries).`)
