import { chromium } from "playwright-core";

const chromiumPath =
  "C:/Users/Exist Code/AppData/Local/ms-playwright/chromium-1234/chrome-win64/chrome.exe";

const browser = await chromium.launch({ executablePath: chromiumPath, headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

page.on("requestfailed", (req) => {
  console.log("REQUEST FAILED:", req.url(), req.failure()?.errorText);
});
page.on("response", (res) => {
  if (res.url().includes("thumbnail")) {
    console.log("RESPONSE:", res.status(), res.url());
  }
});

await page.goto("http://localhost:3000/login", { waitUntil: "networkidle" });
await page.click('button:has-text("Sign in")');
await page.waitForURL("**/dashboard", { timeout: 15000 });
await page.waitForTimeout(1500);

const imgs = await page.$$eval("img", (els) =>
  els.map((el) => ({
    src: el.src,
    naturalWidth: el.naturalWidth,
    complete: el.complete,
    clientWidth: el.clientWidth,
    clientHeight: el.clientHeight,
  }))
);
console.log(JSON.stringify(imgs, null, 2));

await page.screenshot({ path: "f:/clipper-tools/tools/testdata/shots/debug-dashboard.png" });
await page.screenshot({
  path: "f:/clipper-tools/tools/testdata/shots/debug-card.png",
  clip: { x: 280, y: 115, width: 380, height: 240 },
});

await browser.close();
