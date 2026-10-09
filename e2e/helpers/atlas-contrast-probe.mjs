// @ts-check
/**
 * ATLAS ds_chrome live contrast probe (snackcheck).
 *
 * Ported from maintenancecheck/tests/e2e/helpers/atlas-contrast-probe.mjs.
 * Logs in, walks representative SnackCheck pages, and measures the COMPUTED
 * WCAG 2.1 contrast of semantic chrome: status badges, primary/danger CTAs,
 * invalid-field borders, control borders, and the error toast — across
 * light / dark / light-highcontrast user themes.
 *
 *   text ink   >= 4.5:1  (WCAG 1.4.3 AA)
 *   borders    >= 3.0:1  (WCAG 1.4.11)
 *
 * Usage (from the app dir):
 *   node e2e/helpers/atlas-contrast-probe.mjs [--out <path.json>]
 *
 * Requires E2E_USER + E2E_PASS (e2e/.env) and http://localhost:8081.
 */
import { writeFileSync, mkdirSync, existsSync, readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createRequire } from 'node:module'
import { chromium } from '@playwright/test'

const HERE = dirname(fileURLToPath(import.meta.url))
const APP_ROOT = resolve(HERE, '../..')
const require = createRequire(import.meta.url)
const { setUserTheme, resetUserTheme } = require('./theming.js')

const ENV_PATH = resolve(APP_ROOT, 'e2e/.env')
if (existsSync(ENV_PATH)) {
	for (const line of readFileSync(ENV_PATH, 'utf8').split('\n')) {
		const t = line.trim()
		if (!t || t.startsWith('#')) continue
		const eq = t.indexOf('=')
		if (eq <= 0) continue
		const k = t.slice(0, eq).trim()
		let v = t.slice(eq + 1).trim()
		if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) v = v.slice(1, -1)
		if (process.env[k] === undefined) process.env[k] = v
	}
}

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '')
const USER = process.env.E2E_USER
const PASS = process.env.E2E_PASS || process.env.E2E_PASSWORD

// ── WCAG contrast helpers (injected into the page for computed colors) ──
const EVAL_FN = String.raw`
function hexToRgb(c) {
  c = c.trim()
  // Chrome serialises color-mix() as color(srgb r g b / a) — floats 0..1.
  if (c.startsWith('color(')) {
    const m = c.match(/[\d.]+/g)
    if (m && m.length >= 3) {
      const s = m.map(parseFloat)
      const scale = s.every((v) => v <= 1) ? 255 : 1
      return [s[0] * scale, s[1] * scale, s[2] * scale]
    }
    return null
  }
  if (c.startsWith('rgb')) {
    const m = c.match(/[\d.]+/g)
    if (m && m.length >= 3) return [parseFloat(m[0]), parseFloat(m[1]), parseFloat(m[2])]
    return null
  }
  if (c.startsWith('#')) {
    let h = c.slice(1)
    if (h.length === 3) h = h.split('').map(x => x + x).join('')
    if (h.length === 4) h = h.split('').map(x => x + x).join('')
    if (h.length === 6 || h.length === 8) {
      return [parseInt(h.slice(0,2),16), parseInt(h.slice(2,4),16), parseInt(h.slice(4,6),16)]
    }
  }
  return null
}
function lum(rgb) {
  const f = v => {
    v /= 255
    return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)
  }
  return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2])
}
function effBg(el) {
  // Walk ancestors for the first non-transparent background.
  let n = el
  while (n && n !== document.documentElement) {
    const bg = getComputedStyle(n).backgroundColor
    const m = bg && bg.match(/[\d.]+/g)
    if (m && m.length >= 4 && parseFloat(m[3]) > 0) return bg
    if (m && m.length === 3 && !bg.includes('transparent')) return bg
    n = n.parentElement
  }
  return getComputedStyle(document.body).backgroundColor
}
function alphaOf(c) {
  const m = c && c.match(/[\d.]+/g)
  if (m && m.length >= 4) return parseFloat(m[3])
  // color(srgb r g b / a) — fourth channel is the alpha.
  if (c && c.startsWith('color(')) {
    const parts = c.match(/[\d.]+/g)
    if (parts && parts.length >= 4) return parseFloat(parts[3])
  }
  return 1
}
function blend(fgRgb, bgRgb, a) {
  return [
    a * fgRgb[0] + (1 - a) * bgRgb[0],
    a * fgRgb[1] + (1 - a) * bgRgb[1],
    a * fgRgb[2] + (1 - a) * bgRgb[2],
  ]
}
function ratio(fg, bg) {
  const a = hexToRgb(fg), b = hexToRgb(bg)
  if (!a || !b) return null
  const l1 = lum(a), l2 = lum(b)
  const hi = Math.max(l1, l2), lo = Math.min(l1, l2)
  return (hi + 0.05) / (lo + 0.05)
}
function borderRatio(border, bg) {
  const f = hexToRgb(border), b = hexToRgb(bg)
  if (!f || !b) return null
  const alpha = alphaOf(border)
  const eff = alpha >= 1 ? f : blend(f, b, alpha)
  const l1 = lum(eff), l2 = lum(b)
  const hi = Math.max(l1, l2), lo = Math.min(l1, l2)
  return (hi + 0.05) / (lo + 0.05)
}
function ancestorBg(el) {
  // Effective background BEHIND the element (skip its own fill) — for filled
  // CTAs the control boundary is fill-vs-page, not transparent-border-vs-fill.
  let n = el.parentElement
  while (n && n !== document.documentElement) {
    const bg = getComputedStyle(n).backgroundColor
    const m = bg && bg.match(/[\d.]+/g)
    if (m && m.length >= 4 && parseFloat(m[3]) > 0) return bg
    if (m && m.length === 3 && !bg.includes('transparent')) return bg
    n = n.parentElement
  }
  return getComputedStyle(document.body).backgroundColor
}
window.__snkProbe = { effBg, ancestorBg, ratio, alphaOf, borderRatio }
`

/** Elements to measure per page. */
const PROBES = [
	{
		page: '/index.php/apps/snackcheck/log?siteId=1',
		label: 'log',
		ready: '#snk-main-content',
		rows: [
			{ sel: '.snk-filter', kind: 'filter-chip', what: 'text+border' },
			{ sel: 'button.snk-tile .snk-tile__name, button.snk-tile .snk-tile__price', kind: 'tile-ink', what: 'text' },
			{ sel: 'button.snk-tile', kind: 'tile-border', what: 'border' },
			{ sel: '#snk-site-select, .snk-mode-panel .snk-input, .snk-mode-panel .snk-select', kind: 'control-border', what: 'border' },
			{ sel: '.snk-mode-chip span, .snk-qty-chip', kind: 'mode-chip', what: 'text+border' },
		],
	},
	{
		page: '/index.php/apps/snackcheck/catalog?siteId=1',
		label: 'catalog',
		ready: '#snk-main-content',
		rows: [
			{ sel: '.snk-badge', kind: 'badge', what: 'text' },
			{ sel: '.snk-input, .snk-select', kind: 'control-border', what: 'border' },
			{ sel: '.snk-btn--primary, .snk-btn--secondary', kind: 'cta', what: 'text+border' },
		],
	},
	{
		page: '/index.php/apps/snackcheck/users?siteId=1',
		label: 'users',
		ready: '#snk-main-content',
		rows: [
			{ sel: '.snk-badge', kind: 'badge', what: 'text' },
			{ sel: '.snk-btn, button.snk-tile', kind: 'cta', what: 'text+border' },
		],
	},
	{
		page: '/index.php/apps/snackcheck/settings',
		label: 'settings',
		ready: '#snk-main-content',
		rows: [
			{ sel: '.snk-input, .snk-select, .snk-field input, .snk-field select', kind: 'control-border', what: 'border' },
			{ sel: '.snk-btn--primary', kind: 'primary-cta', what: 'text+border' },
		],
	},
	{
		page: '/index.php/apps/snackcheck/hospitality',
		label: 'hospitality',
		ready: '#snk-main-content',
		rows: [
			{ sel: '.snk-badge', kind: 'badge', what: 'text' },
			{ sel: 'table td, table th', kind: 'table-ink', what: 'text' },
			{ sel: '.snk-btn--primary, .snk-btn--danger', kind: 'cta-fill', what: 'fill' },
			{ sel: '.snk-btn--secondary, .snk-btn--ghost, .snk-select', kind: 'cta/control', what: 'text+border' },
		],
	},
]

/** Programmatic login (same flow as e2e/helpers/auth-guard.js). */
async function login(page) {
	await page.goto(`${BASE}/index.php/login`, { waitUntil: 'domcontentloaded' })
	const userField = page.locator('input#user, input[name="user"]').first()
	await userField.waitFor({ state: 'visible', timeout: 30_000 })
	await userField.fill(USER)
	await page.locator('input#password, input[name="password"]').first().fill(PASS)
	await page.getByRole('button', { name: /^log in$|^anmelden$/i }).click()
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
}

async function measure(page, probes) {
	const results = []
	for (const p of probes) {
		await page.goto(`${BASE}${p.page}`, { waitUntil: 'domcontentloaded' })
		await page.waitForSelector(p.ready || '#snk-main-content', { timeout: 30_000 })
		await page.waitForTimeout(400)
		for (const row of p.rows) {
			const found = await page.evaluate(
				async ({ sel, what }) => {
					const els = Array.from(document.querySelectorAll(sel)).filter(
						(n) => n.offsetParent !== null,
					)
					const out = []
					for (const el of els.slice(0, 6)) {
						const cs = getComputedStyle(el)
						const bg = window.__snkProbe.effBg(el)
						const item = {
							tag: el.tagName.toLowerCase(),
							cls: (el.getAttribute('class') || '').slice(0, 80),
							fg: cs.color,
							bg,
							borderColor: cs.borderColor,
							borderWidth: cs.borderWidth,
						}
						if (what === 'fill') {
							// Filled CTA: text ink on fill + fill vs surrounding page bg.
							item.textRatio = window.__snkProbe.ratio(cs.color, bg)
							const surround = window.__snkProbe.ancestorBg(el)
							item.fill = cs.backgroundColor
							item.surroundBg = surround
							item.fillRatio = window.__snkProbe.ratio(cs.backgroundColor, surround)
						} else {
							if (what !== 'border') {
								item.textRatio = window.__snkProbe.ratio(cs.color, bg)
							}
							if (what !== 'text' && parseFloat(cs.borderWidth) > 0) {
								const bAlpha = window.__snkProbe.alphaOf(cs.borderColor)
								item.borderAlpha = bAlpha
								// alpha=0 border = invisible boundary — record so the
								// verdict can flag outlined controls that lost their frame.
								item.borderTransparent = bAlpha === 0
								if (bAlpha > 0) {
									item.borderRatio = window.__snkProbe.borderRatio(cs.borderColor, bg)
								}
							}
						}
						out.push(item)
					}
					return out
				},
				{ sel: row.sel, what: row.what },
			)
			results.push({ page: p.label, kind: row.kind, selector: row.sel, what: row.what, found: found.length, samples: found })
		}
	}
	return results
}

/**
 * Live field-error + error-toast state: open the catalog edit dialog, submit an
 * unparseable price → markFieldInvalid pins aria-invalid + assertive toast.
 * Measures the invalid control border + toast ink (WCAG 3.3.1/1.4.3).
 */
async function measureFieldError(page) {
	await page.goto(`${BASE}/index.php/apps/snackcheck/catalog?siteId=1`, { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('#snk-main-content', { timeout: 30_000 })
	const editBtn = page.locator('[data-snk-action="edit-item"]').first()
	if ((await editBtn.count()) === 0) return { skipped: 'no edit-item action rendered' }
	await editBtn.click()
	const dlg = page.locator('#snk-edit-item-dialog')
	await dlg.waitFor({ state: 'visible', timeout: 15_000 })
	const price = dlg.locator('input[name="priceEuro"]')
	await price.fill('abc,,,')
	await dlg.locator('button[type="submit"][value="confirm"], .snk-btn--primary').last().click()
	await page.waitForFunction(
		() => document.querySelector('#snk-edit-item-dialog input[name="priceEuro"]')?.getAttribute('aria-invalid') === 'true'
			|| document.getElementById('snk-toast')?.classList.contains('snk-toast--error'),
		null,
		{ timeout: 15_000 },
	)
	const out = await page.evaluate(() => {
		const input = document.querySelector('#snk-edit-item-dialog input[name="priceEuro"]')
		const toast = document.getElementById('snk-toast')
		const res = {}
		if (input) {
			const cs = getComputedStyle(input)
			res.invalidField = {
				ariaInvalid: input.getAttribute('aria-invalid'),
				borderColor: cs.borderColor,
				borderWidth: cs.borderWidth,
				bg: window.__snkProbe.effBg(input),
				borderRatio: window.__snkProbe.borderRatio(cs.borderColor, window.__snkProbe.effBg(input)),
			}
		}
		if (toast && !toast.hidden) {
			const cs = getComputedStyle(toast)
			const textEl = toast.querySelector('.snk-toast__text') || toast
			const tcs = getComputedStyle(textEl)
			res.errorToast = {
				fg: tcs.color,
				bg: window.__snkProbe.effBg(textEl),
				textRatio: window.__snkProbe.ratio(tcs.color, window.__snkProbe.effBg(textEl)),
				borderColor: cs.borderColor,
				borderRatio: window.__snkProbe.borderRatio(cs.borderColor, window.__snkProbe.effBg(toast)),
			}
		}
		return res
	})
	await page.keyboard.press('Escape')
	return out
}

async function main() {
	if (!USER || !PASS) throw new Error('E2E_USER + E2E_PASS required (e2e/.env)')
	const browser = await chromium.launch()
	const context = await browser.newContext({ viewport: { width: 1440, height: 900 } })
	const page = await context.newPage()
	const report = { app: 'snackcheck', probe: 'live-computed-contrast', base: BASE, generated_at: new Date().toISOString(), themes: {} }
	let failures = 0

	try {
		await login(page)
		await context.addInitScript(EVAL_FN)

		for (const theme of ['light', 'dark', 'light-highcontrast']) {
			await page.goto(`${BASE}/index.php/apps/snackcheck/log?siteId=1`, { waitUntil: 'domcontentloaded' })
			await setUserTheme(page, theme)
			await page.waitForSelector('#snk-main-content', { timeout: 30_000 })
			const themeRes = { pages: await measure(page, PROBES) }
			if (theme === 'light') {
				themeRes.field_error_state = await measureFieldError(page)
			}
			report.themes[theme] = themeRes
		}
		await resetUserTheme(page)
	} finally {
		await context.close()
		await browser.close()
	}

	// Verdict
	const TEXT_MIN = 4.5
	const BORDER_MIN = 3.0
	const findings = []
	for (const [theme, t] of Object.entries(report.themes)) {
		for (const row of t.pages) {
			for (const s of row.samples || []) {
				if (s.textRatio !== undefined && s.textRatio !== null && s.textRatio < TEXT_MIN) {
					findings.push({ theme, page: row.page, kind: row.kind, cls: s.cls, ratio: s.textRatio, min: TEXT_MIN })
				}
				if (s.borderRatio !== undefined && s.borderRatio !== null && s.borderRatio < BORDER_MIN) {
					findings.push({ theme, page: row.page, kind: row.kind + '-border', cls: s.cls, ratio: s.borderRatio, min: BORDER_MIN })
				}
				if (s.borderTransparent === true) {
					findings.push({ theme, page: row.page, kind: row.kind + '-border', cls: s.cls, ratio: 0, min: 'opaque border expected' })
				}
				if (s.fillRatio !== undefined && s.fillRatio !== null && s.fillRatio < BORDER_MIN) {
					findings.push({ theme, page: row.page, kind: row.kind + '-fill', cls: s.cls, ratio: s.fillRatio, min: BORDER_MIN })
				}
			}
		}
		const fe = t.field_error_state
		if (fe) {
			if (fe.errorToast && fe.errorToast.textRatio !== null && fe.errorToast.textRatio < TEXT_MIN) {
				findings.push({ theme, page: 'toast', kind: 'error-toast-text', ratio: fe.errorToast.textRatio, min: TEXT_MIN })
			}
			if (fe.invalidField && fe.invalidField.borderRatio !== null && fe.invalidField.borderRatio < BORDER_MIN) {
				findings.push({ theme, page: 'catalog-edit-dialog', kind: 'invalid-border', ratio: fe.invalidField.borderRatio, min: BORDER_MIN })
			}
		}
	}
	report.findings = findings
	report.verdict = findings.length === 0 ? 'PASS' : 'FAIL'
	failures = findings.length

	const outIdx = process.argv.indexOf('--out')
	const outPath = outIdx > 0 ? process.argv[outIdx + 1] : null
	if (outPath) {
		mkdirSync(dirname(outPath), { recursive: true })
		writeFileSync(outPath, JSON.stringify(report, null, 2))
		console.log(`wrote ${outPath}`)
	} else {
		console.log(JSON.stringify(report, null, 2).slice(0, 4000))
	}
	console.log(`contrast probe: ${report.verdict} (${findings.length} findings)`)
	process.exit(failures > 0 ? 1 : 0)
}

main().catch((e) => {
	console.error(e)
	process.exit(2)
})
