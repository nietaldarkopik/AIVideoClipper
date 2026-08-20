# Instagram Automation

A self-hosted Puppeteer service that logs into Instagram through the real web UI
and (eventually) publishes clips the same way, since Instagram's official Content
Publishing API requires a Business/Creator account linked to a Facebook Page plus
Meta App Review — a lot of friction for personal use. Laravel calls this service
through `InstagramProvider` (`backend/app/Services/Social/Providers/InstagramProvider.php`).

Unlike YouTube/Facebook (real OAuth) or TikTok/Twitter/LinkedIn (mocked), Instagram
has no OAuth step here at all — `connect()` sends a real username/password straight
to this service's `/login` endpoint. The password is stored encrypted in the
`social_accounts` table (needed to re-login when the scraped session expires,
since there's no OAuth refresh token for a session obtained this way).

**Two-factor authentication is not fully wired up yet.** The service reports
`requires_2fa` + a `session_id` from `/login`, and exposes `/login/verify` to
submit the code, but `InstagramProvider::connect()` on the Laravel side doesn't
call it yet — it just fails with a clear error. Disable 2FA temporarily on the
account you're connecting, or wire up the code-entry step (`/login/verify`) if
you need it.

## Requirements

- Node.js 18+ (tested with the bundled `puppeteer` which downloads its own Chromium)
- `node_modules` already committed/installed under this folder — if missing, run:

```bash
cd tools/instagram-automation
npm install
```

## Running

```bash
cd tools/instagram-automation
npm start
```

Starts on `http://127.0.0.1:8300`. Puppeteer launches Chromium headless by default;
set `IG_HEADLESS=false` to watch it drive a real browser window (useful for
debugging a failed login/publish).

Configure via environment variables before starting:

| Var | Default | Notes |
|---|---|---|
| `IG_AUTOMATION_HOST` | `127.0.0.1` | |
| `IG_AUTOMATION_PORT` | `8300` | Must match `IG_AUTOMATION_URL` in `backend/.env`. |
| `IG_HEADLESS` | `true` | Set to `false` to launch a visible Chromium window instead of headless. |

## Enabling it in Laravel

Already wired up by default — `backend/.env` has:

```
IG_AUTOMATION_URL=http://127.0.0.1:8300
IG_AUTOMATION_TIMEOUT=180
```

Unlike the AI providers (transcription/analysis/reframing), there's no `mock`
fallback for Instagram — connecting or publishing an Instagram account always
calls this service, so it needs to be running whenever that feature is used.
If it's down, `connect()`/`publish()` fail with a `cURL error 7: Failed to
connect to 127.0.0.1 port 8300` style error.

## API

- `GET /health` — `{"status": "ok", "service": "Clipper Instagram Automation", "pending_logins": number}`
- `POST /login` — body `{"username", "password"}`. Returns either:
  - `{"success": true, "cookies": [...]}` on success, or
  - `{"success": false, "requires_2fa": true, "session_id": "<uuid>"}` if the account has 2FA (see above — not consumed by Laravel yet), or
  - `{"success": false, "error": "..."}` on any other failure.
- `POST /login/verify` — body `{"session_id", "code"}`. Completes a pending 2FA login started by `/login`. Not called by Laravel yet.
- `POST /publish` — body `{"cookies", "video_path", "caption"}`. `video_path` must be an absolute local path already on disk (Laravel always renders the clip locally before calling this). Returns `{"success": true, "post_url": "..."}` or `{"success": false, "error": "..."}`.

Pending 2FA browser sessions (from `/login`) are held in memory for 5 minutes and
auto-closed if `/login/verify` is never called, so an abandoned login doesn't leak
a Chromium instance.
