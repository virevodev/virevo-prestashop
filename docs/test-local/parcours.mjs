// Parcours complet du module virevopay sur le PrestaShop local (http://localhost:8089).
// Usage : node parcours.mjs <playwright> <dossier de sortie> <dossier admin> [sans-webhook]
import { createHmac } from "node:crypto";
import { pathToFileURL } from "node:url";
import { join } from "node:path";

const [pw, out, adminDir, mode] = process.argv.slice(2);
const { chromium } = await import(pathToFileURL(join(pw, "index.mjs")).href);
const base = "http://localhost:8089";

const browser = await chromium.launch({ channel: "msedge" });
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: "fr-FR" });
const page = await ctx.newPage();
const shot = (name, opts = {}) => page.screenshot({ path: join(out, `${name}.png`), fullPage: true, ...opts });
// L'admin PrestaShop exige un jeton dans l'URL : on passe l'écran d'avertissement.
const adminGo = async (url) => {
  await page.goto(url);
  const ok = page.getByText("Oui, je comprends les risques");
  if (await ok.count()) await ok.click();
  await page.waitForLoadState("networkidle");
};

// Panier : deux articles.
await page.goto(`${base}/index.php?id_product=1&controller=product&id_lang=1`);
await page.locator("#quantity_wanted").fill("2");
await page.locator("button.add-to-cart").click();
await page.waitForSelector("#blockcart-modal", { timeout: 15000 });
await page.goto(`${base}/index.php?controller=order`);

// Étape 1 : informations personnelles (invité).
await page.fill("#customer-form input[name=firstname]", "Camille");
await page.fill("#customer-form input[name=lastname]", "Martin");
await page.fill("#customer-form input[name=email]", `camille.${Date.now()}@example.fr`);
for (const cb of await page.locator("#customer-form input[type=checkbox][required]").all()) await cb.check();
await page.click("#customer-form button[name=continue]");

// Étape 2 : adresse.
await page.waitForSelector("input[name=address1]");
await page.fill("input[name=address1]", "8 rue des Potiers");
await page.fill("input[name=postcode]", "69001");
await page.fill("input[name=city]", "Lyon");
await page.click("button[name=confirm-addresses]");

// Étape 3 : livraison.
await page.waitForSelector("button[name=confirmDeliveryOption]");
await page.click("button[name=confirmDeliveryOption]");

// Étape 4 : paiement.
const option = page.locator("input[data-module-name=virevopay]");
await option.waitFor({ timeout: 15000 });
await option.check();
await page.locator("input[name='conditions_to_approve[terms-and-conditions]']").check();
await page.waitForTimeout(800);
await shot("ps-checkout");
await page.locator("#payment-confirmation button").click();
await page.waitForLoadState("networkidle");
console.log("après validation :", page.url());
await shot("ps-retour");

const m = page.url().match(/id_order=(\d+)/);
const orderId = m ? m[1] : null;
console.log("commande", orderId);

if (orderId && mode !== "sans-webhook") {
  const body = JSON.stringify({
    type: "payment.succeeded",
    data: { payment: { id: `pay_demo_${orderId}`, reference: orderId, amount_cents: 0, currency: "EUR" } },
  });
  const t = Math.floor(Date.now() / 1000);
  const sig = createHmac("sha256", "whsec_test123").update(`${t}.${body}`).digest("hex");
  const r = await fetch(`${base}/index.php?fc=module&module=virevopay&controller=webhook`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "Virevo-Signature": `t=${t},v1=${sig}` },
    body,
  });
  console.log("webhook", r.status, (await r.text()).slice(0, 200));
}

// Administration.
await page.goto(`${base}/${adminDir}/`);
await page.fill("#email", "demo@virevo.test");
await page.fill("#passwd", "demo-local-2026");
await page.click("#submit_login");
await page.waitForLoadState("networkidle");
if (orderId) {
  await adminGo(`${base}/${adminDir}/index.php/sell/orders/${orderId}/view`);
  await shot("ps-admin-commande", { fullPage: false });
}
await adminGo(`${base}/${adminDir}/index.php/improve/modules/manage/action/configure/virevopay`);
await shot("ps-admin-reglages");
await browser.close();
