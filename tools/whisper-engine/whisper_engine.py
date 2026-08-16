import os
import logging
from faster_whisper import WhisperModel

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)


class WhisperEngine:
    def __init__(self, model_size="base", device="cpu", compute_type="int8"):
        logger.info(f"Loading Whisper model: {model_size} on {device} ({compute_type})")
        try:
            self.model = WhisperModel(model_size, device=device, compute_type=compute_type)
        except Exception as e:
            logger.error(f"Failed to load Whisper model: {e}")
            if device == "cuda":
                logger.warning("Falling back to CPU")
                self.model = WhisperModel(model_size, device="cpu", compute_type="int8")
            else:
                raise e

    def transcribe(self, audio_path, language=None):
        logger.info(f"Transcribing: {audio_path}")
        if not os.path.exists(audio_path):
            raise FileNotFoundError(f"Audio file not found: {audio_path}")

        segments_iter, info = self.model.transcribe(
            audio_path,
            beam_size=5,
            language=language,
            word_timestamps=True,
            # Skip silent/non-speech stretches entirely instead of running the model
            # over them — less audio actually decoded (lighter on a CPU-only, no-GPU
            # machine) and it also sidesteps Whisper's well-known tendency to
            # hallucinate text during silence, so this is a pure win, not a
            # speed/quality tradeoff.
            vad_filter=True,
            vad_parameters={"min_silence_duration_ms": 500},
            # Each audio chunk here is already an independent slice from
            # WhisperEngineTranscriptionProvider's own chunking, not a real
            # continuation of prior speech — feeding it the previous window's text as
            # context doesn't add real information and occasionally sends the model
            # into a repetition loop (a real CPU-burning failure mode) on noisy or
            # silent stretches. Off is both lighter and safer for this chunked setup.
            condition_on_previous_text=False,
        )

        segments = []
        words = []
        for segment in segments_iter:
            text = segment.text.strip()
            segments.append({
                "start": segment.start,
                "end": segment.end,
                "text": text,
            })
            for word in (segment.words or []):
                words.append({
                    "word": word.word.strip(),
                    "start": word.start,
                    "end": word.end,
                })

        logger.info(f"Transcription complete. Found {len(segments)} segments, {len(words)} words.")
        return {
            "language": info.language,
            "language_probability": info.language_probability,
            "duration": info.duration,
            "segments": segments,
            "words": words,
        }
