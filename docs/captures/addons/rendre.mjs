// Captures au format Addons (entre 1000×1000 et 2000×2000 px) : 1600×1200.
// Usage : node rendre.mjs <dossier du paquet playwright>
import { pathToFileURL, fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const [pw] = process.argv.slice(2);
const { chromium } = await import(pathToFileURL(join(pw, "index.mjs")).href);
const here = dirname(fileURLToPath(import.meta.url));
const noms = { 1: "1-paiement", 2: "2-commande-payee", 3: "3-reglages", 4: "4-service-indisponible" };

const browser = await chromium.launch({ channel: "msedge" });
const page = await browser.newPage({ viewport: { width: 1600, height: 1200 } });
for (const [n, nom] of Object.entries(noms)) {
  await page.goto(`${pathToFileURL(join(here, "cadre.html")).href}?n=${n}`);
  await page.waitForLoadState("networkidle");
  await page.screenshot({ path: join(here, `${nom}.png`) });
  console.log(`${nom}.png`);
}
await browser.close();
