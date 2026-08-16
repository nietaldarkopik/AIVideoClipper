const puppeteer = require("puppeteer");

const USER_AGENT =
  "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36";

async function launchPage() {
  const browser = await puppeteer.launch({
    headless: process.env.IG_HEADLESS !== "false",
    args: ["--window-size=1280,900", "--disable-blink-features=AutomationControlled"],
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 900 });
  await page.setUserAgent(USER_AGENT);
  return { browser, page };
}

module.exports = { launchPage, USER_AGENT };
