import { chromium } from 'playwright-core'
const browser = await chromium.launch()
for (const p of ['/products', '/categories', '/products/does-not-exist', '/', '/articles', '/articles/none', '/page/none']) {
  const page = await (await browser.newContext()).newPage(); const m = []
  page.on('console', (x) => m.push(`${x.type()}: ${x.text()}`))
  await page.goto('http://localhost:3000' + p, { waitUntil: 'networkidle' }); await page.waitForTimeout(500)
  const h = m.filter((x) => /hydrat|mismatch/i.test(x))
  console.log(p, h.length ? 'MISMATCH\n' + h.join('\n').slice(0, 900) : 'ok')
}
await browser.close()
