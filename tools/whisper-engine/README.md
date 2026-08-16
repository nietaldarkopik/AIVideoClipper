# Whisper Engine

A small self-hosted transcription service, adapted from the standalone `caption-service`
prototype in the Electron app. It wraps [faster-whisper](https://github.com/SYSTRAN/faster-whisper)
(CTranslate2 Whisper) behind a FastAPI HTTP endpoint that Laravel calls through
`WhisperEngineTranscriptionProvider` (`backend/app/Services/AI/WhisperEngine`).

Unlike the OpenAI provider, this runs entirely on your machine — no API key, no
per-minute cost, no 25MB upload cap — and returns real word-level timestamps
straight from the model instead of interpolating even spacing across a segment.

Subtitle file generation (SRT/ASS), word-by-word caption highlight styling, and
FFmpeg burn-in are **not** part of this service — Laravel already does all of that
in `SubtitleService` + `FFmpegService` once it has a `TranscriptionResult`. This
service's only job is: WAV path in, segments + words out.

## Requirements

- Python 3.10+ (tested on 3.14)
- No FFmpeg needed here — Laravel already extracts a 16kHz mono WAV before calling this service

## Setup

```bash
cd tools/whisper-engine
python -m venv venv
./venv/Scripts/pip install -r requirements.txt   # Windows
# venv/bin/pip install -r requirements.txt       # Linux/Mac
```

## Running

```bash
./venv/Scripts/python main.py     # Windows
# venv/bin/python main.py         # Linux/Mac
```

Starts on `http://127.0.0.1:8100`. The model loads lazily on the first `/transcribe`
call (so startup is instant) and then stays resident in memory for every request
after that.

Configure via environment variables before starting:

| Var | Default | Notes |
|---|---|---|
| `WHISPER_MODEL_SIZE` | `base` | `tiny`/`base`/`small`/`medium`/`large-v3`. Bigger = more accurate, slower, more RAM. |
| `WHISPER_DEVICE` | `cpu` | Set to `cuda` if you have an NVIDIA GPU + CUDA installed. |
| `WHISPER_COMPUTE_TYPE` | `int8` | `int8` for CPU, `float16` typically for GPU. |
| `WHISPER_ENGINE_HOST` | `127.0.0.1` | |
| `WHISPER_ENGINE_PORT` | `8100` | Must match `WHISPER_ENGINE_URL` in `backend/.env`. |

Models download from Hugging Face on first use and are cached under
`~/.cache/huggingface`.

### CPU-only / no-GPU machines

`whisper_engine.py` always runs with `vad_filter=True` (skips silent/non-speech
stretches instead of decoding them — less compute, and it also avoids Whisper's
tendency to hallucinate text over silence) and `condition_on_previous_text=False`
(each chunk from `WhisperEngineTranscriptionProvider` is already an independent
slice, not a real continuation, so carrying prior text as context adds nothing and
occasionally sends the model into a CPU-burning repetition loop on noisy audio).
Neither of these trades away transcription quality — they're both free wins,
which is why they're not exposed as env vars. `beam_size=5` (the actual
speed/accuracy knob) is left alone by default; dropping it to `1` (greedy decoding)
would lighten load further but does measurably affect accuracy, so that's a
deliberate choice to leave for you to make in `whisper_engine.py` if you want it.

### Long videos and resource use

This service itself always transcribes whatever WAV path it's given in one shot —
it doesn't chunk anything. The caller does that: `WhisperEngineTranscriptionProvider`
(Laravel side) splits the source audio into fixed-length chunks (`WHISPER_ENGINE_CHUNK_SECONDS`
in `backend/.env`, default 120s) with ffmpeg and sends them one at a time, stitching
the results back into one timeline by offsetting each chunk's timestamps.

This exists because transcribing a long recording in a single call keeps the whole
thing — plus beam search and word-timestamp state — resident in CPU/RAM for the
entire run. On modest hardware that's been enough to freeze or reboot the machine.
If you still see that happen, lower `WHISPER_ENGINE_CHUNK_SECONDS` further (e.g. `60`).
Other things worth checking on a machine that reboots under load: CPU thermals
(sustained 100% CPU for a `base`/`small` model on a poorly-cooled laptop can trigger
a thermal shutdown), and free RAM if other clipper-tools processes (Next.js, queue
worker, MySQL/Redis) are running at the same time.

## API

- `GET /health` — `{"status": "ok", "model_loaded": bool}`
- `POST /transcribe` — body `{"audio_path": "<absolute local path>", "language": "en"}` (`language` optional; omit to auto-detect).
  Returns `{"language", "language_probability", "duration", "segments": [{start,end,text}], "words": [{word,start,end}]}`.

The service only accepts a local file path already on disk — it doesn't accept
uploads or fetch remote URLs, since Laravel always has the audio extracted locally
before it calls this.

## Wiring it into clipper-tools

In `backend/.env`, set:

```
AI_TRANSCRIPTION_PROVIDER=whisper_engine
WHISPER_ENGINE_URL=http://127.0.0.1:8100
```

Then keep this service running alongside the other four processes (redis, `php
artisan serve`, queue worker, Next.js) whenever `AI_TRANSCRIPTION_PROVIDER=whisper_engine`
is set — the queue worker calls it synchronously during `AnalyzeVideoJob`.
