# ClipWize (AI Video Clipper)

A working SaaS MVP: upload or import a long video, run AI analysis to find the best
moments, generate social-ready vertical clips with auto captions and smart reframing,
apply reusable templates, and publish to connected social accounts.

This is the **Core Clipping Workflow MVP** scope (see "What's real vs. mocked" below) —
not the full 45-section spec. It's a genuine full-stack app with a real database, real
FFmpeg rendering, and a real queue pipeline; the AI and social-platform integrations are
swappable mock providers so the whole thing runs with zero API keys.

## Stack

- **Frontend**: Next.js 16 (App Router, Turbopack) + TypeScript + Tailwind CSS v4, SWR, Zustand
- **Backend**: Laravel 12 + PHP 8.4, REST API with Sanctum token auth
- **Database**: PostgreSQL 17
- **Queue/Cache**: Redis (portable build, no service install — see `tools/redis`)
- **Video**: FFmpeg (crop/reframe, subtitle burn-in, thumbnails) + yt-dlp (URL import)

## Running it with Docker

The whole stack (PostgreSQL, Redis, Laravel API, three queue workers, scheduler,
Next.js) runs from one `docker-compose.yml`. FFmpeg and yt-dlp are baked into the
backend image, so nothing needs installing on the host.

```bash
cp .env.example .env
# 1. set DB_PASSWORD in .env
# 2. generate an app key and paste it into APP_KEY:
docker compose run --rm --no-deps -e APP_KEY=x api php artisan key:generate --show
docker compose up -d --build
docker compose exec api php artisan db:seed --force   # first run only: templates, platforms, admin user
```

Open http://localhost:3000 (API on :8000). The seeder creates `admin@clipper.test` /
`password` — **change that password before exposing the stack anywhere.**

- Migrations run automatically on the `api` container's start; workers wait for it to be healthy.
- Optional self-hosted services are opt-in profiles:
  `docker compose --profile whisper --profile face-tracker --profile instagram up -d`
  (then set `AI_TRANSCRIPTION_PROVIDER=whisper_engine` / `AI_REFRAMING_PROVIDER=face_tracker` in `.env`).
- Uploaded/rendered media lives in the `app_storage` volume; Postgres in `pgdata`.
  Back up both before `docker compose down -v` (which deletes them).
- `NEXT_PUBLIC_API_URL` is baked into the frontend at build time — it must be the URL the
  *browser* uses for the API. Change it and run `docker compose up -d --build frontend`.
- After changing PHP code, rebuild: `docker compose up -d --build`. Workers cache config at start.
- Other provider/OAuth keys: copy the lines you need from `backend/.env.example` into `.env`.
- Port already taken (e.g. Portainer uses :8000)? Set `API_PORT=8100` and
  `NEXT_PUBLIC_API_URL=http://localhost:8100/api` (and `APP_URL=http://localhost:8100`) in `.env`,
  then `docker compose up -d --build`. `FRONTEND_PORT` works the same for :3000.

### Media access (signed URLs)

`/api/media/{path}` serves videos, clips, thumbnails and uploads to `<video>`/`<img>`/`<audio>`
tags, which can't send a bearer token. Every URL therefore carries an expiring HMAC signature
(`?expires=…&signature=…`) and the route answers **403** without a valid one.

- The API returns already-signed URLs (`url`, `thumbnail_url`, `srt_url`, …). Don't build
  `/api/media/...` URLs by hand; to sign a path the client holds (a layer's `image_path`, a
  waveform), call `POST /api/media/sign` with `{"paths": [...]}` — it only signs paths the caller
  owns (`null` otherwise). In the frontend use `useMediaUrl` / `SignedImg` / `SignedAudio`
  from `src/lib/media.tsx`.
- Signatures are keyed on `APP_KEY`, so rotating it invalidates outstanding links (a page reload
  fetches new ones). Lifetime: `MEDIA_URL_TTL` seconds (default `86400`), set in `.env`.
- Adding a new folder on the media disk? Add its prefix to `App\Support\MediaAccess`, or the sign
  endpoint will refuse it.

### Running the backend tests

The backend image is built without dev dependencies and the host may have no PHP, so run PHPUnit
in a throwaway container (from the repo root, after `docker compose build api`):

```bash
docker run --rm --entrypoint bash -v "$PWD/backend":/src:ro -e HOME=/tmp -e COMPOSER_HOME=/tmp/c \
  aivideoclipper-api -c '
    mkdir /tmp/t && cd /src && tar --exclude=./vendor --exclude=./storage/app -cf - . | tar -xf - -C /tmp/t
    cd /tmp/t && rm -f bootstrap/cache/*.php && mkdir -p storage/framework/{cache,sessions,views} storage/logs
    export APP_KEY=base64:$(openssl rand -base64 32) APP_ENV=testing
    composer install --no-interaction --no-scripts -q && vendor/bin/phpunit'
```

Add `--filter=MediaSigningTest` to `phpunit` to run a single class.

## Running it

### Quick start: `start-all`

The easiest way to start everything is the bundled script at the repo root — it
opens one window per process and auto-detects which optional services you need
by reading `backend/.env`:

```powershell
.\start-all.ps1     # PowerShell
```

```bat
start-all.bat        :: cmd.exe, if you don't want to run PowerShell scripts
```

Both scripts are equivalent and always start the six **required** processes
(Redis, Laravel API, queue worker, batch downloads worker, scheduler, Next.js
frontend). They then auto-detect three **optional** services and start each
one only when it looks needed/installed:

| Service | Started automatically when... | Force flags |
|---|---|---|
| `whisper-engine` | `backend/.env` has `AI_TRANSCRIPTION_PROVIDER=whisper_engine` | `-WhisperEngine` / `-NoWhisperEngine` |
| `face-tracker` | `backend/.env` has `AI_REFRAMING_PROVIDER=face_tracker` | `-FaceTracker` / `-NoFaceTracker` |
| `instagram-automation` | `tools/instagram-automation/node_modules` exists (no `.env` mode switch — Instagram connect/publish always needs it, see `tools/instagram-automation/README.md`) | `-InstagramAutomation` / `-NoInstagramAutomation` |

Pass the matching `-No...` flag to skip a service that would otherwise
auto-start (e.g. you want whisper-engine's window closed to save RAM), or the
plain flag to force-start one that wouldn't otherwise qualify. Flags work
identically on both scripts, e.g.:

```powershell
.\start-all.ps1 -NoWhisperEngine -InstagramAutomation
```

```bat
start-all.bat -NoWhisperEngine -InstagramAutomation
```

Close a window to stop that individual process, or run `.\stop-all.ps1` /
`stop-all.bat` to force-stop everything at once — useful when a previous
session left something running in the background and `start-all` now fails
with a port-already-in-use error. It identifies processes by the port they
listen on or their exact command line (never by bare process name), so it
won't touch unrelated PHP/Node/Redis processes elsewhere on the machine (e.g.
WAMP's own PHP). Run `stop-all`, then `start-all` again.

### Running processes manually

If you'd rather run things by hand (or in your own terminal multiplexer), six
things need to be running at once:

```bash
# 1. Redis (portable, no install)
tools/redis/redis-server.exe

# 2. Laravel API
cd backend
php artisan serve --host=127.0.0.1 --port=8000

# 3. Queue worker (runs the actual video pipeline — required)
cd backend
php artisan queue:work redis --queue=default

# 3a. Batch downloads worker (the batch autobot's downloader — required; without
# it, a created batch just sits there since nothing ever downloads its videos)
cd backend
php artisan queue:work redis --queue=batch-downloads

# 3b. Scheduler (auto-fails ProcessingJob rows stuck "running" from a dead worker — required)
cd backend
php artisan schedule:work

# 4. Next.js frontend
cd frontend
npm run dev
```

Then open http://localhost:3000. PostgreSQL runs as a Windows service already (installed
via winget) — no separate step needed there.

**Optional processes** — only needed depending on which providers you've enabled
in `backend/.env`:

```bash
# Whisper engine — self-hosted transcription, only if AI_TRANSCRIPTION_PROVIDER=whisper_engine
cd tools/whisper-engine
./venv/Scripts/python main.py

# Face tracker — self-hosted smart-crop reframing, only if AI_REFRAMING_PROVIDER=face_tracker
cd tools/face-tracker
./venv/Scripts/python main.py

# Instagram automation — self-hosted browser login/publish, only needed to connect/publish
# Instagram accounts (no .env mode switch, unlike the two above — it's always the real thing)
cd tools/instagram-automation
npm start
```

See `tools/whisper-engine/README.md`, `tools/face-tracker/README.md`, and
`tools/instagram-automation/README.md` for setup and configuration of each.

**Login**: `admin@clipper.test` / `password` (admin) or `demo@clipper.test` / `password`
(regular user). Both were seeded by `php artisan db:seed`.

If you restart your terminal, `ffmpeg`/`ffprobe`/`yt-dlp` will resolve from PATH directly
(winget added them); until then `backend/.env` points at their absolute install paths.

## Branding and theme

The product is branded **ClipWize**. Everything visual is driven from a few places, so a future
rebrand is a small change:

- **Colours** — CSS variables in `frontend/src/app/globals.css` (`:root`), exposed to Tailwind as
  `bg-accent`, `text-accent`, `text-accent-2`, etc. Palette: emerald `--accent` `#10b981`, amber
  `--accent-2` `#fbbf24` (the logo's spark), green-tinted near-black surfaces.
  Use `text-accent-foreground` (not `text-white`) on a solid `bg-accent` fill — white on emerald
  fails contrast.
- **Logo** — source of truth is `frontend/public/logo.svg` (Play-W tile + amber spark). Rendered
  files: `public/logo.png` (1024), `src/app/icon.png` (64), `src/app/apple-icon.png` (180),
  `src/app/favicon.ico` (32 + 64). The wordmark ("Clip" + accent "Wize") lives in
  `src/components/layout/BrandMark.tsx`, used by the header and sidebar.
- **Regenerating PNGs after editing the SVG** — render it with the Playwright Chromium already on
  disk (macOS shown; adjust the path elsewhere), one run per size:
  ```bash
  cd frontend
  printf '<body style="margin:0;background:transparent"><img src="file://%s/public/logo.svg" width="1024" height="1024" style="display:block">' "$PWD" > /tmp/logo.html
  ~/Library/Caches/ms-playwright/chromium_headless_shell-*/chrome-headless-shell-mac-arm64/chrome-headless-shell \
    --no-sandbox --default-background-color=00000000 --hide-scrollbars --window-size=1024,1024 \
    --screenshot=public/logo.png file:///tmp/logo.html
  ```
  Repeat with 64 / 180 for `icon.png` / `apple-icon.png`.
- **Deliberately not rebranded**: the default colour `#7C5CFF` of the *rendered video* progress bar
  (`RenderClipJob`, `LayerCompositionService`, `TemplatePreviewService`, `lib/layers.ts`) — changing
  it would recolour existing templates on re-render; the `clipper-auth` localStorage key (renaming
  logs everyone out); and the `allowedDevOrigins` hosts in `next.config.ts`.

## What's real vs. mocked

| Piece | Status |
|---|---|
| Upload / URL import (YouTube, TikTok, IG, FB, X, Vimeo, direct links) | **Real** — via yt-dlp |
| Video probing, thumbnailing, audio extraction | **Real** — via FFmpeg |
| Transcription | Pluggable — `mock` (deterministic filler), `openai` (real, paid Whisper), or `whisper_engine` (real, self-hosted, see `tools/whisper-engine`) |
| Moment detection, scoring, hashtags | Pluggable — `mock` (deterministic filler), `openai` (real, paid), or `ollama` (real, self-hosted — point `OLLAMA_URL` at your own server) |
| Smart reframing (crop keyframes) | **Mocked** heuristic (centered crop + alternating pan) behind `ReframingProvider` — real face/speaker tracking would replace this |
| Clip rendering (trim, crop, aspect ratio, watermark) | **Real** — FFmpeg |
| Auto captions incl. word-by-word highlight | **Real** — generated as ASS/SRT and burned in with FFmpeg, from the mock transcript |
| Templates + versioning | **Real** — full CRUD, immutable versions, 4 seeded system templates |
| Social account connect | Per-platform: **YouTube** and **Facebook** are real OAuth (registered Google/Meta apps); **Instagram** is real via self-hosted browser automation (username/password login, see `tools/instagram-automation`); **TikTok/Twitter/LinkedIn** are still mocked. All go through the same `SocialProvider` interface (`SocialProviderManager`) |
| Publishing | Real for YouTube/Facebook/Instagram (actually posts); mocked for TikTok/Twitter/LinkedIn — queued per-platform jobs with retry/backoff, ~6% simulated failure rate to exercise the retry path, produces a fake post URL |
| Analytics / metrics sync | Interface exists (`fetchMetrics`) but **no UI wired to it** — out of scope this pass |
| Admin panel | **Real** — stats, users, project list, processing job monitor, AI config knobs |

To wire in a real provider later: implement the relevant interface in
`backend/app/Services/AI/Contracts/*` or `backend/app/Services/Social/Contracts/*`,
register it in `AIServiceProvider`/`SocialProviderManager`, and flip the `AI_*_PROVIDER`
env var. Nothing else in the app needs to change.

## Explicitly out of scope this pass

Per the agreed MVP scope, these sections of the original spec were **not** built:
visual drag-and-drop template canvas (templates use a property-panel editor instead),
content calendar / scheduling UI, publishing automation rules, cross-platform analytics
dashboards, and AI performance feedback loops. The data model and provider architecture
were designed so all of these could be added without a rewrite.

## Directory layout

```
backend/   Laravel API + queue jobs (app/Jobs, app/Services/AI, app/Services/Social)
frontend/  Next.js app (src/app, src/components)
tools/     Portable redis-server + test fixtures used during development
```
