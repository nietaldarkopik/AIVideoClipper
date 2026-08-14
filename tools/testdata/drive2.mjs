import { chromium } from "playwright-core";

const chromiumPath =
  "C:/Users/Exist Code/AppData/Local/ms-playwright/chromium-1234/chrome-win64/chrome.exe";
const shotsDir = "f:/clipper-tools/tools/testdata/shots";

const browser = await chromium.launch({ executablePath: chromiumPath, headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
const errors = [];
page.on("pageerror", (err) => errors.push(err.message));
page.on("console", (msg) => msg.type() === "error" && errors.push(msg.text()));

async function shot(name) {
  await page.screenshot({ path: `${shotsDir}/${name}.png` });
  console.log("shot:", name);
}

// --- Connect a social account and publish a clip ---
await page.goto("http://localhost:3000/login", { waitUntil: "networkidle" });
await page.click('button:has-text("Sign in")');
await page.waitForURL("**/dashboard");

await page.goto("http://localhost:3000/social-accounts", { waitUntil: "networkidle" });
await page.click('button:has-text("Connect Account")');
await page.waitForSelector("text=Connect Account", { state: "visible" });
await page.selectOption("#platform", "tiktok");
await page.fill("#account_name", "My Brand");
await page.fill("#username", "mybrand");
await page.click('.relative >> button:has-text("Connect")');
await page.waitForTimeout(1000);
await shot("10-social-connected");

await page.goto("http://localhost:3000/clips/1", { waitUntil: "networkidle" });
await page.waitForTimeout(800);
await shot("11-clip-editor-with-account");

// select the tiktok checkbox in publish panel and publish
const checkbox = page.locator('label:has-text("TikTok") input[type=checkbox]').first();
await checkbox.check();
await page.click('button:has-text("Publish to")');
await page.waitForTimeout(1500);
await shot("12-published");

// --- Admin login ---
await page.evaluate(() => localStorage.removeItem("clipper-auth"));
await page.goto("http://localhost:3000/login", { waitUntil: "networkidle" });
await page.fill('#email', 'admin@clipper.test');
await page.click('button:has-text("Sign in")');
await page.waitForURL("**/dashboard");
await page.goto("http://localhost:3000/admin", { waitUntil: "networkidle" });
await page.waitForTimeout(800);
await shot("13-admin-overview");

await page.goto("http://localhost:3000/admin/processing", { waitUntil: "networkidle" });
await page.waitForTimeout(800);
await shot("14-admin-processing");

console.log("\n--- Errors ---");
console.log(errors.length ? errors.join("\n") : "(none)");

await browser.close();
