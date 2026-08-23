/**
 * Takes the screenshots the help page shows.
 *
 * Runs inside the official Playwright image against a running installation —
 * see help-screenshots.sh, which is what you actually call. Every shot is taken
 * once per interface language, because a German reader with English screenshots
 * is worse served than with none.
 *
 * The guard at the top is the important part: this must never run against an
 * installation holding real documents. A screenshot of somebody's invoice in a
 * public repository cannot be taken back.
 */
import { chromium } from 'playwright'
import { mkdir } from 'node:fs/promises'

const BASE = process.env.HELP_BASE_URL ?? 'http://host.docker.internal:3000'
const EMAIL = process.env.ADMIN_EMAIL
const PASSWORD = process.env.ADMIN_PASSWORD
const OUT = process.env.HELP_OUT ?? '/out'
const LOCALES = ['de', 'en']

/** Hosts an installation may talk to and still count as a demo. */
const DEMO_HOSTS = ['demo-nextcloud', 'localhost', '127.0.0.1']

const VIEWPORT = { width: 1280, height: 900 }

if (!EMAIL || !PASSWORD) {
  console.error('ADMIN_EMAIL and ADMIN_PASSWORD have to be set.')
  process.exit(1)
}

/** Signs in over the interface, so the session cookie is the real thing. */
async function signIn(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' })
  await page.fill('input[type="email"]', EMAIL)
  await page.fill('input[type="password"]', PASSWORD)
  await page.click('button[type="submit"]')
  await page.waitForURL(url => !url.pathname.startsWith('/login'), { timeout: 20000 })
}

/**
 * Refuses to continue on an installation that talks to anything but a demo
 * Nextcloud. Better a failed run than a published screenshot of real files.
 */
async function assertDemoInstallation(page) {
  const instances = await page.evaluate(async () => {
    const response = await fetch('/api/admin/instances', { headers: { Accept: 'application/json' } })
    if (!response.ok) return null
    const body = await response.json()
    return body.instances ?? body.data ?? body
  })

  if (!Array.isArray(instances)) {
    throw new Error('Could not read the connected instances — is the account an administrator?')
  }

  if (instances.length === 0) {
    throw new Error('No Nextcloud connected. Seed the demo first: make demo-seed')
  }

  for (const instance of instances) {
    const host = new URL(instance.base_url).hostname
    if (!DEMO_HOSTS.includes(host)) {
      throw new Error(
        `Refusing to take screenshots: "${instance.name}" points at ${host}, which is not a demo instance. `
        + 'Run this against a throwaway installation (make demo), never against one holding real documents.',
      )
    }
  }
}

/** Switches the interface language the way the language picker does. */
async function useLocale(context, locale) {
  await context.addCookies([{
    name: 'nextsearch_locale',
    value: locale,
    url: BASE,
  }])
}

async function shoot(target, dir, name, options = {}) {
  await target.screenshot({ path: `${dir}/${name}.png`, animations: 'disabled', ...options })
  console.log(`  ${name}.png`)
}

/**
 * Opens a document for the shots that follow.
 *
 * Deliberately one that NextSearch renders itself — markdown, a mail, plain
 * text. A PDF would be handed to the browser's own viewer, and that one stays
 * blank in a headless Chromium: the screenshot would show an empty frame where
 * a reader expects the document.
 */
async function openRenderedDocument(page) {
  await page.goto(`${BASE}/?q=lagerhalle`, { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)

  const hits = page.locator('a[href^="/documents/"]')
  await hits.first().waitFor({ timeout: 15000 })

  const count = await hits.count()

  for (let i = 0; i < count; i++) {
    const hit = hits.nth(i)
    const label = (await hit.innerText()).toLowerCase()

    if (/\.(md|markdown|eml|txt)\b/.test(label)) {
      await hit.click()
      await page.waitForURL('**/documents/**', { timeout: 15000 })
      await page.waitForTimeout(2000)

      return true
    }
  }

  return false
}

const browser = await chromium.launch()

try {
  for (const locale of LOCALES) {
    const dir = `${OUT}/${locale}`
    await mkdir(dir, { recursive: true })
    console.log(`\n${locale}:`)

    const context = await browser.newContext({
      viewport: VIEWPORT,
      deviceScaleFactor: 1,
      colorScheme: 'light',
      locale: locale === 'de' ? 'de-DE' : 'en-GB',
      // Times and dates end up in the screenshots; a fixed zone keeps them
      // from moving with whoever regenerates them.
      timezoneId: 'Europe/Berlin',
    })

    await useLocale(context, locale)
    const page = await context.newPage()

    await signIn(page)

    if (locale === LOCALES[0]) {
      await assertDemoInstallation(page)
    }

    // The search, with results and the filter column beside them.
    await page.goto(`${BASE}/?q=rechnung`, { waitUntil: 'networkidle' })
    await page.waitForTimeout(2000)
    await shoot(page, dir, 'search')

    // The filter column on its own — in the help it stands next to the passage
    // about how filters combine.
    const facets = page.locator('[data-shot="facets"]').first()
    if (await facets.count()) {
      await shoot(facets, dir, 'filter')
    }

    // A document with its content, metadata and tags. Clipped: below the
    // metadata block there is nothing but page.
    if (await openRenderedDocument(page)) {
      await shoot(page, dir, 'document', { clip: { x: 0, y: 0, width: VIEWPORT.width, height: 640 } })

      // The tag picker, opened. Only the top of the page is of interest here.
      const picker = page.locator('[data-shot="tags"]').first()
      if (await picker.count()) {
        await picker.locator('button').last().click()
        await page.waitForTimeout(800)
        await shoot(page, dir, 'tags', { clip: { x: 0, y: 0, width: VIEWPORT.width, height: 430 } })
      }
    }

    // The MCP tab of the profile. Below the connection list the page is empty,
    // so the shot stops there.
    await page.goto(`${BASE}/account?tab=mcp`, { waitUntil: 'networkidle' })
    await page.waitForTimeout(1200)
    await shoot(page, dir, 'mcp', { clip: { x: 0, y: 0, width: VIEWPORT.width, height: 760 } })

    await context.close()
  }
} finally {
  await browser.close()
}

console.log('\nDone.')
