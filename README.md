# AI Video Clipper

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
