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

Four things need to be running at once:

```bash
# 1. Redis (portable, no install)
tools/redis/redis-server.exe

# 2. Laravel API
cd backend
php artisan serve --host=127.0.0.1 --port=8000

# 3. Queue worker (runs the actual video pipeline — required)
cd backend
php artisan queue:work redis --queue=default

# 4. Next.js frontend
cd frontend
npm run dev
```

Then open http://localhost:3000. PostgreSQL runs as a Windows service already (installed
via winget) — no separate step needed there.

**Optional 5th process** — if `backend/.env` has `AI_TRANSCRIPTION_PROVIDER=whisper_engine`
(self-hosted transcription instead of OpenAI), also run:

```bash
# 5. Whisper engine (self-hosted transcription, only needed if AI_TRANSCRIPTION_PROVIDER=whisper_engine)
cd tools/whisper-engine
./venv/Scripts/python main.py
```

See `tools/whisper-engine/README.md` for setup.

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
| Social account connect | **Mocked** — no OAuth app registrations exist yet; `SocialProvider` interface + per-platform adapter classes (TikTok/YouTube/Instagram/Facebook/Twitter/LinkedIn) are real and ready to swap in real OAuth |
| Publishing | **Mocked** — queued per-platform jobs with retry/backoff, ~6% simulated failure rate to exercise the retry path; produces a fake post URL |
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
