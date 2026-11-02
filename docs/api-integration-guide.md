# AI Video Clipper ("ClipWize") — Backend API Guide

> **Audience:** an AI assistant (Claude Desktop) helping a developer integrate *another application* with this backend.
> **Catatan singkat (ID):** dokumen ini menjelaskan cara memanggil API backend clipper-tool dari aplikasi lain. Bagian 1–7 adalah panduan; bagian 8–9 adalah referensi yang dihasilkan otomatis dari kode (route, aturan validasi, bentuk response).

## 0. How to use this document (instructions for Claude)

- Treat Sections 8 and 9 as the source of truth for endpoint names, parameters and response fields. They were generated from the backend's route table, `validate()` rules and real responses. **Do not invent endpoints or fields**; if something is not listed, say so and ask.
- Every request except login/register and the signed media URLs needs `Authorization: Bearer <token>` (Section 2).
- Many operations are **asynchronous**: the call returns immediately and a background job does the work. You must **poll** the resource's `status` field (Section 5). Never assume the work is finished when the POST returns.
- Several endpoints **cost money or CPU** (AI calls, FFmpeg renders, publishing to public social media) and some are **irreversible** (publish, delete). When writing integration code, add confirmation steps for those and do not call them in loops or retries without the developer's explicit intent. The risky ones are called out in Section 7.
- Never put the bearer token, or any API key, in client-side code, logs, or source control.

## 1. What this system is

A video clipping and social-publishing platform:

1. A user creates a **Project** and adds a source **Video** (file upload or URL).
2. The backend transcribes it and an AI scores **Clip Candidates** (the best moments).
3. Chosen candidates are rendered into vertical/square/landscape **Clips** (with captions, covers, templates).
4. Clips are **published or scheduled** to connected social accounts (YouTube, Facebook, TikTok, Instagram, …) as **Social Posts**, and their performance is read back as **analytics**.
5. A **research engine** produces daily content ideas per research channel; ideas/trending topics can become **Content Briefs** (researched script) and **Cartoon Videos** (AI-illustrated shorts).
6. An **AI Assistant** (chat with tool-calling and file attachments) can read and act on the same data, with human approval for anything that changes data, posts publicly, or deletes.

## 2. Base URL and authentication

| Environment | Base URL |
|---|---|
| Public (self-hosted instance behind a reverse proxy) | `https://existcode.excoder.site/api` |
| Local machine | `http://localhost:8000/api` |

The public instance runs on the owner's own computer behind DNS + a reverse proxy, so it can be offline.

**Fallback when the public domain is unreachable** (DNS/proxy/tunnel down, but the computer is on): use the local address instead. It is the *same backend and database*, so **the same bearer token keeps working** and only the base URL changes:

| From | Base URL |
|---|---|
| Same computer | `http://localhost:8000/api` |
| Another device on the same LAN | `http://<LAN-IP>:8000/api` (needs port 8000 allowed in the Windows firewall) |

Plain **HTTP**, not HTTPS. Signed media URLs in responses follow the address you called (`http://localhost:8000/api/media/...` when you call localhost), so they stay usable. Make the base URL a configuration value in the integrating app so switching is a one-line change. Not possible without the public domain: connecting *new* social accounts via OAuth and TikTok login (their redirect URIs are registered to the domain); publishing through accounts that are already connected still works. **If you are Claude Desktop (sandboxed code execution): the local addresses above do NOT work for you** — `localhost`/LAN addresses point at the sandbox, and its egress proxy answers `403` for anything not allow-listed (that 403 comes from the proxy, not from this API, which answers `401` to an unauthenticated `/auth/me`). You can only reach this API through a public hostname allowed in the sandbox network settings; when the main domain is down you need a second public hostname (e.g. a Cloudflare Tunnel or Tailscale Funnel URL) — ask the owner for the current one and use it as the base URL. Full operating notes: `docs/penggunaan-lokal.md`. Confirm the base URL with the developer; all paths below are relative to it (e.g. `/projects` means `{BASE}/projects`).

### Get a token

```bash
curl -X POST "$BASE/auth/login" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"integration@example.com","password":"********"}'
```

Response `200`:

```json
{ "user": { "id": 1, "name": "…", "email": "…", "role": "user", "created_at": "…" },
  "token": "12|abcdef…" }
```

- Use it on every call: `Authorization: Bearer 12|abcdef…` and `Accept: application/json`.
- **Tokens do not expire** (Sanctum `expiration` is `null`) and carry full access to that user's data. Treat them like a password. Revoke with `POST /auth/logout` (revokes the token used on that call).
- Wrong credentials → `401 {"message":"Invalid credentials."}`. Missing/invalid token on other routes → `401 {"message":"Unauthenticated."}`.
- `POST /auth/register` (`name`, `email`, `password`, `password_confirmation`) creates a normal-role user and returns the same `{user, token}` with `201`.
- **Recommendation:** create a *dedicated* user for the integration instead of reusing a person's account. All data is scoped per user: an integration user only sees projects, clips, accounts and posts it owns (or that were created under it).
- Roles: `user` and `admin`. Endpoints under `/admin/*` require `admin` (listed last in Section 9; most integrations never need them).
- There is **no static API key** for this backend's own API. (Keys in the server's `.env` belong to third-party services such as AI providers and social platforms and are not used to call this API.)
- TikTok login (`/auth/tiktok/*`) is a browser OAuth flow for the web app; integrations should use email/password tokens.

## 3. Conventions

- **Format:** JSON requests and responses (`Content-Type: application/json`), except file uploads which use `multipart/form-data`. Always send `Accept: application/json`, otherwise some errors may not come back as JSON.
- **IDs** are integers. Path placeholders such as `{clip}` or `{project}` are the numeric id of that resource.
- **Timestamps** are ISO-8601 strings.
- **Single resource:** most endpoints wrap it as `{ "data": { … } }`. Some (analytics, dashboard) return a plain object — see Section 8.
- **Lists:** Laravel pagination: `{ "data": [ … ], "links": {…}, "meta": { "current_page", "last_page", "per_page", "total", … } }`. Use `?page=N` and `?per_page=N` (individual handlers may cap `per_page`; check `meta.per_page` in the response for the effective value).
- **Validation errors** → `422`:
  ```json
  { "message": "The title field is required.", "errors": { "title": ["The title field is required."] } }
  ```
- **Other errors:** `401` unauthenticated, `404` not found *or not yours* (ownership failures are usually reported as 404 rather than 403), `422` for business-rule rejections with `{"message": "…"}` (e.g. "Clip must finish rendering before it can be published."), `429` rate limited, `5xx` server errors.
- **Rate limits** (per user, falling back to IP):
  | Limiter | Limit | Applies to |
  |---|---|---|
  | `api` | 300 / minute | every authenticated route |
  | `expensive` | 20 / minute | routes that start AI/FFmpeg/yt-dlp work (marked in Section 9) |
  | `auth` | 5 / minute per email+IP, 20 / minute per IP | login/register |

  On `429` read the `Retry-After` header and back off.
- **CORS:** `api/*` allows any origin and header, without credentials. Browser apps can call it directly with a Bearer token; prefer calling from your own server so the token never reaches a browser.
- **No idempotency keys.** Repeating a POST repeats the action (a second publish creates a second post). Do not blindly retry non-GET requests after a timeout; check the resource's state first.

## 4. Media files and signed URLs

Video, thumbnail, cover and image fields (e.g. `url`, `thumbnail_url`, `cover_url`, `image_url`, `srt_url`) are **absolute, pre-signed URLs**:

```
https://…/api/media/<path>?expires=<unix>&signature=<hmac>
```

- They work **without** an `Authorization` header (so you can hand them to a `<video>` tag or download with plain `curl -L`).
- They **expire** (roughly 24–25 hours). Never store them long-term; store the resource id and re-fetch the resource, or refresh with `POST /media/sign` body `{"paths": ["clips/…/final.mp4", …]}` (max 100) which returns `{ "data": { "<path>": "<fresh signed url or null>" } }` (null = you are not allowed to access that path).
- Uploading source videos: `POST /projects/{project}/videos` with `multipart/form-data` field `file` (mp4, mov, mkv, webm, avi; up to ~5 GB) **or** a JSON/form field `url` (a public http(s) URL; the server downloads it with yt-dlp).

## 5. Domain model and lifecycles (statuses to poll)

```
Project ─┬─ Video (source) ── Transcript
         ├─ ClipCandidate (AI-scored moments)
         └─ Clip (rendered output) ── SocialPost (one per destination account) ── metrics
SocialAccount (connected platform account)   PublishingProfile (named group of accounts)
ContentChannel ── ResearchRun ── ContentIdea ──▶ ContentBrief ──▶ CartoonVideo (scenes)
AssistantConversation ── messages / actions
```

Status values (poll `GET` on the resource until a terminal state):

| Resource | Statuses (terminal in **bold**) |
|---|---|
| Project | draft, uploading, processing, transcribing, analyzing, generating_clips, rendering, **completed**, **failed** |
| Video | processing, **ready**, **failed** |
| Clip | queued, rendering, **completed**, **failed** |
| SocialPost | ready, scheduled, uploading, publishing, retrying, **published**, **failed**, **cancelled** |
| ContentBrief | pending, searching, fetching_sources, generating_script, finding_videos, **completed**, **failed**, **cancelled** |
| CartoonVideo | pending, storyboarding, **storyboard_ready** (waits for you to call render), rendering, assembling, **completed**, **needs_attention** (some scenes failed; re-render retries only those), **failed**, **cancelled** |
| ContentIdea | idea, selected, scripting, draft, approved, published, rejected (a workflow label, set by users, not a job state) |
| AssistantConversation | **idle**, running, **awaiting_approval**, **failed** |

Suggested polling: every 2–5 s for short jobs, 10–30 s for renders and research; give up after a sensible timeout and surface `failure_reason` (present on failed resources).

## 6. Common workflows (curl)

`BASE` and `TOKEN` below are placeholders. All examples send `-H "Authorization: Bearer $TOKEN" -H "Accept: application/json"`.

### 6.1 Video → clips

```bash
# 1. project
curl -X POST "$BASE/projects" -H "Content-Type: application/json" -d '{"title":"Episode 12"}'          # → data.id = P

# 2. add the source video (URL or file)
curl -X POST "$BASE/projects/P/videos" -F 'url=https://www.youtube.com/watch?v=…'
curl -X POST "$BASE/projects/P/videos" -F 'file=@episode12.mp4'
#    201; project.status = uploading. Poll GET /projects/P until project.video.status = "ready"
#    (project.status returns to "draft") — or "failed" with failure_reason.

# 3. transcribe + score moments (async)
curl -X POST "$BASE/projects/P/analyze"
#    Poll GET /projects/P until status is no longer processing/transcribing/analyzing.
curl "$BASE/projects/P/clip-candidates"                              # AI-scored moments

# 4. render clips (async). Either pick candidates or a mode.
curl -X POST "$BASE/projects/P/generate-clips" -H "Content-Type: application/json" \
     -d '{"mode":"top_3","aspect_ratio":"9:16","subtitles_enabled":true}'
#    201, returns the created clips (status queued). Poll GET /clips/{id} until "completed";
#    then clip.url is a signed MP4 URL.
```

### 6.2 Edit clip metadata, publish or schedule

```bash
curl -X PATCH "$BASE/clips/C" -H "Content-Type: application/json" \
     -d '{"title":"…","caption":"…","hashtags":["tips","editing"]}'

curl "$BASE/social-accounts"                 # pick ids with status "connected"
# publish now (omit scheduled_at) or schedule:
curl -X POST "$BASE/social-posts" -H "Content-Type: application/json" \
     -d '{"clip_id":C,"social_account_ids":[A1,A2],"scheduled_at":"2026-11-05T09:00:00+07:00"}'
#    The clip must be "completed". One SocialPost is created per account (isolated failures).
curl "$BASE/social-posts?status=scheduled&per_page=25"
curl -X POST "$BASE/social-posts/S/publish-now"      # publish an existing scheduled post immediately
curl -X POST "$BASE/social-posts/S/retry"            # retry a failed post
curl -X POST "$BASE/clips/bulk-schedule" -H "Content-Type: application/json" \
     -d '{"clip_ids":[C1,C2,C3],"social_account_ids":[A1],"scheduled_at":"2026-11-05T09:00:00+07:00","gap_min_minutes":30,"gap_max_minutes":90}'
```

Connecting a new social account is an **OAuth flow the account owner must complete in a browser**: `GET /social-accounts/{platform}/authorize` returns `{ "authorization_url": "…" }`; send the human to that URL. The provider redirects to the backend's callback, which stores the connection. An integration cannot (and should not) complete it on the user's behalf. Do not use `POST /social-accounts/connect` for Instagram — it takes a username/password; prefer OAuth platforms.

### 6.2b Publish a finished video made elsewhere (import, then publish)

`POST /clips/import` registers an **already-rendered video file** as a ready-to-publish clip, skipping the analyze/render pipeline. It only *registers* the clip — it never posts anything. Publishing is the separate, explicit call from 6.2.

```bash
curl -X POST "$BASE/clips/import" \
  -F 'file=@final_video.mp4' \
  -F 'title=My video title' \
  -F 'caption=Description text' \
  -F 'hashtags[]=tips' -F 'hashtags[]=editing'
#   optional: -F 'project_id=P'  (attach to one of YOUR existing projects instead of creating a new one)
# → 201 { "data": { "id": C, "status": "completed", "aspect_ratio": "9:16", "duration": 12.3, "url": "<signed mp4 url>", … } }

# then publish/schedule it exactly like any clip:
curl -X POST "$BASE/social-posts" -H "Content-Type: application/json" \
     -d '{"clip_id":C,"social_account_ids":[A1],"scheduled_at":"2026-11-05T09:00:00+07:00"}'
```

- Accepted: mp4, mov, webm, mkv (checked with ffprobe, not just the MIME header). Default limits: 2048 MB and 3 hours (server-configurable). Larger or non-video files → `422`.
- `aspect_ratio` is detected from the file and rounded to the nearest of `9:16`, `1:1`, `16:9`.
- The clip is created `completed` immediately (no polling needed) and a thumbnail is generated.
- Each call creates a new project unless you pass `project_id`. There is no de-duplication: importing the same file twice creates two clips.

### 6.3 Analytics

```bash
curl "$BASE/analytics?days=30"
# → totals{posts,views,likes,comments,shares,saves,engagement_rate}, by_platform[], top_posts[], timeseries[],
#   last_synced_at, awaiting_first_sync.  Metrics are synced from the platforms periodically;
#   POST /social-posts/{id}/sync-metrics refreshes one post.
```

### 6.4 Research → brief → cartoon video

```bash
curl "$BASE/content-channels"                                         # research channels
curl -X POST "$BASE/content-channels/CH/research-runs"                # 202; poll GET /research/runs/{id}
curl "$BASE/content-ideas?content_channel_id=CH&min_priority=60"      # ideas, best first
curl -X POST "$BASE/content-briefs" -H "Content-Type: application/json" -d '{"topic":"…"}'   # async; poll GET /content-briefs/{id}
curl -X POST "$BASE/content-briefs/B/regenerate-script"               # rerun only the script step
# cartoon short from an idea or brief (source_type: content_idea | content_brief)
curl -X POST "$BASE/cartoon-videos" -H "Content-Type: application/json" -d '{"source_type":"content_brief","source_id":B}'
#   poll GET /cartoon-videos/V until "storyboard_ready" → review/edit scenes (PATCH /cartoon-videos/V/scenes/{scene})
curl -X POST "$BASE/cartoon-videos/V/render"                          # spends image/video AI credits
#   poll until "completed" (then url + clip_id) or "needs_attention" (call render again to retry failed scenes)
```

### 6.4b Text-to-speech

`POST /tts` turns text into an MP3 with the server's configured speech provider. It is **synchronous** (a few seconds for a short text) and counts against the `expensive` limiter.

```bash
curl -X POST "$BASE/tts" -H "Content-Type: application/json" \
     -d '{"text":"Halo, ini uji suara dari API."}'
# → 201
# { "data": { "path": "uploads/1/tts/<uuid>.mp3", "url": "<signed url>", "format": "mp3",
#             "duration_seconds": 4.2, "characters": 37, "size_bytes": 201644, "retention_days": 7 } }
```

- `text`: required, max 4000 characters by default (`422` beyond that).
- `voice` (optional, max 64): **honored only when the server uses the OpenAI speech provider**; with the 9Router provider the voice is fixed by the server's model configuration and this field is ignored. Don't rely on it to switch voices.
- Download `url` right away: the file is **deleted after `retention_days` (default 7)** and the signed URL itself expires sooner (Section 4). Refresh the URL with `POST /media/sign` using `path` while the file still exists.
- Failures of the upstream speech provider come back as `502` with a short message — retry later or ask the server owner to check the TTS configuration; `422` means your request was invalid.
- There is no per-user daily quota; only the 20/min limiter and the per-request character cap protect the speech provider's spend, so don't call it in unbounded loops.

### 6.5 AI Assistant from another app

The assistant answers questions and performs actions using the user's own data. **Anything that changes data, publishes, or deletes is held as a pending action until a human approves it** — your integration should surface those to the user, not auto-approve them.

```bash
# (optional) upload files first; returns attachment ids. Images, PDF, DOCX, XLSX, CSV, text.
curl -X POST "$BASE/assistant/attachments" -F 'files[]=@report.csv' -F 'files[]=@screenshot.png'

# send a message (creates a conversation when conversation_id is omitted) → 202
curl -X POST "$BASE/assistant/messages" -H "Content-Type: application/json" \
     -d '{"content":"Analyze last 30 days performance","attachment_ids":[12]}'

# the reply is produced by a background job: poll until status != "running"
curl "$BASE/assistant/conversations/CV"
#   status "idle"              → read the last assistant message in data.messages
#   status "awaiting_approval" → data.actions[] with status "pending" (tool, risk, summary, arguments).
#                                Show them to a human, then:
curl -X POST "$BASE/assistant/conversations/CV/decide" -H "Content-Type: application/json" \
     -d '{"decisions":{"7":"approve","8":"reject"}}'      # keys are action ids; resumes once ALL are decided
#   status "failed"            → data.failure_reason
curl -X POST "$BASE/assistant/conversations/CV/stop"
```

A new message is rejected with `422` while the conversation is `running` or `awaiting_approval`.

## 7. Gotchas and safety notes for integrators

- **Irreversible / public:** `POST /social-posts`, `POST /clips/bulk-schedule`, `POST /social-posts/{id}/publish-now` and `/retry` publish to public platforms. `DELETE` on projects, clips, posts, briefs and cartoon videos is permanent. Require explicit human confirmation in your UI.
- **Costly:** analyze, generate-clips, regenerate, research runs, content briefs, cartoon render, text-to-speech (`/tts`), clip import (disk + ffprobe/ffmpeg) and assistant messages use AI credits/CPU/disk and are rate limited to 20/min.
- **Imported clips are not published automatically.** `/clips/import` only registers the file; nothing reaches a social platform until `POST /social-posts` is called.
- **Platforms:** Facebook, Instagram, TikTok and YouTube publish for real. LinkedIn and Twitter/X are **simulated (mock) providers** in this deployment — a "published" post there does not exist on the real platform.
- **Eventual consistency:** after a POST, re-GET the resource to see the new `status`; list endpoints can lag a few seconds behind running jobs.
- **Signed URLs expire** (Section 4); don't cache them as permanent links.
- **Ownership = 404.** A 404 on an id you believe exists usually means it belongs to another user.
- **Partial failure:** publishing to several accounts creates independent posts; some can fail while others succeed — inspect each post's `status` / `error_message`.
- **Dates and time zones:** send scheduled times with an explicit offset (e.g. `+07:00`); `scheduled_at` must be in the future when editing a post.
- **Do not log the bearer token** or signed URLs (they grant access until they expire).
- **Not covered here:** admin endpoints (backups, settings, user management) and the OAuth callback routes, which are for the web app.

---

## 8. Response shapes (generated from real responses)

Field names and types only (values omitted for privacy). `{…}` lists nested fields one level deep. `null` means the sampled row had no value — the type can be a string/number when populated. Envelope: most responses are `{ "data": … }`; lists add `links` and `meta`.

**`GET /api/auth/me`** → HTTP 200
- Envelope: `data`
- `data` is an object: 
  - `id`: int, `name`: string, `email`: string, `role`: string, `created_at`: string

**`GET /api/dashboard`** → HTTP 200
- Top-level object: 
  - `stats`: object {total_projects:int, total_videos:int, total_clips_generated:int, clips_completed:int, clips_processing:int, clips_failed:int, jobs_active:int, jobs_failed:int}, `recent_projects`: object {data:array}

**`GET /api/analytics?days=30`** → HTTP 200
- Top-level object: 
  - `range_days`: int, `totals`: object {posts:int, views:int, likes:int, comments:int, shares:int, saves:int, engagement_rate:number}, `by_platform`: array of {platform:string, posts:int, views:int, likes:int, comments:int, shares:int, saves:int, avg_views:int}, `top_posts`: array of {id:int, clip_id:int, title:string, thumbnail_url:string, platform:string, account_name:string, post_url:string, published_at:string, metrics:object}, `timeseries`: array of {date:string, views:int, likes:int}, `last_synced_at`: string, `awaiting_first_sync`: int

**`GET /api/projects`** → HTTP 200
- Envelope: `data` + `links`, `meta`
- `data` is a list of items; each item: 
  - `id`: int, `title`: string, `description`: null, `origin_type`: null, `origin_id`: null, `status`: string, `failure_reason`: null, `video`: object {id:int, project_id:int, source_type:string, source_url:string, title:null, original_filename:null, url:null, thumbnail_url:null, thumbnail_strip_url:null, thumbnail_strip_tile_count:int, waveform_url:null, duration_seconds:null, width:null, height:null, resolution:null, file_size_bytes:null, status:string, failure_reason:null, has_transcript:null, created_at:string, updated_at:string}, `videos`: array of {id:int, project_id:int, source_type:string, source_url:string, title:null, original_filename:null, url:null, thumbnail_url:null, thumbnail_strip_url:null, thumbnail_strip_tile_count:int, waveform_url:null, duration_seconds:null, width:null, height:null, resolution:null, file_size_bytes:null, status:string, failure_reason:null, has_transcript:null, created_at:string, updated_at:string}, `clip_candidates_count`: int, `clips_count`: int, `last_edited_at`: string, `created_at`: string, `updated_at`: string

**`GET /api/projects/{id}`** → HTTP 200
- Envelope: `data`
- `data` is an object: 
  - `id`: int, `title`: string, `description`: null, `origin_type`: null, `origin_id`: null, `status`: string, `failure_reason`: null, `video`: object {id:int, project_id:int, source_type:string, source_url:string, title:null, original_filename:null, url:null, thumbnail_url:null, thumbnail_strip_url:null, thumbnail_strip_tile_count:int, waveform_url:null, duration_seconds:null, width:null, height:null, resolution:null, file_size_bytes:null, status:string, failure_reason:null, has_transcript:null, created_at:string, updated_at:string}, `videos`: array of {id:int, project_id:int, source_type:string, source_url:string, title:null, original_filename:null, url:null, thumbnail_url:null, thumbnail_strip_url:null, thumbnail_strip_tile_count:int, waveform_url:null, duration_seconds:null, width:null, height:null, resolution:null, file_size_bytes:null, status:string, failure_reason:null, has_transcript:null, created_at:string, updated_at:string}, `clip_candidates_count`: int, `clips_count`: int, `last_edited_at`: string, `created_at`: string, `updated_at`: string

**`GET /api/clips`** → HTTP 200
- Envelope: `data` + `links`, `meta`
- `data` is a list of items; each item: 
  - `id`: int, `project_id`: int, `project_title`: string, `video_id`: int, `clip_candidate_id`: int, `template`: object {id:int, name:string, slug:string, description:string, thumbnail_url:string, preview_url:string, preview_status:string, aspect_ratio:string, resolution:object, status:string, is_system:bool, created_at:string, updated_at:string}, `template_version_id`: int, `title`: string, `caption`: string, `hashtags`: array, `start_time`: int, `end_time`: number, `duration`: number, `speed`: int, `volume`: int, `aspect_ratio`: string, `segments`: null, `additional_video_clips`: null, `crop_config`: object {mode:string, keyframes:array}, `scenes`: null, `subtitle_language`: string, `subtitles_enabled`: bool, `subtitle_config`: null, `custom_subtitle_format`: null, `caption_cues`: null, `layer_overrides`: null, `reaction_layout`: null, `reaction_script`: string, `reaction_tone`: string, `intro_enabled`: bool, `outro_enabled`: bool, `intro_voice`: null, `reference_url`: null, `status`: string, `is_published`: bool, `video_title`: string, `source_channel`: string, `published_platforms`: array, `published_targets`: array of {social_account_id:int, platform:string, account_name:string}, `scheduled_targets`: array, `progress`: int, `failure_reason`: null, `url`: string, `thumbnail_url`: string, `cover_template_id`: int, `cover_text`: string, `cover_kicker`: string, `cover_subline`: string, `cover_url`: string, `srt_url`: string, `output_size_bytes`: int, `rendered_at`: string, `created_at`: string, `updated_at`: string

**`GET /api/clips/{id}`** → HTTP 200
- Envelope: `data`
- `data` is an object: 
  - `id`: int, `project_id`: int, `project_title`: string, `video_id`: int, `clip_candidate_id`: int, `template`: object {id:int, name:string, slug:string, description:string, thumbnail_url:string, preview_url:string, preview_status:string, aspect_ratio:string, resolution:object, status:string, is_system:bool, current_version:object, created_at:string, updated_at:string}, `template_version_id`: int, `title`: string, `caption`: string, `hashtags`: array, `start_time`: number, `end_time`: int, `duration`: number, `speed`: int, `volume`: int, `aspect_ratio`: string, `segments`: null, `additional_video_clips`: null, `crop_config`: object {mode:string, keyframes:array}, `scenes`: null, `subtitle_language`: string, `subtitles_enabled`: bool, `subtitle_config`: null, `custom_subtitle_format`: null, `caption_cues`: null, `layer_overrides`: null, `reaction_layout`: null, `reaction_script`: string, `reaction_tone`: string, `intro_enabled`: bool, `outro_enabled`: bool, `intro_voice`: null, `reference_url`: null, `status`: string, `is_published`: bool, `progress`: int, `failure_reason`: null, `url`: string, `thumbnail_url`: string, `cover_template_id`: int, `cover_text`: string, `cover_kicker`: string, `cover_subline`: string, `cover_url`: string, `cover_title_options`: array, `cover_subtitle_options`: array, `srt_url`: string, `output_size_bytes`: int, `rendered_at`: string, `created_at`: string, `updated_at`: string

**`GET /api/social-accounts`** → HTTP 200
- Envelope: `data`
- `data` is a list of items; each item: 
  - `id`: int, `platform`: string, `account_name`: string, `username`: null, `avatar_url`: string, `status`: string, `token_status`: string, `permissions`: array, `auto_publish_enabled`: bool, `default_cover_template_id`: int, `default_cover_template`: object {id:int, name:string, slug:string, description:string, thumbnail_url:string, aspect_ratio:string, config:object, status:string, is_system:bool, created_at:string, updated_at:string}, `messenger_sync_enabled`: bool, `messenger_last_synced_at`: null, `last_synced_at`: string, `created_at`: string

**`GET /api/social-posts`** → HTTP 200
- Envelope: `data` + `links`, `meta`
- `data` is a list of items; each item: 
  - `id`: int, `clip_id`: int, `clip`: object {id:int, title:string, thumbnail_url:string, url:string, duration:number, project_id:int, project_title:string}, `platform`: string, `social_account`: object {id:int, platform:string, account_name:string, username:string, avatar_url:string, status:string, token_status:string, permissions:array, auto_publish_enabled:bool, default_cover_template_id:int, messenger_sync_enabled:bool, messenger_last_synced_at:null, last_synced_at:string, created_at:string}, `title`: string, `caption`: string, `hashtags`: array, `status`: string, `scheduled_at`: string, `published_at`: string, `post_url`: string, `error_message`: null, `retry_count`: int, `thumbnail_status`: string, `thumbnail_uploaded_at`: string, `thumbnail_error`: null, `metrics`: object {views:int, likes:int, comments:int, shares:int, saves:int, watch_time:int, retention:int}, `metrics_synced_at`: string, `created_at`: string

**`GET /api/content-channels`** → HTTP 200
- Envelope: `data`
- `data` is a list of items; each item: 
  - `id`: int, `name`: string, `handle`: string, `avatar_url`: null, `description`: string, `language`: string, `timezone`: string, `is_active`: bool, `platform_id`: int, `platform`: object {id:int, key:string, name:string, type:string, default_strategy:object, enabled:bool, sort_order:int}, `niche`: string, `sub_niches`: array, `keywords`: array, `excluded_keywords`: array, `target_audience`: string, `content_style`: array, `content_types`: array, `content_formats`: array, `tone`: string, `hook_styles`: array, `scheduler_enabled`: bool, `research_frequency`: string, `research_times`: array, `schedule_times`: array, `interval_hours`: null, `ideas_per_run`: int, `min_relevance_score`: int, `min_trend_score`: int, `scoring_weights`: object {trend:number, relevance:number, originality:number, freshness:number, engagement:number, cross_source:number, performance:number}, `last_research_at`: string, `next_research_at`: string, `ideas_count`: int, `runs_count`: int, `created_at`: string, `updated_at`: string

**`GET /api/content-ideas`** → HTTP 200
- Envelope: `data` + `links`, `meta`
- `data` is a list of items; each item: 
  - `id`: int, `content_channel_id`: int, `channel`: object {id:int, name:string, handle:null, avatar_url:null, description:null, language:null, timezone:null, is_active:null, platform_id:int, platform:object, niche:string, sub_niches:array, keywords:array, excluded_keywords:array, target_audience:null, content_style:array, content_types:array, content_formats:array, tone:null, hook_styles:array, scheduler_enabled:null, research_frequency:null, research_times:array, schedule_times:array, interval_hours:null, ideas_per_run:null, min_relevance_score:null, min_trend_score:null, scoring_weights:object, last_research_at:null, next_research_at:null, created_at:null, updated_at:null}, `research_run_id`: int, `research_date`: string, `topic`: string, `title`: string, `alternative_titles`: array, `short_description`: string, `content_angle`: string, `why_this_topic`: string, `target_audience`: string, `keywords`: array, `source_summary`: string, `scores`: object {trend:int, relevance:int, originality:int, freshness:int, engagement:int, cross_source:int, performance:int, priority:int}, `priority_score`: int, `trend_score`: int, `suggested_content_type`: string, `suggested_format`: string, `status`: string, `notes`: null, `selected_at`: null, `sources_count`: int, `created_at`: string

**`GET /api/content-briefs/{id}`** → HTTP 200
- Envelope: `data`
- `data` is an object: 
  - `id`: int, `topic`: string, `region_code`: string, `source_platform`: null, `source_trending_title`: null, `source_trending_url`: null, `status`: string, `progress`: int, `message`: string, `failure_reason`: null, `sources`: array of {title:string, url:string, snippet:string, published_at:null, content_excerpt:string}, `candidate_videos`: array of {title:string, url:string, platform:string, thumbnail_url:string}, `narrative_title`: string, `narrative_hook`: string, `narrative_sections`: array of {heading:string, narration_text:string, duration_estimate_seconds:int}, `narrative_full_script`: string, `narrative_suggested_description`: string, `narrative_suggested_hashtags`: array, `started_at`: string, `finished_at`: string, `created_at`: string

**`GET /api/research/runs`** → HTTP 200
- Envelope: `data` + `links`, `meta`
- `data` is a list of items; each item: 
  - `id`: int, `content_channel_id`: int, `channel`: object {id:int, name:string, handle:null, avatar_url:null, description:null, language:null, timezone:null, is_active:null, platform_id:int, niche:null, sub_niches:array, keywords:array, excluded_keywords:array, target_audience:null, content_style:array, content_types:array, content_formats:array, tone:null, hook_styles:array, scheduler_enabled:null, research_frequency:null, research_times:array, schedule_times:array, interval_hours:null, ideas_per_run:null, min_relevance_score:null, min_trend_score:null, scoring_weights:object, last_research_at:null, next_research_at:null, created_at:null, updated_at:null}, `trigger`: string, `status`: string, `progress`: int, `message`: string, `topics_found`: int, `results_collected`: int, `ideas_generated`: int, `duplicates_skipped`: int, `providers_used`: array of {source_key:string, items:int}, `providers_failed`: array of {source_key:string, error:string, skipped:bool}, `error_message`: null, `duration_ms`: int, `started_at`: string, `finished_at`: string, `created_at`: string

**`GET /api/cartoon-videos/{id}`** → HTTP 200
- Envelope: `data`
- `data` is an object: 
  - `id`: int, `source_type`: string, `source_id`: int, `title`: string, `topic`: string, `character_description`: string, `style`: string, `voice`: null, `motion_provider`: string, `status`: string, `progress`: int, `message`: string, `failure_reason`: null, `duration_seconds`: number, `url`: string, `thumbnail_url`: string, `project_id`: int, `clip_id`: int, `estimated_cost_usd`: int, `scenes`: array of {id:int, position:int, narration:string, visual_prompt:string, motion_prompt:string, status:string, error:null, audio_duration:number, image_provider:null, clip_provider:null, image_url:string, clip_url:string}, `started_at`: string, `finished_at`: string, `created_at`: string

**`GET /api/publishing-profiles`** → HTTP 200
- Envelope: `data`
- `data` is a list of items; each item: 
  - `id`: int, `name`: string, `is_default`: bool, `social_accounts`: array of {id:int, platform:string, account_name:string, username:null, avatar_url:string, status:string, token_status:string, permissions:array, auto_publish_enabled:bool, default_cover_template_id:int, messenger_sync_enabled:bool, messenger_last_synced_at:null, last_synced_at:string, created_at:string}

**`GET /api/video-batches`** → HTTP 200
- Envelope: `data` + `links`, `meta`
- `data` is a list of items; each item: 
  - `id`: int, `name`: string, `status`: string, `settings`: object {clip_mode:string, template_id:int, aspect_ratio:string, subtitle_language:string, subtitles_enabled:bool, publishing_profile_id:int, publish_stagger_min_minutes:int, publish_stagger_max_minutes:int}, `total_items`: int, `completed_items`: int, `failed_items`: int, `started_at`: null, `finished_at`: null, `created_at`: string

**`GET /api/trending`** → HTTP 200
- Envelope: `data`
- `data` is a list of items; each item: 
  - `platform`: string, `external_id`: string, `title`: string, `source_url`: string, `thumbnail_url`: string, `author_name`: string, `view_count`: int, `like_count`: int, `comment_count`: int, `published_at`: string, `is_mock`: bool

**`GET /api/templates`** → HTTP 200
- Envelope: `data`
- `data` is a list of items; each item: 
  - `id`: int, `name`: string, `slug`: string, `description`: null, `thumbnail_url`: string, `preview_url`: string, `preview_status`: string, `category`: object {id:int, name:string, slug:string, description:null, is_custom:bool}, `aspect_ratio`: string, `resolution`: object {width:int, height:int}, `status`: string, `is_system`: bool, `current_version`: object {id:int, template_id:int, version_number:int, label:string, is_published:bool, config:object, created_at:string}, `created_at`: string, `updated_at`: string

**`GET /api/channel-watches`** → HTTP 200
- Envelope: `data`
- `data` is a list of items; each item: 
  - `id`: int, `platform`: string, `channel_id`: string, `channel_title`: string, `channel_url`: string, `thumbnail_url`: string, `is_active`: bool, `settings`: object {clip_mode:string, template_id:null, aspect_ratio:string, subtitle_language:string, subtitles_enabled:bool, publishing_profile_id:int, publish_stagger_min_minutes:int, publish_stagger_max_minutes:int}, `last_video_id`: string, `last_video_published_at`: string, `last_checked_at`: string, `last_error`: null, `created_at`: string

**`GET /api/media-uploads`** → HTTP 200
- Envelope: `data`
- `data` is a list of items; each item: 
  - `path`: string, `url`: string, `kind`: string, `name`: string, `size_bytes`: int, `uploaded_at`: string, `waveform_path`: null, `waveform_url`: null


---

## 9. Endpoint reference (generated from the route table and `validate()` rules)

How to read this: every route is under the base URL (prefix `/api` is already part of the base URL in Section 2, so these paths show it for completeness). `{placeholder}` segments are numeric ids. **Parameters** are taken from the handler's validation rules: `required`/`sometimes`/`nullable` as in Laravel; `one-of(a/b/c)` lists allowed values; `array` fields with `.*` describe their items. For `GET` routes without a validation block, *Query parameters read by the handler* lists the filters the code reads (all optional). Where a rule shows a PHP constant instead of values (e.g. `one-of(content_idea/content_brief)`), the values are: cartoon `source_type` = `content_idea` | `content_brief`; project `origin_type` = `content_idea` | `content_brief` | `trending`; content idea `status` = idea, selected, scripting, draft, approved, published, rejected. A route listed without parameters takes none. Rate limit `expensive` = 20/min (Section 3). Routes marked **admin role only** are for administrators.

### `/api/analytics`

#### `GET /api/analytics`
- Auth: Bearer token · Rate limit: api
- Handler: `AnalyticsController::index`
- Query parameters read by the handler: `days`

### `/api/assistant`

#### `GET /api/assistant/config`
- Auth: Bearer token · Rate limit: api
- Handler: `AssistantController::config`

#### `GET /api/assistant/conversations`
- Auth: Bearer token · Rate limit: api
- Handler: `AssistantController::index`
- Query parameters read by the handler: `per_page`

#### `GET /api/assistant/conversations/{conversation}`
- Auth: Bearer token · Rate limit: api
- Handler: `AssistantController::show`

#### `PATCH /api/assistant/conversations/{conversation}`
- Auth: Bearer token · Rate limit: api
- Handler: `AssistantController::update`

#### `DELETE /api/assistant/conversations/{conversation}`
- Auth: Bearer token · Rate limit: api
- Handler: `AssistantController::destroy`

#### `POST /api/assistant/conversations/{conversation}/decide`
- Auth: Bearer token · Rate limit: api
- Handler: `AssistantController::decide`
- Parameters (JSON/form body):
  - `decisions`: required | array | min:1
  - `decisions.*`: required | one-of(approve/reject)

#### `POST /api/assistant/conversations/{conversation}/stop`
- Auth: Bearer token · Rate limit: api
- Handler: `AssistantController::stop`

#### `POST /api/assistant/attachments`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `AssistantController::uploadAttachments`
- Parameters (JSON/form body):
  - `files`: required | array | min:1 | max 8 files per message by default (server setting)
  - `files.*`: file | max 20 MB per file by default (server setting ASSISTANT_MAX_FILE_MB)

#### `POST /api/assistant/messages`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `AssistantController::sendMessage`
- Parameters (JSON/form body):
  - `conversation_id`: nullable | integer
  - `content`: nullable | string | max:20000
  - `attachment_ids`: sometimes | array | max 8 files per message by default (server setting)
  - `attachment_ids.*`: integer

### `/api/auth`

#### `POST /api/auth/register`
- Auth: public · Rate limit: auth
- Handler: `AuthController::register`
- Parameters (JSON/form body):
  - `name`: required | string | max:255
  - `email`: required | string | email | max:255 | unique:users | email
  - `password`: required | confirmed | password(min rules)

#### `POST /api/auth/login`
- Auth: public · Rate limit: auth
- Handler: `AuthController::login`
- Parameters (JSON/form body):
  - `email`: required | email
  - `password`: required | string

#### `GET /api/auth/tiktok/redirect`
- Auth: public · Rate limit: auth
- Handler: `AuthController::tiktokRedirect`
- Parameters (query):
  - `nonce`: required | string | min:32 | max:128 | regex:/^[A-Za-z0-9_-]+$/

#### `POST /api/auth/tiktok/exchange`
- Auth: public · Rate limit: auth
- Handler: `AuthController::tiktokExchange`
- Parameters (JSON/form body):
  - `code`: required | string | size:64
  - `nonce`: required | string | max:128

#### `GET /api/auth/tiktok/callback`
- Auth: public · Rate limit: auth
- Handler: `AuthController::tiktokCallback`
- Query parameters read by the handler: `error`, `code`, `state`

#### `POST /api/auth/logout`
- Auth: Bearer token · Rate limit: api
- Handler: `AuthController::logout`

#### `GET /api/auth/me`
- Auth: Bearer token · Rate limit: api
- Handler: `AuthController::me`

### `/api/cartoon-videos`

#### `GET /api/cartoon-videos`
- Auth: Bearer token · Rate limit: api
- Handler: `CartoonVideoController::index`
- Query parameters read by the handler: `source_type`, `source_id`, `per_page`

#### `POST /api/cartoon-videos`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `CartoonVideoController::store`
- Parameters (JSON/form body):
  - `source_type`: required | one-of(content_idea/content_brief)
  - `source_id`: required | integer
  - `voice`: nullable | string | max:120

#### `GET /api/cartoon-videos/{cartoonVideo}`
- Auth: Bearer token · Rate limit: api
- Handler: `CartoonVideoController::show`

#### `PATCH /api/cartoon-videos/{cartoonVideo}`
- Auth: Bearer token · Rate limit: api
- Handler: `CartoonVideoController::update`
- Parameters (JSON/form body):
  - `title`: sometimes | string | max:255
  - `voice`: sometimes | nullable | string | max:120
  - `character_description`: sometimes | nullable | string | max:1000

#### `PATCH /api/cartoon-videos/{cartoonVideo}/scenes/{scene}`
- Auth: Bearer token · Rate limit: api
- Handler: `CartoonVideoController::updateScene`
- Parameters (JSON/form body):
  - `narration`: sometimes | string | max:1500
  - `visual_prompt`: sometimes | string | max:1500
  - `motion_prompt`: sometimes | nullable | string | max:500

#### `POST /api/cartoon-videos/{cartoonVideo}/regenerate-storyboard`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `CartoonVideoController::regenerateStoryboard`

#### `POST /api/cartoon-videos/{cartoonVideo}/render`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `CartoonVideoController::render`

#### `POST /api/cartoon-videos/{cartoonVideo}/cancel`
- Auth: Bearer token · Rate limit: api
- Handler: `CartoonVideoController::cancel`

#### `DELETE /api/cartoon-videos/{cartoonVideo}`
- Auth: Bearer token · Rate limit: api
- Handler: `CartoonVideoController::destroy`

### `/api/channel-watches`

#### `GET /api/channel-watches`
- Auth: Bearer token · Rate limit: api
- Handler: `ChannelWatchController::index`

#### `POST /api/channel-watches`
- Auth: Bearer token · Rate limit: api
- Handler: `ChannelWatchController::store`
- Parameters (JSON/form body):
  - `channel_url`: required | string | max:2048
  - `clip_mode`: sometimes | one-of(top_3/top_5/top_10/all)
  - `template_id`: nullable | exists:templates | id
  - `aspect_ratio`: sometimes | one-of(9:16/1:1/16:9)
  - `subtitle_language`: sometimes | string | max:10
  - `subtitles_enabled`: sometimes | boolean
  - `publishing_profile_id`: nullable | exists:publishing_profiles | id
  - `publish_stagger_min_minutes`: sometimes | integer | min:0 | max:1440
  - `publish_stagger_max_minutes`: sometimes | integer | min:0 | max:1440 | gte:publish_stagger_min_minutes

#### `PUT /api/channel-watches/{channel_watch}`
- Auth: Bearer token · Rate limit: api
- Handler: `ChannelWatchController::update`
- Parameters (JSON/form body):
  - `is_active`: sometimes | boolean
  - `clip_mode`: sometimes | one-of(top_3/top_5/top_10/all)
  - `template_id`: nullable | exists:templates | id
  - `aspect_ratio`: sometimes | one-of(9:16/1:1/16:9)
  - `subtitle_language`: sometimes | string | max:10
  - `subtitles_enabled`: sometimes | boolean
  - `publishing_profile_id`: nullable | exists:publishing_profiles | id
  - `publish_stagger_min_minutes`: sometimes | integer | min:0 | max:1440
  - `publish_stagger_max_minutes`: sometimes | integer | min:0 | max:1440 | gte:publish_stagger_min_minutes

#### `PATCH /api/channel-watches/{channel_watch}`
- Auth: Bearer token · Rate limit: api
- Handler: `ChannelWatchController::update`
- Parameters (JSON/form body):
  - `is_active`: sometimes | boolean
  - `clip_mode`: sometimes | one-of(top_3/top_5/top_10/all)
  - `template_id`: nullable | exists:templates | id
  - `aspect_ratio`: sometimes | one-of(9:16/1:1/16:9)
  - `subtitle_language`: sometimes | string | max:10
  - `subtitles_enabled`: sometimes | boolean
  - `publishing_profile_id`: nullable | exists:publishing_profiles | id
  - `publish_stagger_min_minutes`: sometimes | integer | min:0 | max:1440
  - `publish_stagger_max_minutes`: sometimes | integer | min:0 | max:1440 | gte:publish_stagger_min_minutes

#### `DELETE /api/channel-watches/{channel_watch}`
- Auth: Bearer token · Rate limit: api
- Handler: `ChannelWatchController::destroy`

#### `POST /api/channel-watches/{channelWatch}/check`
- Auth: Bearer token · Rate limit: api
- Handler: `ChannelWatchController::checkNow`

### `/api/clip-candidates`

#### `GET /api/clip-candidates/{clipCandidate}`
- Auth: Bearer token · Rate limit: api
- Handler: `ClipCandidateController::show`

#### `POST /api/clip-candidates/{clipCandidate}/reaction`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `ReactionController::store`
- Parameters (JSON/form body):
  - `webcam`: required | file | mimetypes:video/webm | video/mp4 | max:512000
  - `layout`: required | one-of(pip_bottom_right/pip_bottom_left/split_top_bottom/split_side_by_side)
  - `template_id`: nullable | exists:templates | id
  - `aspect_ratio`: sometimes | one-of(9:16/1:1/16:9)

### `/api/clips`

#### `GET /api/clips`
- Auth: Bearer token · Rate limit: api
- Handler: `ClipController::index`
- Query parameters read by the handler: `project_id`, `status`, `published`, `platform`, `social_account_id`, `scheduled_platform`, `scheduled_social_account_id`, `scheduled_from`, `scheduled_to`, `source_channel`, `template_id`, `aspect_ratio`, `sort_dir`, `sort_by`, `per_page`

#### `GET /api/clips/search`
- Auth: Bearer token · Rate limit: api
- Handler: `ClipController::search`
- Parameters (query):
  - `q`: required | string | min:1 | max:255
  - `project_id`: sometimes | nullable | integer

#### `GET /api/clips/source-channels`
- Auth: Bearer token · Rate limit: api
- Handler: `ClipController::sourceChannels`

#### `GET /api/clips/{clip}`
- Auth: Bearer token · Rate limit: api
- Handler: `ClipController::show`

#### `GET /api/clips/{clip}/preview-config`
- Auth: Bearer token · Rate limit: api
- Handler: `ClipController::previewConfig`

#### `PATCH /api/clips/{clip}`
- Auth: Bearer token · Rate limit: api
- Handler: `ClipController::update`
- Parameters (JSON/form body):
  - `title`: sometimes | string | max:255
  - `caption`: sometimes | nullable | string
  - `hashtags`: sometimes | array
  - `start_time`: sometimes | numeric | min:0
  - `end_time`: sometimes | numeric | min:0
  - `aspect_ratio`: sometimes | one-of(9:16/1:1/16:9)
  - `template_id`: sometimes | nullable | exists:templates | id
  - `cover_template_id`: sometimes | nullable | exists:cover_templates | id
  - `cover_text`: sometimes | nullable | string | max:120
  - `cover_kicker`: sometimes | nullable | string | max:30
  - `cover_subline`: sometimes | nullable | string | max:40
  - `speed`: sometimes | numeric | min:0.5 | max:2
  - `volume`: sometimes | numeric | min:0 | max:2
  - `subtitles_enabled`: sometimes | boolean
  - `subtitle_language`: sometimes | string | max:10
  - `subtitle_config`: sometimes | array
  - `caption_cues`: sometimes | nullable | array
  - `caption_cues.*.start`: required_with:caption_cues | numeric | min:0
  - `caption_cues.*.end`: required_with:caption_cues | numeric | min:0
  - `caption_cues.*.text`: required_with:caption_cues | string
  - `caption_cues.*.words`: sometimes | array
  - `scenes`: sometimes | array
  - `layer_overrides`: sometimes | nullable | array
  - `segments`: sometimes | nullable | array
  - `segments.*.start`: required_with:segments | numeric | min:0
  - `segments.*.end`: required_with:segments | numeric | min:0
  - `segments.*.transition_in`: sometimes | nullable | array
  - `segments.*.transition_in.type`: sometimes | one-of(none/fade/dissolve)
  - `segments.*.transition_in.duration`: sometimes | numeric | min:0.05 | max:3
  - `additional_video_clips`: sometimes | nullable | array
  - `additional_video_clips.*.video_id`: 
  - `additional_video_clips.*.start`: required | numeric | min:0
  - `additional_video_clips.*.end`: required | numeric | min:0
  - `additional_video_clips.*.transition_in`: sometimes | nullable | array
  - `additional_video_clips.*.transition_in.type`: sometimes | one-of(none/fade/dissolve)
  - `additional_video_clips.*.transition_in.duration`: sometimes | numeric | min:0.05 | max:3
  - `crop_config`: sometimes | nullable | array
  - `reaction_script`: sometimes | nullable | string
  - `intro_enabled`: sometimes | boolean
  - `outro_enabled`: sometimes | boolean
  - `intro_voice`: sometimes | nullable | string | max:64
  - `reference_url`: sometimes | nullable | string | max:2048 | url

#### `DELETE /api/clips/{clip}`
- Auth: Bearer token · Rate limit: api
- Handler: `ClipController::destroy`

#### `POST /api/clips/{clip}/duplicate`
- Auth: Bearer token · Rate limit: api
- Handler: `ClipController::duplicate`

#### `POST /api/clips/{clip}/subtitle`
- Auth: Bearer token · Rate limit: api
- Handler: `ClipController::uploadSubtitle`
- Parameters (JSON/form body):
  - `file`: required | file | max:2048 | (plus custom checks in the handler)

#### `DELETE /api/clips/{clip}/subtitle`
- Auth: Bearer token · Rate limit: api
- Handler: `ClipController::removeSubtitle`

#### `POST /api/clips/{clip}/regenerate`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `ClipController::regenerate`

#### `POST /api/clips/{clip}/reaction`
- Auth: Bearer token · Rate limit: api
- Handler: `ReactionController::updateClip`
- Parameters (JSON/form body):
  - `webcam`: required | file | mimetypes:video/webm | video/mp4 | max:512000
  - `layout`: required | one-of(pip_bottom_right/pip_bottom_left/split_top_bottom/split_side_by_side)

#### `POST /api/clips/{clip}/generate-social-metadata`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `ClipController::generateSocialMetadata`
- Parameters (JSON/form body):
  - `platforms`: sometimes | array
  - `platforms.*`: string | one-of(tiktok/youtube/instagram/facebook/twitter/linkedin)
  - `reference_url`: sometimes | nullable | string | max:2048 | url

#### `POST /api/clips/{clip}/generate-reaction-script`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `ClipController::generateReactionScript`
- Parameters (JSON/form body):
  - `voice`: sometimes | nullable | string | max:64
  - `reference_url`: sometimes | nullable | string | max:2048 | url

#### `POST /api/clips/{clip}/generate-cover`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `ClipController::generateCover`
- Parameters (JSON/form body):
  - `cover_template_id`: sometimes | nullable | exists:cover_templates | id
  - `text`: sometimes | nullable | string | max:120
  - `kicker`: sometimes | nullable | string | max:30
  - `subline`: sometimes | nullable | string | max:40

#### `POST /api/clips/export-zip`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `ClipController::exportZip`
- Parameters (JSON/form body):
  - `clip_ids`: required | array | min:1
  - `clip_ids.*`: integer

#### `POST /api/clips/import`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `ClipImportController`
- Parameters (JSON/form body):
  - `file`: required | file | mimetypes:video/mp4 | video/quicktime | video/webm | video/x-matroska | max 2048 MB by default (server setting CLIP_IMPORT_MAX_MB)
  - `title`: required | string | max:255
  - `caption`: sometimes | nullable | string | max:5000
  - `hashtags`: sometimes | array | max:30
  - `hashtags.*`: string | max:100
  - `project_id`: sometimes | nullable | integer

#### `POST /api/clips/bulk-regenerate`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `ClipController::bulkRegenerate`
- Parameters (JSON/form body):
  - `clip_ids`: required | array | min:1
  - `clip_ids.*`: integer | exists:clips | id

#### `POST /api/clips/bulk-generate-cover`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `ClipController::bulkGenerateCover`
- Parameters (JSON/form body):
  - `clip_ids`: required | array | min:1
  - `clip_ids.*`: integer | exists:clips | id

#### `POST /api/clips/bulk-delete`
- Auth: Bearer token · Rate limit: api
- Handler: `ClipController::bulkDestroy`
- Parameters (JSON/form body):
  - `clip_ids`: required | array | min:1
  - `clip_ids.*`: integer | exists:clips | id

#### `POST /api/clips/bulk-move-channel`
- Auth: Bearer token · Rate limit: api
- Handler: `ClipController::bulkMoveChannel`
- Parameters (JSON/form body):
  - `clip_ids`: required | array | min:1
  - `clip_ids.*`: integer | exists:clips | id
  - `target_social_account_id`: required | exists:social_accounts | id

#### `POST /api/clips/bulk-schedule`
- Auth: Bearer token · Rate limit: api
- Handler: `ClipController::bulkSchedule`
- Parameters (JSON/form body):
  - `clip_ids`: required | array | min:1
  - `clip_ids.*`: integer | exists:clips | id
  - `social_account_ids`: required | array | min:1
  - `social_account_ids.*`: integer | exists:social_accounts | id
  - `scheduled_at`: required | date
  - `gap_min_minutes`: sometimes | integer | min:0
  - `gap_max_minutes`: sometimes | integer | min:0

### `/api/content-briefs`

#### `GET /api/content-briefs`
- Auth: Bearer token · Rate limit: api
- Handler: `ContentBriefController::index`
- Query parameters read by the handler: `per_page`

#### `POST /api/content-briefs`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `ContentBriefController::store`
- Parameters (JSON/form body):
  - `topic`: required | string | max:2000
  - `region_code`: nullable | string | max:10
  - `source_platform`: nullable | string | max:50
  - `source_trending_title`: nullable | string | max:500
  - `source_trending_url`: nullable | string | max:2048

#### `GET /api/content-briefs/{contentBrief}`
- Auth: Bearer token · Rate limit: api
- Handler: `ContentBriefController::show`

#### `POST /api/content-briefs/{contentBrief}/regenerate-script`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `ContentBriefController::regenerateScript`

#### `POST /api/content-briefs/{contentBrief}/search-videos`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `ContentBriefController::searchVideos`
- Parameters (JSON/form body):
  - `query`: sometimes | nullable | string | max:500

#### `DELETE /api/content-briefs/{contentBrief}`
- Auth: Bearer token · Rate limit: api
- Handler: `ContentBriefController::destroy`

### `/api/content-channels`

#### `GET /api/content-channels`
- Auth: Bearer token · Rate limit: api
- Handler: `ContentChannelController::index`
- Query parameters read by the handler: `platform_id`, `is_active`

#### `POST /api/content-channels`
- Auth: Bearer token · Rate limit: api
- Handler: `ContentChannelController::store`

#### `GET /api/content-channels/{content_channel}`
- Auth: Bearer token · Rate limit: api
- Handler: `ContentChannelController::show`

#### `PUT /api/content-channels/{content_channel}`
- Auth: Bearer token · Rate limit: api
- Handler: `ContentChannelController::update`

#### `PATCH /api/content-channels/{content_channel}`
- Auth: Bearer token · Rate limit: api
- Handler: `ContentChannelController::update`

#### `DELETE /api/content-channels/{content_channel}`
- Auth: Bearer token · Rate limit: api
- Handler: `ContentChannelController::destroy`

#### `GET /api/content-channels/{contentChannel}/research-runs`
- Auth: Bearer token · Rate limit: api
- Handler: `ResearchRunController::forChannel`
- Query parameters read by the handler: `per_page`

#### `POST /api/content-channels/{contentChannel}/research-runs`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `ResearchRunController::store`

#### `GET /api/content-channels/{contentChannel}/content-ideas`
- Auth: Bearer token · Rate limit: api
- Handler: `ContentIdeaController::forChannel`
- Query parameters read by the handler: `status`, `per_page`

### `/api/content-ideas`

#### `GET /api/content-ideas`
- Auth: Bearer token · Rate limit: api
- Handler: `ContentIdeaController::index`
- Query parameters read by the handler: `content_channel_id`, `status`, `research_date`, `date_from`, `date_to`, `min_priority`, `suggested_content_type`, `platform_id`, `source_key`, `q`, `per_page`

#### `GET /api/content-ideas/{contentIdea}`
- Auth: Bearer token · Rate limit: api
- Handler: `ContentIdeaController::show`

#### `PATCH /api/content-ideas/{contentIdea}`
- Auth: Bearer token · Rate limit: api
- Handler: `ContentIdeaController::update`
- Parameters (JSON/form body):
  - `status`: sometimes | one-of(idea/selected/scripting/draft/approved/published/rejected)
  - `notes`: sometimes | nullable | string | max:5000
  - `title`: sometimes | string | max:500
  - `suggested_content_type`: sometimes | nullable | string | max:120
  - `suggested_format`: sometimes | nullable | string | max:120

#### `POST /api/content-ideas/{contentIdea}/select`
- Auth: Bearer token · Rate limit: api
- Handler: `ContentIdeaController::select`

#### `POST /api/content-ideas/{contentIdea}/reject`
- Auth: Bearer token · Rate limit: api
- Handler: `ContentIdeaController::reject`

#### `DELETE /api/content-ideas/{contentIdea}`
- Auth: Bearer token · Rate limit: api
- Handler: `ContentIdeaController::destroy`

### `/api/cover-templates`

#### `GET /api/cover-templates`
- Auth: Bearer token · Rate limit: api
- Handler: `CoverTemplateController::index`
- Query parameters read by the handler: `include_archived`

#### `GET /api/cover-templates/demo-frame`
- Auth: Bearer token · Rate limit: api
- Handler: `CoverTemplateController::demoFrame`

#### `GET /api/cover-templates/{coverTemplate}`
- Auth: Bearer token · Rate limit: api
- Handler: `CoverTemplateController::show`

### `/api/dashboard`

#### `GET /api/dashboard`
- Auth: Bearer token · Rate limit: api
- Handler: `DashboardController::index`

### `/api/facebook-messages`

#### `GET /api/facebook-messages`
- Auth: Bearer token · Rate limit: api
- Handler: `FacebookMessageController::index`
- Query parameters read by the handler: `social_account_id`, `status`, `per_page`

#### `POST /api/facebook-messages/{facebookMessage}/convert-to-idea`
- Auth: Bearer token · Rate limit: api
- Handler: `FacebookMessageController::convertToIdea`
- Parameters (JSON/form body):
  - `content_channel_id`: 
  - `title`: sometimes | nullable | string | max:500

#### `POST /api/facebook-messages/{facebookMessage}/ignore`
- Auth: Bearer token · Rate limit: api
- Handler: `FacebookMessageController::ignore`

### `/api/media`

#### `GET /api/media/{path}`
- Auth: public
- Handler: `MediaStreamController::stream`
- Query parameters read by the handler: `expires`, `signature`, `download`

#### `POST /api/media/sign`
- Auth: Bearer token · Rate limit: api
- Handler: `MediaSignController`
- Parameters (JSON/form body):
  - `paths`: required | array | min:1 | max:100
  - `paths.*`: required | string | max:1024

### `/api/media-uploads`

#### `GET /api/media-uploads`
- Auth: Bearer token · Rate limit: api
- Handler: `MediaUploadController::index`
- Parameters (query):
  - `kind`: sometimes | one-of(image/audio)

#### `POST /api/media-uploads`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `MediaUploadController::store`
- Parameters (JSON/form body):
  - `file`: 

#### `DELETE /api/media-uploads`
- Auth: Bearer token · Rate limit: api
- Handler: `MediaUploadController::destroy`

### `/api/projects`

#### `GET /api/projects`
- Auth: Bearer token · Rate limit: api
- Handler: `ProjectController::index`
- Query parameters read by the handler: `status`, `search`, `sort_dir`, `sort_by`, `per_page`

#### `POST /api/projects`
- Auth: Bearer token · Rate limit: api
- Handler: `ProjectController::store`
- Parameters (JSON/form body):
  - `title`: required | string | max:255
  - `description`: nullable | string
  - `origin_type`: nullable | string | one-of(content_idea/content_brief/trending)
  - `origin_id`: nullable | integer

#### `POST /api/projects/bulk-delete`
- Auth: Bearer token · Rate limit: api
- Handler: `ProjectController::bulkDestroy`
- Parameters (JSON/form body):
  - `project_ids`: required | array | min:1
  - `project_ids.*`: integer | exists:projects | id

#### `GET /api/projects/{project}`
- Auth: Bearer token · Rate limit: api
- Handler: `ProjectController::show`

#### `PATCH /api/projects/{project}`
- Auth: Bearer token · Rate limit: api
- Handler: `ProjectController::update`
- Parameters (JSON/form body):
  - `title`: sometimes | string | max:255
  - `description`: nullable | string

#### `DELETE /api/projects/{project}`
- Auth: Bearer token · Rate limit: api
- Handler: `ProjectController::destroy`

#### `POST /api/projects/{project}/analyze`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `ProjectController::analyze`

#### `POST /api/projects/{project}/reprocess`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `ProjectController::reprocess`

#### `POST /api/projects/{project}/generate-clips`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `ProjectController::generateClips`
- Parameters (JSON/form body):
  - `candidate_ids`: sometimes | array
  - `candidate_ids.*`: integer
  - `mode`: sometimes | one-of(top_3/top_5/top_10/all)
  - `template_id`: nullable | exists:templates | id
  - `aspect_ratio`: sometimes | one-of(9:16/1:1/16:9)
  - `subtitle_language`: sometimes | string | max:10
  - `subtitles_enabled`: sometimes | boolean

#### `GET /api/projects/{project}/clip-candidates`
- Auth: Bearer token · Rate limit: api
- Handler: `ClipCandidateController::index`

#### `GET /api/projects/{project}/processing-jobs`
- Auth: Bearer token · Rate limit: api
- Handler: `ProcessingJobController::forProject`

#### `POST /api/projects/{project}/processing-jobs/{processingJob}/cancel`
- Auth: Bearer token · Rate limit: api
- Handler: `ProcessingJobController::cancel`

#### `POST /api/projects/{project}/videos`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `VideoController::store`
- Parameters (JSON/form body):
  - `file`: required_without:url | file | mimetypes:video/mp4 | video/quicktime | video/x-matroska | video/webm | video/x-msvideo | max:5242880
  - `url`: required_without:file | nullable | url | max:8192 | new PublicHttpUrl

### `/api/publishing-profiles`

#### `GET /api/publishing-profiles`
- Auth: Bearer token · Rate limit: api
- Handler: `PublishingProfileController::index`

#### `POST /api/publishing-profiles`
- Auth: Bearer token · Rate limit: api
- Handler: `PublishingProfileController::store`
- Parameters (JSON/form body):
  - `name`: required | string | max:255
  - `social_account_ids`: sometimes | array
  - `social_account_ids.*`: integer | exists:social_accounts | id
  - `is_default`: sometimes | boolean

#### `PUT /api/publishing-profiles/{publishing_profile}`
- Auth: Bearer token · Rate limit: api
- Handler: `PublishingProfileController::update`
- Parameters (JSON/form body):
  - `name`: sometimes | string | max:255
  - `social_account_ids`: sometimes | array
  - `social_account_ids.*`: integer | exists:social_accounts | id
  - `is_default`: sometimes | boolean

#### `PATCH /api/publishing-profiles/{publishing_profile}`
- Auth: Bearer token · Rate limit: api
- Handler: `PublishingProfileController::update`
- Parameters (JSON/form body):
  - `name`: sometimes | string | max:255
  - `social_account_ids`: sometimes | array
  - `social_account_ids.*`: integer | exists:social_accounts | id
  - `is_default`: sometimes | boolean

#### `DELETE /api/publishing-profiles/{publishing_profile}`
- Auth: Bearer token · Rate limit: api
- Handler: `PublishingProfileController::destroy`

### `/api/research`

#### `GET /api/research/dashboard`
- Auth: Bearer token · Rate limit: api
- Handler: `ResearchDashboardController::index`

#### `GET /api/research/platforms`
- Auth: Bearer token · Rate limit: api
- Handler: `ResearchSourceController::platforms`

#### `GET /api/research/channel-templates`
- Auth: Bearer token · Rate limit: api
- Handler: `ResearchSourceController::templates`

#### `GET /api/research/sources`
- Auth: Bearer token · Rate limit: api
- Handler: `ResearchSourceController::index`
- Query parameters read by the handler: `enabled`

#### `GET /api/research/sources/{researchSource}`
- Auth: Bearer token · Rate limit: api
- Handler: `ResearchSourceController::show`

#### `GET /api/research/runs`
- Auth: Bearer token · Rate limit: api
- Handler: `ResearchRunController::index`
- Query parameters read by the handler: `content_channel_id`, `status`, `per_page`

#### `GET /api/research/runs/{researchRun}`
- Auth: Bearer token · Rate limit: api
- Handler: `ResearchRunController::show`

### `/api/social-accounts`

#### `GET /api/social-accounts/{platform}/callback`
- Auth: public
- Handler: `SocialAccountController::callback`
- Query parameters read by the handler: `error`, `code`, `state`

#### `GET /api/social-accounts/platforms`
- Auth: Bearer token · Rate limit: api
- Handler: `SocialAccountController::platforms`

#### `GET /api/social-accounts`
- Auth: Bearer token · Rate limit: api
- Handler: `SocialAccountController::index`

#### `POST /api/social-accounts/connect`
- Auth: Bearer token · Rate limit: api
- Handler: `SocialAccountController::connect`
- Parameters (JSON/form body):
  - `platform`: required | one-of(platform keys from GET /social-accounts/platforms)
  - `account_name`: required_unless:platform | instagram | string | max:255
  - `username`: required_if:platform | instagram | nullable | string | max:255
  - `password`: required_if:platform | instagram | nullable | string

#### `GET /api/social-accounts/{platform}/authorize`
- Auth: Bearer token · Rate limit: api
- Handler: `SocialAccountController::authorize`

#### `PATCH /api/social-accounts/{socialAccount}`
- Auth: Bearer token · Rate limit: api
- Handler: `SocialAccountController::update`
- Parameters (JSON/form body):
  - `auto_publish_enabled`: sometimes | boolean
  - `default_cover_template_id`: sometimes | nullable | exists:cover_templates | id
  - `messenger_sync_enabled`: sometimes | boolean

#### `POST /api/social-accounts/{socialAccount}/refresh`
- Auth: Bearer token · Rate limit: api
- Handler: `SocialAccountController::refresh`

#### `POST /api/social-accounts/{socialAccount}/disconnect`
- Auth: Bearer token · Rate limit: api
- Handler: `SocialAccountController::disconnect`

#### `POST /api/social-accounts/{socialAccount}/sync-messages`
- Auth: Bearer token · Rate limit: api
- Handler: `FacebookMessageController::sync`

### `/api/social-posts`

#### `GET /api/social-posts`
- Auth: Bearer token · Rate limit: api
- Handler: `SocialPostController::index`
- Query parameters read by the handler: `clip_id`, `project_id`, `status`, `platform`, `social_account_id`, `scheduled_from`, `scheduled_to`, `sort_dir`, `sort_by`, `per_page`

#### `POST /api/social-posts`
- Auth: Bearer token · Rate limit: api
- Handler: `SocialPostController::store`
- Parameters (JSON/form body):
  - `clip_id`: required | exists:clips | id
  - `social_account_ids`: sometimes | array
  - `social_account_ids.*`: integer | exists:social_accounts | id
  - `publishing_profile_id`: sometimes | nullable | exists:publishing_profiles | id
  - `caption_overrides`: sometimes | array] | // { [social_account_id]: { title? | caption? | hashtags? } }
  - `scheduled_at`: sometimes | nullable | date

#### `POST /api/social-posts/bulk-reschedule`
- Auth: Bearer token · Rate limit: api
- Handler: `SocialPostController::bulkReschedule`
- Parameters (JSON/form body):
  - `social_post_ids`: required | array | min:1
  - `social_post_ids.*`: integer | exists:social_posts | id
  - `min_minutes`: sometimes | integer | min:1
  - `max_minutes`: sometimes | integer | min:1
  - `max_per_day`: sometimes | integer | min:1
  - `window_start_hour`: sometimes | nullable | integer | min:0 | max:23
  - `window_end_hour`: sometimes | nullable | integer | min:1 | max:24

#### `POST /api/social-posts/bulk-move-channel`
- Auth: Bearer token · Rate limit: api
- Handler: `SocialPostController::bulkMoveChannel`
- Parameters (JSON/form body):
  - `social_post_ids`: required | array | min:1
  - `social_post_ids.*`: integer | exists:social_posts | id
  - `target_social_account_id`: required | exists:social_accounts | id

#### `POST /api/social-posts/bulk-delete`
- Auth: Bearer token · Rate limit: api
- Handler: `SocialPostController::bulkDelete`
- Parameters (JSON/form body):
  - `social_post_ids`: required | array | min:1
  - `social_post_ids.*`: integer | exists:social_posts | id

#### `GET /api/social-posts/{socialPost}`
- Auth: Bearer token · Rate limit: api
- Handler: `SocialPostController::show`

#### `PATCH /api/social-posts/{socialPost}`
- Auth: Bearer token · Rate limit: api
- Handler: `SocialPostController::update`
- Parameters (JSON/form body):
  - `social_account_id`: sometimes | exists:social_accounts | id
  - `title`: sometimes | nullable | string
  - `caption`: sometimes | nullable | string
  - `hashtags`: sometimes | array
  - `scheduled_at`: sometimes | nullable | date | after:now

#### `POST /api/social-posts/{socialPost}/regenerate-schedule`
- Auth: Bearer token · Rate limit: api
- Handler: `SocialPostController::regenerateSchedule`
- Parameters (JSON/form body):
  - `social_account_id`: sometimes | exists:social_accounts | id

#### `POST /api/social-posts/{socialPost}/retry`
- Auth: Bearer token · Rate limit: api
- Handler: `SocialPostController::retry`

#### `POST /api/social-posts/{socialPost}/sync-metrics`
- Auth: Bearer token · Rate limit: api
- Handler: `SocialPostController::syncMetrics`

#### `POST /api/social-posts/{socialPost}/retry-thumbnail`
- Auth: Bearer token · Rate limit: api
- Handler: `SocialPostController::retryThumbnail`

#### `POST /api/social-posts/bulk-retry-thumbnail`
- Auth: Bearer token · Rate limit: api
- Handler: `SocialPostController::bulkRetryThumbnail`
- Parameters (JSON/form body):
  - `social_post_ids`: required | array | min:1
  - `social_post_ids.*`: integer | exists:social_posts | id

#### `POST /api/social-posts/{socialPost}/publish-now`
- Auth: Bearer token · Rate limit: api
- Handler: `SocialPostController::publishNow`

#### `DELETE /api/social-posts/{socialPost}`
- Auth: Bearer token · Rate limit: api
- Handler: `SocialPostController::destroy`

### `/api/stickers`

#### `GET /api/stickers`
- Auth: Bearer token · Rate limit: api
- Handler: `StickerController::index`

### `/api/template-categories`

#### `GET /api/template-categories`
- Auth: Bearer token · Rate limit: api
- Handler: `TemplateCategoryController::index`

### `/api/templates`

#### `GET /api/templates`
- Auth: Bearer token · Rate limit: api
- Handler: `TemplateController::index`
- Query parameters read by the handler: `category_id`, `aspect_ratio`, `include_archived`

#### `GET /api/templates/demo-source`
- Auth: Bearer token · Rate limit: api
- Handler: `TemplateController::demoSource`

#### `GET /api/templates/{template}`
- Auth: Bearer token · Rate limit: api
- Handler: `TemplateController::show`

### `/api/trending`

#### `GET /api/trending`
- Auth: Bearer token · Rate limit: api
- Handler: `TrendingController::index`
- Parameters (query):
  - `platform`: sometimes | one-of(platform keys from GET /social-accounts/platforms)

#### `GET /api/trending/platforms`
- Auth: Bearer token · Rate limit: api
- Handler: `TrendingController::platforms`

### `/api/tts`

#### `POST /api/tts`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `TtsController`
- Parameters (JSON/form body):
  - `text`: required | string | min:1 | max 4000 characters by default (server setting TTS_MAX_CHARS)
  - `voice`: sometimes | nullable | string | max:64

### `/api/video-batches`

#### `GET /api/video-batches`
- Auth: Bearer token · Rate limit: api
- Handler: `VideoBatchController::index`
- Query parameters read by the handler: `status`, `search`, `per_page`

#### `POST /api/video-batches`
- Auth: Bearer token · Rate limit: api, expensive
- Handler: `VideoBatchController::store`
- Parameters (JSON/form body):
  - `name`: nullable | string | max:255
  - `urls`: required | array | min:1 | max:50
  - `urls.*`: nullable | string | max:2048 | new PublicHttpUrl
  - `clip_mode`: sometimes | one-of(top_3/top_5/top_10/all)
  - `template_id`: nullable | exists:templates | id
  - `aspect_ratio`: sometimes | one-of(9:16/1:1/16:9)
  - `subtitle_language`: sometimes | string | max:10
  - `subtitles_enabled`: sometimes | boolean
  - `publishing_profile_id`: nullable | exists:publishing_profiles | id
  - `publish_stagger_min_minutes`: sometimes | integer | min:0 | max:1440
  - `publish_stagger_max_minutes`: sometimes | integer | min:0 | max:1440 | gte:publish_stagger_min_minutes

#### `POST /api/video-batches/bulk-cancel`
- Auth: Bearer token · Rate limit: api
- Handler: `VideoBatchController::bulkCancel`
- Parameters (JSON/form body):
  - `batch_ids`: required | array | min:1
  - `batch_ids.*`: integer | exists:video_batches | id

#### `POST /api/video-batches/bulk-delete`
- Auth: Bearer token · Rate limit: api
- Handler: `VideoBatchController::bulkDestroy`
- Parameters (JSON/form body):
  - `batch_ids`: required | array | min:1
  - `batch_ids.*`: integer | exists:video_batches | id

#### `GET /api/video-batches/{videoBatch}`
- Auth: Bearer token · Rate limit: api
- Handler: `VideoBatchController::show`

#### `POST /api/video-batches/{videoBatch}/cancel`
- Auth: Bearer token · Rate limit: api
- Handler: `VideoBatchController::cancel`

#### `POST /api/video-batches/{videoBatch}/items/{item}/retry`
- Auth: Bearer token · Rate limit: api
- Handler: `VideoBatchController::retryItem`

#### `DELETE /api/video-batches/{videoBatch}`
- Auth: Bearer token · Rate limit: api
- Handler: `VideoBatchController::destroy`

### `/api/videos`

#### `GET /api/videos/{video}`
- Auth: Bearer token · Rate limit: api
- Handler: `VideoController::show`

#### `GET /api/videos/{video}/transcript`
- Auth: Bearer token · Rate limit: api
- Handler: `VideoController::transcript`

#### `GET /api/videos/{video}/transcript/download`
- Auth: Bearer token · Rate limit: api
- Handler: `VideoController::downloadTranscript`

### `/api/admin/ai-request-logs`

#### `GET /api/admin/ai-request-logs`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `AdminController::aiRequestLogs`
- Query parameters read by the handler: `capability`, `provider`, `status`, `per_page`

### `/api/admin/backups`

#### `GET /api/admin/backups`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `BackupController::index`

#### `POST /api/admin/backups`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `BackupController::store`

#### `GET /api/admin/backups/{filename}/download`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `BackupController::download`

#### `DELETE /api/admin/backups/{filename}`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `BackupController::destroy`

### `/api/admin/cover-templates`

#### `POST /api/admin/cover-templates`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `CoverTemplateController::store`
- Parameters (JSON/form body):
  - `name`: required | string | max:255
  - `description`: nullable | string
  - `aspect_ratio`: required | one-of(9:16/1:1/16:9)
  - `config`: nullable | array

#### `PATCH /api/admin/cover-templates/{coverTemplate}`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `CoverTemplateController::update`
- Parameters (JSON/form body):
  - `name`: sometimes | string | max:255
  - `description`: nullable | string
  - `aspect_ratio`: sometimes | one-of(9:16/1:1/16:9)
  - `status`: sometimes | one-of(draft/published/archived)
  - `config`: sometimes | array

#### `POST /api/admin/cover-templates/{coverTemplate}/duplicate`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `CoverTemplateController::duplicate`

#### `POST /api/admin/cover-templates/{coverTemplate}/generate-thumbnail`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `CoverTemplateController::generateThumbnail`

#### `POST /api/admin/cover-templates/{coverTemplate}/archive`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `CoverTemplateController::archive`

#### `DELETE /api/admin/cover-templates/{coverTemplate}`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `CoverTemplateController::destroy`

### `/api/admin/processing-jobs`

#### `GET /api/admin/processing-jobs`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `AdminController::processingJobs`
- Query parameters read by the handler: `status`, `per_page`

### `/api/admin/projects`

#### `GET /api/admin/projects`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `AdminController::projects`
- Query parameters read by the handler: `status`, `per_page`

### `/api/admin/research`

#### `PATCH /api/admin/research/sources/{researchSource}`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `ResearchSourceController::update`
- Parameters (JSON/form body):
  - `name`: sometimes | string | max:255
  - `description`: sometimes | nullable | string | max:2000
  - `enabled`: sometimes | boolean
  - `configuration`: sometimes | nullable | array

#### `POST /api/admin/research/sources/{researchSource}/test`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `ResearchSourceController::test`

### `/api/admin/settings`

#### `GET /api/admin/settings`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `AdminController::settings`

#### `PATCH /api/admin/settings`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `AdminController::updateSettings`
- Parameters (JSON/form body):
  - `transcript_model`: sometimes | string | in:mock | openai | whisper_engine | nine_router
  - `clip_scoring_model`: sometimes | string | in:mock | openai | ollama | claude | gemini | nine_router
  - `reaction_script_model`: sometimes | string | in:mock | openai | ollama | gemini | nine_router
  - `tts_model`: sometimes | string | in:mock | openai | nine_router
  - `content_idea_model`: sometimes | string | in:mock | openai | gemini | nine_router
  - `default_clip_duration`: sometimes | integer | min:5 | max:180
  - `default_template_id`: sometimes | nullable | exists:templates | id
  - `max_clips_per_video`: sometimes | integer | min:1 | max:50
  - `use_intro`: sometimes | boolean
  - `use_outro`: sometimes | boolean

### `/api/admin/stats`

#### `GET /api/admin/stats`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `AdminController::stats`

### `/api/admin/template-categories`

#### `POST /api/admin/template-categories`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `TemplateCategoryController::store`
- Parameters (JSON/form body):
  - `name`: required | string | max:255
  - `description`: nullable | string

#### `PATCH /api/admin/template-categories/{templateCategory}`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `TemplateCategoryController::update`
- Parameters (JSON/form body):
  - `name`: sometimes | string | max:255
  - `description`: nullable | string
  - `sort_order`: sometimes | integer

#### `DELETE /api/admin/template-categories/{templateCategory}`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `TemplateCategoryController::destroy`

### `/api/admin/templates`

#### `POST /api/admin/templates`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `TemplateController::store`
- Parameters (JSON/form body):
  - `name`: required | string | max:255
  - `description`: nullable | string
  - `template_category_id`: nullable | exists:template_categories | id
  - `aspect_ratio`: required | one-of(9:16/1:1/16:9)
  - `resolution_width`: nullable | integer
  - `resolution_height`: nullable | integer
  - `config`: nullable | array

#### `PATCH /api/admin/templates/{template}`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `TemplateController::update`
- Parameters (JSON/form body):
  - `name`: sometimes | string | max:255
  - `description`: nullable | string
  - `template_category_id`: sometimes | nullable | exists:template_categories | id
  - `status`: sometimes | one-of(draft/published/archived)
  - `aspect_ratio`: sometimes | one-of(9:16/1:1/16:9)
  - `resolution_width`: sometimes | integer | min:100 | max:4000
  - `resolution_height`: sometimes | integer | min:100 | max:4000
  - `config`: sometimes | array
  - `label`: sometimes | nullable | string | max:50

#### `POST /api/admin/templates/{template}/duplicate`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `TemplateController::duplicate`

#### `POST /api/admin/templates/{template}/generate-thumbnail`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `TemplateController::generateThumbnail`

#### `POST /api/admin/templates/{template}/generate-preview`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `TemplateController::generatePreview`

#### `POST /api/admin/templates/{template}/archive`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `TemplateController::archive`

#### `DELETE /api/admin/templates/{template}`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `TemplateController::destroy`

### `/api/admin/users`

#### `GET /api/admin/users`
- Auth: Bearer token · Rate limit: api · **admin role only**
- Handler: `AdminController::users`
- Query parameters read by the handler: `q`, `per_page`
