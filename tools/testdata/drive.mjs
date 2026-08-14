import { chromium } from "playwright-core";
import path from "node:path";
import fs from "node:fs";

const shotsDir = "f:/clipper-tools/tools/testdata/shots";
fs.mkdirSync(shotsDir, { recursive: true });

const chromiumPath =
  "C:/Users/Exist Code/AppData/Local/ms-playwright/chromium-1234/chrome-win64/chrome.exe";

const browser = await chromium.launch({ executablePath: chromiumPath, headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

const errors = [];
page.on("console", (msg) => {
  if (msg.type() === "error") errors.push(`[console] ${msg.text()}`);
});
page.on("pageerror", (err) => errors.push(`[pageerror] ${err.message}`));

async function shot(name) {
  await page.screenshot({ path: path.join(shotsDir, `${name}.png`), fullPage: false });
  console.log(`shot: ${name}`);
}

console.log("Navigating to login...");
await page.goto("http://localhost:3000/login", { waitUntil: "networkidle" });
await page.waitForSelector("text=Welcome back");
await shot("01-login");

await page.click('button:has-text("Sign in")');
await page.waitForURL("**/dashboard", { timeout: 15000 });
await page.waitForTimeout(1200);
await shot("02-dashboard");

const routes = [
  ["projects", "03-projects"],
  ["projects/1", "04-project-detail"],
  ["clips/1", "05-clip-editor"],
  ["templates", "06-templates"],
  ["templates/1", "07-template-detail"],
  ["social-accounts", "08-social-accounts"],
  ["settings", "09-settings"],
];

for (const [route, name] of routes) {
  await page.goto(`http://localhost:3000/${route}`, { waitUntil: "networkidle" });
  await page.waitForTimeout(1000);
  await shot(name);
}

console.log("\n--- Console/page errors ---");
console.log(errors.length ? errors.join("\n") : "(none)");

await browser.close();
