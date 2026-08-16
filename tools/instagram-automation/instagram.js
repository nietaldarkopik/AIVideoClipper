const path = require("path");
const fs = require("fs");

const DEBUG_DIR = path.join(__dirname, "debug-screenshots");
if (!fs.existsSync(DEBUG_DIR)) fs.mkdirSync(DEBUG_DIR, { recursive: true });

async function debugScreenshot(page, label) {
  const file = path.join(DEBUG_DIR, `${Date.now()}-${label}.png`);
  try {
    await page.screenshot({ path: file, fullPage: true });
  } catch (e) {
    // best-effort; a screenshot failure shouldn't mask the real error
  }
  return file;
}

async function dismissPostLoginDialogs(page) {
  // "Save your login info?" / "Turn on notifications" etc. — best-effort, several
  // rounds since more than one can appear in sequence. Never fatal if none show up.
  for (let i = 0; i < 3; i++) {
    try {
      const locator = page.locator("::-p-text(Not now)");
      await locator.setTimeout(3000).click();
      await new Promise((r) => setTimeout(r, 1000));
    } catch {
      break;
    }
  }
}

/**
 * VERIFIED against Instagram's live login page (2026-08-15): the form fields are
 * input[name="email"] and input[name="pass"] — NOT "username"/"password" like
 * older Instagram versions used. Submitting is done by pressing Enter rather than
 * hunting for the styled "Log in" button, since the visible button label sits
 * inside several layers of unstyled-class React divs with no stable selector.
 */
async function login(page, username, password) {
  await page.goto("https://www.instagram.com/accounts/login/", {
    waitUntil: "networkidle2",
    timeout: 30000,
  });

  await page.waitForSelector('input[name="email"]', { timeout: 15000 });
  await page.type('input[name="email"]', username, { delay: 30 });
  await page.type('input[name="pass"]', password, { delay: 30 });

  await Promise.all([
    page.keyboard.press("Enter"),
    page.waitForNavigation({ waitUntil: "networkidle2", timeout: 30000 }).catch(() => {}),
  ]);
  await new Promise((r) => setTimeout(r, 2000));

  const url = page.url();
  const bodyText = await page.evaluate(() => document.body.innerText).catch(() => "");

  if (/\/challenge\//.test(url) || /suspicious|confirm it'?s you|we detected/i.test(bodyText)) {
    const screenshot = await debugScreenshot(page, "checkpoint");
    return { success: false, error: "Instagram flagged this login as suspicious (checkpoint challenge). This usually requires manually approving it from a phone that's already logged into the account.", screenshot };
  }

  if (/two.?factor|enter the code|security code/i.test(bodyText)) {
    return { success: false, requiresTwoFactor: true };
  }

  if (/incorrect|couldn'?t find|wrong password/i.test(bodyText)) {
    const screenshot = await debugScreenshot(page, "bad-credentials");
    return { success: false, error: "Instagram rejected the username/password.", screenshot };
  }

  await dismissPostLoginDialogs(page);

  const cookies = await page.cookies();
  const sessionCookie = cookies.find((c) => c.name === "sessionid");
  if (!sessionCookie) {
    const screenshot = await debugScreenshot(page, "no-session-cookie");
    return { success: false, error: "Login did not produce a session cookie — Instagram's flow may have changed.", screenshot };
  }

  return { success: true, cookies };
}

/**
 * UNVERIFIED against a real account (no test credentials were available while
 * building this) — written from Instagram's known/documented upload flow shape,
 * but the exact selectors may need adjusting once tried against a live login.
 * Every unexpected state gets a debug screenshot so that's a fast fix, not a
 * from-scratch investigation.
 */
async function submitTwoFactorCode(page, code) {
  await page.waitForSelector('input[name="verificationCode"], input[aria-label*="code" i]', { timeout: 10000 });
  const selector = (await page.$('input[name="verificationCode"]')) ? 'input[name="verificationCode"]' : 'input[aria-label*="code" i]';
  await page.type(selector, code, { delay: 30 });

  await Promise.all([
    page.keyboard.press("Enter"),
    page.waitForNavigation({ waitUntil: "networkidle2", timeout: 30000 }).catch(() => {}),
  ]);
  await new Promise((r) => setTimeout(r, 2000));

  const bodyText = await page.evaluate(() => document.body.innerText).catch(() => "");
  if (/incorrect code|wrong code|please check the code/i.test(bodyText)) {
    return { success: false, error: "Incorrect verification code." };
  }

  await dismissPostLoginDialogs(page);

  const cookies = await page.cookies();
  const sessionCookie = cookies.find((c) => c.name === "sessionid");
  if (!sessionCookie) {
    const screenshot = await debugScreenshot(page, "2fa-no-session-cookie");
    return { success: false, error: "Verification did not produce a session cookie.", screenshot };
  }

  return { success: true, cookies };
}

/**
 * UNVERIFIED against a real account — same caveat as submitTwoFactorCode(). The
 * create-post flow is: click "New post" → pick file → Next (crop) → Next (filters)
 * → write caption → Share. Buttons are matched by visible text via Puppeteer's
 * locator API, which is more resilient to Instagram's ever-changing CSS class
 * names than class-based selectors would be.
 */
async function publish(page, cookies, videoPath, caption) {
  await page.setCookie(...cookies);
  await page.goto("https://www.instagram.com/", { waitUntil: "networkidle2", timeout: 30000 });

  if (/\/accounts\/login/.test(page.url())) {
    return { success: false, error: "Session cookie was rejected — it likely expired. Reconnect the account." };
  }

  try {
    await page.locator('[aria-label="New post"], [aria-label="Create"]').setTimeout(10000).click();
  } catch {
    const screenshot = await debugScreenshot(page, "no-create-button");
    return { success: false, error: 'Could not find the "New post" button — Instagram\'s UI may have changed.', screenshot };
  }

  let fileChooser;
  try {
    [fileChooser] = await Promise.all([
      page.waitForFileChooser({ timeout: 10000 }),
      page.locator("::-p-text(Select from computer)").setTimeout(10000).click(),
    ]);
  } catch {
    const screenshot = await debugScreenshot(page, "no-file-picker");
    return { success: false, error: 'Could not open the file picker ("Select from computer" button not found).', screenshot };
  }

  await fileChooser.accept([videoPath]);
  await new Promise((r) => setTimeout(r, 3000));

  // Crop step, then filter step — both just need "Next".
  for (let i = 0; i < 2; i++) {
    try {
      await page.locator("::-p-text(Next)").setTimeout(15000).click();
      await new Promise((r) => setTimeout(r, 1500));
    } catch {
      const screenshot = await debugScreenshot(page, `next-step-${i}-failed`);
      return { success: false, error: `Upload flow got stuck at step ${i + 1} (expected a "Next" button).`, screenshot };
    }
  }

  if (caption) {
    try {
      const captionBox = await page.waitForSelector('textarea[aria-label*="caption" i], div[aria-label*="caption" i][contenteditable="true"]', { timeout: 10000 });
      await captionBox.click();
      await captionBox.type(caption, { delay: 10 });
    } catch {
      // Non-fatal — post without a caption rather than failing the whole publish.
    }
  }

  try {
    await page.locator("::-p-text(Share)").setTimeout(15000).click();
  } catch {
    const screenshot = await debugScreenshot(page, "no-share-button");
    return { success: false, error: 'Could not find the "Share" button.', screenshot };
  }

  // Sharing can take a while (video processing) before confirmation appears.
  try {
    await page.waitForFunction(
      () => /post has been shared|shared/i.test(document.body.innerText),
      { timeout: 60000 }
    );
  } catch {
    const screenshot = await debugScreenshot(page, "share-confirmation-timeout");
    return { success: false, error: "Timed out waiting for share confirmation — it may have posted anyway; check the account manually.", screenshot };
  }

  const postUrl = await findLatestPostUrl(page).catch(() => null);

  return { success: true, post_url: postUrl };
}

async function findLatestPostUrl(page) {
  // Best-effort: visit the profile grid and grab the first post link. Instagram
  // doesn't hand back a direct link from the composer itself.
  const usernameMatch = await page.evaluate(() => {
    const link = document.querySelector('a[href*="/accounts/edit/"]');
    return link ? null : null; // placeholder — profile href isn't reliably derivable from the feed alone
  });
  await page.goto("https://www.instagram.com/", { waitUntil: "networkidle2", timeout: 15000 });
  const profileLink = await page.$('a[aria-label="Profile"], a[href*="/"][role="link"] svg[aria-label="Profile"]');
  if (!profileLink) return null;

  await Promise.all([
    page.waitForNavigation({ waitUntil: "networkidle2", timeout: 15000 }).catch(() => {}),
    profileLink.click(),
  ]);

  const href = await page.evaluate(() => {
    const firstPost = document.querySelector('main a[href*="/p/"], main a[href*="/reel/"]');
    return firstPost ? firstPost.getAttribute("href") : null;
  });

  return href ? `https://www.instagram.com${href}` : null;
}

module.exports = { login, submitTwoFactorCode, publish, debugScreenshot };
