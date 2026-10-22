// Rend readme_en.html et readme_fr.html en PDF (noms exigés par Addons).
// Usage : node rendre-pdf.mjs <dossier du paquet playwright>
import { pathToFileURL, fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const [pw] = process.argv.slice(2);
const { chromium } = await import(pathToFileURL(join(pw, "index.mjs")).href);
const here = dirname(fileURLToPath(import.meta.url));

const browser = await chromium.launch({ channel: "msedge" });
const page = await browser.newPage();
for (const lang of ["en", "fr"]) {
  await page.goto(pathToFileURL(join(here, `readme_${lang}.html`)).href);
  await page.waitForLoadState("networkidle");
  await page.pdf({ path: join(here, `readme_${lang}.pdf`), format: "A4", printBackground: true, preferCSSPageSize: true });
  console.log(`readme_${lang}.pdf`);
}
await browser.close();
