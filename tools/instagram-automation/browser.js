const path = require("path");
const fs = require("fs");
const puppeteer = require("puppeteer-extra");
const StealthPlugin = require("puppeteer-extra-plugin-stealth");

// Patches the standard headless tells (navigator.webdriver, missing
// chrome.runtime, permissions API mismatch, plugin/mimetype arrays, WebGL
// vendor string, etc.) — Instagram's login endpoint fingerprints these and
// silently returns a generic "incorrect password" instead of a bot-block
// page, which is why bad logins were indistinguishable from real ones.
puppeteer.use(StealthPlugin());

const USER_AGENT =
  "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36";

const PROFILES_DIR = path.join(__dirname, "profiles");

// A fresh, empty browser profile on every login attempt is itself a bot
// signal — a real user's browser has cookies, cache, and history. Reusing a
// persistent profile per Instagram username makes repeat logins look like
// the same returning device instead of a new one each time.
function profileDir(profileKey) {
  if (!profileKey) return undefined;
  const safe = profileKey.replace(/[^a-z0-9_.-]/gi, "_");
  const dir = path.join(PROFILES_DIR, safe);
  fs.mkdirSync(dir, { recursive: true });
  return dir;
}

async function launchPage(profileKey) {
  const browser = await puppeteer.launch({
    headless: process.env.IG_HEADLESS !== "false",
    userDataDir: profileDir(profileKey),
    args: ["--window-size=1280,900", "--disable-blink-features=AutomationControlled"],
    ignoreDefaultArgs: ["--enable-automation"],
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 900 });
  await page.setUserAgent(USER_AGENT);
  return { browser, page };
}

module.exports = { launchPage, USER_AGENT };
