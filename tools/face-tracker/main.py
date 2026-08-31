import os
import logging

from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from starlette.concurrency import run_in_threadpool
from typing import Optional, List

from face_tracker import FaceTracker

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("FaceTrackerService")

app = FastAPI(title="Clipper Face Tracker")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Loaded lazily so `python main.py` starts instantly; the cascade file is tiny
# (bundled with opencv-python) so this costs nothing on the first real request.
tracker: Optional[FaceTracker] = None


def get_tracker() -> FaceTracker:
    global tracker
    if tracker is None:
        tracker = FaceTracker()
    return tracker


class DetectCropRequest(BaseModel):
    video_path: str
    clip_start: float
    clip_end: float
    source_width: int
    source_height: int
    target_aspect_ratio: str
    # Absolute path to ffmpeg, for the active-speaker path's own audio extraction —
    # Laravel already resolves this (see backend FFMPEG_BIN) since a fresh shell on
    # this machine may not have it on PATH yet either. Falls back to "ffmpeg" (PATH
    # lookup) if the caller doesn't send one.
    ffmpeg_bin: Optional[str] = "ffmpeg"


class Keyframe(BaseModel):
    time: float
    x: float
    y: float
    width: float
    height: float
    active_speaker: Optional[str] = None


class DetectCropResponse(BaseModel):
    keyframes: List[Keyframe]


@app.get("/health")
async def health_check():
    return {"status": "ok", "service": "Clipper Face Tracker", "tracker_loaded": tracker is not None}


@app.post("/detect-crop", response_model=DetectCropResponse)
async def detect_crop(request: DetectCropRequest):
    """
    Track the primary face across [clip_start, clip_end] in a local video file and
    return crop keyframes (in source-pixel coordinates) that follow it. The caller
    (Laravel) is expected to pass an absolute path on this machine — this service
    does not accept uploads or fetch remote files.
    """
    if not os.path.exists(request.video_path):
        raise HTTPException(status_code=400, detail=f"Video file not found: {request.video_path}")

    try:
        keyframes = await run_in_threadpool(
            get_tracker().detect_crop_keyframes,
            request.video_path,
            request.clip_start,
            request.clip_end,
            request.source_width,
            request.source_height,
            request.target_aspect_ratio,
            ffmpeg_bin=request.ffmpeg_bin or "ffmpeg",
        )
    except Exception as e:
        logger.error(f"Face-tracking crop detection failed: {e}")
        raise HTTPException(status_code=500, detail=str(e))

    return {"keyframes": keyframes}


if __name__ == "__main__":
    import uvicorn
    host = os.getenv("FACE_TRACKER_HOST", "127.0.0.1")
    port = int(os.getenv("FACE_TRACKER_PORT", "8200"))
    uvicorn.run(app, host=host, port=port)
