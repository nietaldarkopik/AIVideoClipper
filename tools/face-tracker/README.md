# Face Tracker

A small self-hosted smart-crop service that replaces `MockReframingProvider`'s
blind left/right-every-10-seconds toggle with an actual face-tracking crop. It
wraps OpenCV's YuNet DNN face detector (and, for active-speaker detection,
MediaPipe's FaceLandmarker) behind a FastAPI HTTP endpoint that Laravel calls
through `FaceTrackerReframingProvider` (`backend/app/Services/AI/FaceTracker`).

## How it works

For a clip's `[start, end]` range, it samples one frame per second, detects
face(s) in each sampled frame, and smooths the resulting center position with
an exponential moving average so the crop pans instead of jittering between
detections. Frames with no detected face keep the last known position rather
than snapping the crop back to center. The result is a list of crop keyframes
(`{time, x, y, width, height, active_speaker}`) that
`FFmpegService::buildCropSegments()` already knows how to consume — no changes
needed on that side.

**Active-speaker prioritization**: when more than one face is present, the
service tries to follow whoever is actually speaking rather than an arbitrary
face. It samples more densely (every 0.25s) than the ~1s keyframe grain,
tracks each detected face across those samples (simple nearest-centroid
matching — adequate since faces don't teleport at 4fps), and for each ~1s
decision window measures every tracked face's mouth-movement variance via
MediaPipe FaceLandmarker's lip landmarks. Whichever face's mouth is moving the
most wins that window, *unless* the window's audio energy (extracted via
ffmpeg, normalized against the clip's own peak) is below a silence threshold —
in that case no switch happens, so a naturally expressive face isn't mistaken
for "speaking" during an actual pause. This is a best-effort heuristic (mouth
movement, not real audio-visual diarization), not perfect — laughing or
chewing can look like speaking — and needs both extra dependencies below to be
installed. If they aren't (or anything in that path throws), it falls back to
the plain largest-face behavior above for the whole clip; this can only ever
add positioning accuracy, never regress reliability below that baseline.

**Known limitation:** YuNet is a fairly small/fast detector and can still miss
heavy occlusion or extreme angles. That's an acceptable tradeoff for typical
talking-head footage (the common case for short-form clips). If tracking
quality isn't good enough for your content, swapping the detector in
`FaceTracker._sample_face_centers`/`_sample_tracked_faces` for a stronger one
is a contained change — the keyframe/smoothing/clamping logic around it
doesn't need to change.

## Requirements

- Python 3.10+ (tested on 3.12)
- FFmpeg on PATH, or pass an absolute path via the request's `ffmpeg_bin`
  field (Laravel already does this, forwarding its own `FFMPEG_BIN`) — only
  needed for the active-speaker path's own audio extraction; the plain
  largest-face path needs no FFmpeg at all, Laravel does the actual
  cropping/rendering itself.

## Setup

```bash
cd tools/face-tracker
python -m venv venv
./venv/Scripts/pip install -r requirements.txt   # Windows
# venv/bin/pip install -r requirements.txt       # Linux/Mac
```

`mediapipe` depends on `opencv-contrib-python`, which conflicts with
`opencv-python-headless` if both get installed (both provide the `cv2` module
at the same path) — `requirements.txt` only lists `mediapipe`, not
`opencv-python-headless` separately, for exactly this reason. Don't add it
back.

**Model files** (both required — `models/` isn't tracked in git):

| File | Used for | Download |
|---|---|---|
| `models/face_detection_yunet_2023mar.onnx` | Face detection | `https://github.com/opencv/opencv_zoo/raw/main/models/face_detection_yunet/face_detection_yunet_2023mar.onnx` |
| `models/face_landmarker.task` | Active-speaker mouth-movement analysis | `https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/latest/face_landmarker.task` |

The service still starts and serves plain largest-face tracking without the
second file — it logs a warning and disables active-speaker detection instead
of failing.

## Running

```bash
./venv/Scripts/python main.py     # Windows
# venv/bin/python main.py         # Linux/Mac
```

Starts on `http://127.0.0.1:8200`. Both models load lazily on the first
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
    "target_aspect_ratio": "9:16",
    "ffmpeg_bin": "ffmpeg"
  }
  ```
  `ffmpeg_bin` is optional (defaults to `"ffmpeg"`, resolved via PATH) and only
  used by the active-speaker path's own audio extraction.

  Returns `{"keyframes": [{"time", "x", "y", "width", "height", "active_speaker"}]}`,
  with `time` relative to `clip_start`, `x`/`y`/`width`/`height` in
  source-video pixel coordinates, and `active_speaker` a string track label
  (e.g. `"1"`, `"2"`) when a confident pick was made for that keyframe, or
  `null` when there was only one face, no clear winner, or active-speaker
  detection is unavailable/disabled.
