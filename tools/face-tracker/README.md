# Face Tracker

A small self-hosted smart-crop service that replaces `MockReframingProvider`'s
blind left/right-every-10-seconds toggle with an actual face-tracking crop. It
wraps OpenCV's bundled Haar cascade face detector behind a FastAPI HTTP endpoint
that Laravel calls through `FaceTrackerReframingProvider`
(`backend/app/Services/AI/FaceTracker`).

## How it works

For a clip's `[start, end]` range, it samples one frame per second, detects the
largest face in each sampled frame, and smooths the resulting center position
with an exponential moving average so the crop pans instead of jittering between
detections. Frames with no detected face keep the last known position rather
than snapping the crop back to center. The result is a list of crop keyframes
(`{time, x, y, width, height}`) that `FFmpegService::buildCropExpression()`
already knows how to consume — no changes needed on that side.

**Known limitation:** Haar cascades are frontal-face detectors — they miss
side profiles and heavy occlusion. That's an acceptable tradeoff for typical
talking-head footage (the common case for short-form clips) and needs zero
extra model downloads, unlike DNN-based detectors. If tracking quality isn't
good enough for your content, swapping `FaceTracker._detect_primary_face_center`
for a stronger detector (e.g. a MediaPipe or DNN-based one) is a contained change
— the keyframe/smoothing/clamping logic around it doesn't need to change.

## Requirements

- Python 3.10+ (tested on 3.14)
- No FFmpeg needed here — Laravel does the actual cropping/rendering itself

## Setup

```bash
cd tools/face-tracker
python -m venv venv
./venv/Scripts/pip install -r requirements.txt   # Windows
# venv/bin/pip install -r requirements.txt       # Linux/Mac
```

## Running

```bash
./venv/Scripts/python main.py     # Windows
# venv/bin/python main.py         # Linux/Mac
```

Starts on `http://127.0.0.1:8200`. The Haar cascade loads lazily on the first
`/detect-crop` call.

Configure via environment variables before starting:

| Var | Default | Notes |
|---|---|---|
| `FACE_TRACKER_HOST` | `127.0.0.1` | |
| `FACE_TRACKER_PORT` | `8200` | Must match `FACE_TRACKER_URL` in `backend/.env`. |

## Enabling it in Laravel

Set in `backend/.env`:

```
AI_REFRAMING_PROVIDER=face_tracker
FACE_TRACKER_URL=http://127.0.0.1:8200
FACE_TRACKER_TIMEOUT=300
```

`RenderClipJob` picks this up automatically via `ReframingProvider` — no other
code changes needed. Restart the queue worker after changing `.env` (it caches
config at boot, same gotcha as the transcription/analysis providers).

## API

- `GET /health` — `{"status": "ok", "tracker_loaded": bool}`
- `POST /detect-crop` — body:
  ```json
  {
    "video_path": "<absolute local path>",
    "clip_start": 996.0,
    "clip_end": 1085.0,
    "source_width": 1920,
    "source_height": 1080,
    "target_aspect_ratio": "9:16"
  }
  ```
  Returns `{"keyframes": [{"time", "x", "y", "width", "height", "active_speaker"}]}`,
  with `time` relative to `clip_start` and `x`/`y`/`width`/`height` in source-video
  pixel coordinates.
