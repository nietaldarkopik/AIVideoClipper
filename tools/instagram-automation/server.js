const express = require("express");
const { randomUUID } = require("crypto");
const { launchPage } = require("./browser");
const { login, submitTwoFactorCode, publish } = require("./instagram");

const app = express();
app.use(express.json({ limit: "10mb" }));

const PORT = process.env.IG_AUTOMATION_PORT || 8300;
const HOST = process.env.IG_AUTOMATION_HOST || "127.0.0.1";

// Browser sessions waiting on a 2FA code, keyed by session_id. Cleared after use
// or after PENDING_TTL_MS so an abandoned login doesn't leak a browser forever.
const pendingLogins = new Map();
const PENDING_TTL_MS = 5 * 60 * 1000;

function schedulePendingCleanup(sessionId) {
  setTimeout(() => {
    const entry = pendingLogins.get(sessionId);
    if (entry) {
      entry.browser.close().catch(() => {});
      pendingLogins.delete(sessionId);
    }
  }, PENDING_TTL_MS);
}

app.get("/health", (req, res) => {
  res.json({ status: "ok", service: "Clipper Instagram Automation", pending_logins: pendingLogins.size });
});

app.post("/login", async (req, res) => {
  const { username, password } = req.body || {};
  if (!username || !password) {
    return res.status(400).json({ success: false, error: "username and password are required." });
  }

  const { browser, page } = await launchPage();
  try {
    const result = await login(page, username, password);

    if (result.requiresTwoFactor) {
      const sessionId = randomUUID();
      pendingLogins.set(sessionId, { browser, page });
      schedulePendingCleanup(sessionId);
      return res.json({ success: false, requires_2fa: true, session_id: sessionId });
    }

    await browser.close();
    return res.json(result);
  } catch (e) {
    await browser.close().catch(() => {});
    return res.status(500).json({ success: false, error: e.message });
  }
});

app.post("/login/verify", async (req, res) => {
  const { session_id, code } = req.body || {};
  const entry = session_id ? pendingLogins.get(session_id) : null;
  if (!entry) {
    return res.status(400).json({ success: false, error: "Unknown or expired session_id. Start the login again." });
  }
  pendingLogins.delete(session_id);

  const { browser, page } = entry;
  try {
    const result = await submitTwoFactorCode(page, code);
    await browser.close();
    return res.json(result);
  } catch (e) {
    await browser.close().catch(() => {});
    return res.status(500).json({ success: false, error: e.message });
  }
});

app.post("/publish", async (req, res) => {
  const { cookies, video_path, caption } = req.body || {};
  if (!cookies || !video_path) {
    return res.status(400).json({ success: false, error: "cookies and video_path are required." });
  }

  const { browser, page } = await launchPage();
  try {
    const result = await publish(page, cookies, video_path, caption || "");
    return res.json(result);
  } catch (e) {
    return res.status(500).json({ success: false, error: e.message });
  } finally {
    await browser.close().catch(() => {});
  }
});

app.listen(PORT, HOST, () => {
  console.log(`Clipper Instagram Automation listening on http://${HOST}:${PORT}`);
});
