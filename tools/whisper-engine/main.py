import os
import logging

from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from starlette.concurrency import run_in_threadpool
from typing import Optional, List, Dict, Any

from whisper_engine import WhisperEngine

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("WhisperEngineService")

app = FastAPI(title="Clipper Whisper Engine")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

MODEL_SIZE = os.getenv("WHISPER_MODEL_SIZE", "base")
DEVICE = os.getenv("WHISPER_DEVICE", "cpu")
COMPUTE_TYPE = os.getenv("WHISPER_COMPUTE_TYPE", "int8")

# Loaded lazily on first request so `uvicorn main:app --reload` starts instantly;
# the first transcription request pays the model-load cost instead.
engine: Optional[WhisperEngine] = None


def get_engine() -> WhisperEngine:
    global engine
    if engine is None:
        engine = WhisperEngine(model_size=MODEL_SIZE, device=DEVICE, compute_type=COMPUTE_TYPE)
    return engine


class TranscribeRequest(BaseModel):
    audio_path: str
    language: Optional[str] = None


class Segment(BaseModel):
    start: float
    end: float
    text: str


class Word(BaseModel):
    word: str
    start: float
    end: float


class TranscribeResponse(BaseModel):
    language: str
    language_probability: float
    duration: float
    segments: List[Segment]
    words: List[Word]


@app.get("/health")
async def health_check():
    return {"status": "ok", "service": "Clipper Whisper Engine", "model_loaded": engine is not None}


@app.post("/transcribe", response_model=TranscribeResponse)
async def transcribe(request: TranscribeRequest):
    """
    Transcribe a local audio file. The caller (Laravel) is expected to have
    already extracted a mono WAV and pass its absolute path on this machine —
    this service does not accept uploads or fetch remote files.
    """
    if not os.path.exists(request.audio_path):
        raise HTTPException(status_code=400, detail=f"Audio file not found: {request.audio_path}")

    try:
        result = await run_in_threadpool(
            get_engine().transcribe, request.audio_path, request.language
        )
    except Exception as e:
        logger.error(f"Transcription failed: {e}")
        raise HTTPException(status_code=500, detail=str(e))

    return result


if __name__ == "__main__":
    import uvicorn
    host = os.getenv("WHISPER_ENGINE_HOST", "127.0.0.1")
    port = int(os.getenv("WHISPER_ENGINE_PORT", "8100"))
    uvicorn.run(app, host=host, port=port)
