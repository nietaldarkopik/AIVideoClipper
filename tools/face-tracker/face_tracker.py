import logging
import os
import subprocess
import tempfile
import wave

import cv2
import numpy as np

logger = logging.getLogger(__name__)

_MODEL_PATH = os.path.join(os.path.dirname(__file__), "models", "face_detection_yunet_2023mar.onnx")
_LANDMARKER_MODEL_PATH = os.path.join(os.path.dirname(__file__), "models", "face_landmarker.task")

# MediaPipe's Tasks API (mediapipe>=1.0) is optional at import time: active-speaker
# detection degrades gracefully to plain largest-face tracking (today's behavior)
# when it — or its model file — isn't available, rather than failing the render.
try:
    import mediapipe as mp
    _MEDIAPIPE_AVAILABLE = True
except ImportError:
    mp = None
    _MEDIAPIPE_AVAILABLE = False


class FaceTracker:
    """
    Detects face position(s) across a clip's time range and produces crop
    keyframes that follow them, instead of a fixed or blindly-alternating box.

    Uses OpenCV's YuNet DNN face detector (models/face_detection_yunet_2023mar.onnx,
    from the official opencv_zoo) rather than a Haar cascade — opencv-python-headless
    5.x no longer bundles Haar cascade XML data or cv2.CascadeClassifier at all, and
    YuNet is more accurate anyway (handles off-angle faces better). On a missed frame
    we keep the last known face position rather than snapping the crop back to
    center, since a stale-but-correct position looks better than a visible jump.

    When multiple faces are present, detect_crop_keyframes() tries to follow
    whoever is actually speaking (see _detect_with_active_speaker) rather than an
    arbitrary face: for each ~1s window it tracks each face's mouth-movement
    (MediaPipe FaceLandmarker's lip landmarks, correlated against that window's
    audio energy so a naturally expressive face isn't mistaken for "speaking"
    during silence) and follows whichever face's mouth is moving the most. This is
    a best-effort heuristic, not real audio-visual diarization — it can be wrong
    on laughing/chewing, and needs the landmark model + a readable audio track.
    Any failure anywhere in that path (missing model, ffmpeg unavailable, one bad
    frame) falls back to _detect_simple()'s exact pre-existing single-largest-face
    behavior for the whole clip, so this enhancement can only ever add positioning
    accuracy, never regress reliability below what shipped before it.
    """

    # Caps how many simultaneous faces get tracked/landmarked per frame — bounds
    # per-frame cost regardless of how crowded a scene is.
    _MAX_TRACKED_FACES = 3

    # Sampling interval for the active-speaker analysis pass specifically (denser
    # than the ~1s keyframe granularity): mouth-movement variance needs several
    # samples within each decision window to be meaningful.
    _DENSE_SAMPLE_INTERVAL = 0.25

    # A face detection in one dense sample carries over its track ID from the
    # previous sample if its center moved less than this fraction of the frame
    # width — keeps a talking head's identity stable across samples without full
    # multi-object tracking (adequate since faces don't teleport at 4fps).
    _MATCH_DISTANCE_FRACTION = 0.2

    # Below this fraction of the clip's own peak audio RMS, a decision window is
    # treated as silence — no active-speaker switch happens on mouth movement
    # alone (avoids picking a face that's just smiling/reacting silently).
    _SILENCE_ENERGY_FRACTION = 0.12

    def __init__(self):
        if not os.path.exists(_MODEL_PATH):
            raise RuntimeError(
                f"Face detection model not found at {_MODEL_PATH}. "
                "Download it from https://github.com/opencv/opencv_zoo/raw/main/models/"
                "face_detection_yunet/face_detection_yunet_2023mar.onnx into tools/face-tracker/models/."
            )
        self._model_path = _MODEL_PATH
        self._landmarker = self._create_landmarker()

    def _create_landmarker(self):
        if not _MEDIAPIPE_AVAILABLE or not os.path.exists(_LANDMARKER_MODEL_PATH):
            logger.warning(
                "MediaPipe FaceLandmarker unavailable (model missing or mediapipe not installed) — "
                "active-speaker detection disabled, falling back to largest-face tracking."
            )
            return None
        try:
            base_options = mp.tasks.BaseOptions(model_asset_path=_LANDMARKER_MODEL_PATH)
            options = mp.tasks.vision.FaceLandmarkerOptions(
                base_options=base_options,
                running_mode=mp.tasks.vision.RunningMode.IMAGE,
                num_faces=1,
                min_face_detection_confidence=0.5,
            )
            return mp.tasks.vision.FaceLandmarker.create_from_options(options)
        except Exception:
            logger.exception("Failed to load MediaPipe FaceLandmarker — active-speaker detection disabled.")
            return None

    def detect_crop_keyframes(
        self,
        video_path,
        clip_start,
        clip_end,
        source_width,
        source_height,
        target_aspect_ratio,
        sample_interval=1.0,
        ffmpeg_bin="ffmpeg",
    ):
        crop_w, crop_h = self._crop_size(source_width, source_height, target_aspect_ratio)

        detector = cv2.FaceDetectorYN.create(
            self._model_path, "", (source_width, source_height),
            score_threshold=0.6, nms_threshold=0.3, top_k=5000,
        )

        if self._landmarker is not None:
            try:
                return self._detect_with_active_speaker(
                    video_path, clip_start, clip_end, source_width, source_height,
                    crop_w, crop_h, detector, sample_interval, ffmpeg_bin,
                )
            except Exception:
                logger.exception("Active-speaker detection failed; falling back to largest-face tracking.")

        return self._detect_simple(
            video_path, clip_start, clip_end, source_width, source_height, crop_w, crop_h, detector, sample_interval
        )

    # ------------------------------------------------------------------
    # Fallback path: today's original algorithm, unchanged.
    # ------------------------------------------------------------------

    def _detect_simple(self, video_path, clip_start, clip_end, source_width, source_height, crop_w, crop_h, detector, sample_interval):
        cap = cv2.VideoCapture(video_path)
        if not cap.isOpened():
            raise RuntimeError(f"Could not open video: {video_path}")

        try:
            raw_centers = self._sample_face_centers(
                cap, detector, clip_start, clip_end, source_width, source_height, sample_interval
            )
        finally:
            cap.release()

        smoothed = self._smooth(raw_centers)

        return self._to_keyframes(smoothed, crop_w, crop_h, source_width, source_height)

    def _sample_face_centers(self, cap, detector, clip_start, clip_end, source_width, source_height, sample_interval):
        # One seek to clip_start, then read forward sequentially — repeatedly
        # seeking to an arbitrary timestamp is expensive on H264 (has to locate
        # the nearest keyframe and decode forward from there every time). Frames
        # we don't need to inspect are grab()'d (decode only, no color-convert/copy)
        # instead of read(), which is the standard cheap way to skip frames.
        fps = cap.get(cv2.CAP_PROP_FPS) or 30.0
        frame_interval = max(1, round(fps * sample_interval))

        cap.set(cv2.CAP_PROP_POS_MSEC, clip_start * 1000)

        last_center = (source_width / 2.0, source_height / 2.0)
        points = []
        frame_count = 0

        while True:
            current_time = clip_start + frame_count / fps
            if current_time > clip_end:
                break

            if frame_count % frame_interval == 0:
                ok, frame = cap.read()
                if not ok:
                    break
                center = self._detect_primary_face_center(detector, frame)
                if center is not None:
                    last_center = center
                points.append((round(current_time - clip_start, 2), last_center))
            elif not cap.grab():
                break

            frame_count += 1

        if not points:
            points.append((0.0, last_center))

        return points

    def _detect_primary_face_center(self, detector, frame):
        h, w = frame.shape[:2]
        detector.setInputSize((w, h))
        _, faces = detector.detect(frame)
        if faces is None or len(faces) == 0:
            return None

        # Largest detected face = assumed primary subject (closest to camera).
        best = max(faces, key=lambda f: f[2] * f[3])
        x, y, fw, fh = float(best[0]), float(best[1]), float(best[2]), float(best[3])
        return (x + fw / 2.0, y + fh / 2.0)

    # ------------------------------------------------------------------
    # Active-speaker path.
    # ------------------------------------------------------------------

    def _detect_with_active_speaker(
        self, video_path, clip_start, clip_end, source_width, source_height, crop_w, crop_h, detector, sample_interval, ffmpeg_bin
    ):
        cap = cv2.VideoCapture(video_path)
        if not cap.isOpened():
            raise RuntimeError(f"Could not open video: {video_path}")

        try:
            dense_samples = self._sample_tracked_faces(cap, detector, clip_start, clip_end, source_width, source_height)
        finally:
            cap.release()

        if not dense_samples:
            dense_samples = [(0.0, [])]

        num_buckets = int(dense_samples[-1][0] // sample_interval) + 1
        audio_energy = self._audio_energy_windows(video_path, clip_start, clip_end, sample_interval, num_buckets, ffmpeg_bin)
        decisions = self._resolve_active_speaker(dense_samples, audio_energy, sample_interval, num_buckets)
        points = self._points_from_windows(dense_samples, decisions, num_buckets, sample_interval, source_width, source_height)

        smoothed = self._smooth_with_speaker(points)

        return self._to_keyframes(smoothed, crop_w, crop_h, source_width, source_height)

    def _sample_tracked_faces(self, cap, detector, clip_start, clip_end, source_width, source_height):
        """
        @return list of (time, [{"id", "cx", "cy", "w", "h", "mar"}, ...]) — one
        entry per dense sample, tracks in detection order (largest first).
        """
        fps = cap.get(cv2.CAP_PROP_FPS) or 30.0
        frame_interval = max(1, round(fps * self._DENSE_SAMPLE_INTERVAL))

        cap.set(cv2.CAP_PROP_POS_MSEC, clip_start * 1000)

        samples = []
        prev_tracks = []
        next_track_id = 1
        frame_count = 0

        while True:
            current_time = clip_start + frame_count / fps
            if current_time > clip_end:
                break

            if frame_count % frame_interval == 0:
                ok, frame = cap.read()
                if not ok:
                    break

                h, w = frame.shape[:2]
                detector.setInputSize((w, h))
                _, faces = detector.detect(frame)
                faces = [] if faces is None else sorted(faces, key=lambda f: f[2] * f[3], reverse=True)[: self._MAX_TRACKED_FACES]

                match_dist = self._MATCH_DISTANCE_FRACTION * w
                current_tracks = []
                used_prev_ids = set()

                for f in faces:
                    fx, fy, fw, fh = float(f[0]), float(f[1]), float(f[2]), float(f[3])
                    cx, cy = fx + fw / 2.0, fy + fh / 2.0

                    best_prev, best_dist = None, match_dist
                    for p in prev_tracks:
                        if p["id"] in used_prev_ids:
                            continue
                        d = ((p["cx"] - cx) ** 2 + (p["cy"] - cy) ** 2) ** 0.5
                        if d < best_dist:
                            best_prev, best_dist = p, d

                    if best_prev is not None:
                        track_id = best_prev["id"]
                        used_prev_ids.add(track_id)
                    else:
                        track_id = next_track_id
                        next_track_id += 1

                    mar = self._mouth_aspect_ratio(frame, fx, fy, fw, fh)
                    current_tracks.append({"id": track_id, "cx": cx, "cy": cy, "w": fw, "h": fh, "mar": mar})

                samples.append((round(current_time - clip_start, 2), current_tracks))
                prev_tracks = current_tracks
            elif not cap.grab():
                break

            frame_count += 1

        return samples

    def _mouth_aspect_ratio(self, frame, fx, fy, fw, fh):
        """
        Runs FaceLandmarker on a margin-padded crop around one detected face and
        returns mouth height / mouth width from its lip landmarks (13/14 inner
        upper/lower lip, 61/291 mouth corners — standard MAR points, verified
        present in mp.tasks.vision.FaceLandmarksConnections.FACE_LANDMARKS_LIPS).
        Returns None on any failure (out-of-frame crop, no landmarks found, model
        error) — a missing MAR reading just excludes that sample from the
        mouth-movement comparison, it doesn't fail the whole clip.
        """
        h, w = frame.shape[:2]
        margin = 0.3
        x0 = max(0, int(fx - fw * margin))
        y0 = max(0, int(fy - fh * margin))
        x1 = min(w, int(fx + fw * (1 + margin)))
        y1 = min(h, int(fy + fh * (1 + margin)))
        if x1 <= x0 or y1 <= y0:
            return None

        try:
            crop = frame[y0:y1, x0:x1]
            crop_rgb = cv2.cvtColor(crop, cv2.COLOR_BGR2RGB)
            mp_image = mp.Image(image_format=mp.ImageFormat.SRGB, data=np.ascontiguousarray(crop_rgb))
            result = self._landmarker.detect(mp_image)
            if not result.face_landmarks:
                return None

            landmarks = result.face_landmarks[0]
            ch, cw = crop.shape[:2]

            def point(idx):
                p = landmarks[idx]
                return (p.x * cw, p.y * ch)

            upper, lower = point(13), point(14)
            left, right = point(61), point(291)
            mouth_height = ((upper[0] - lower[0]) ** 2 + (upper[1] - lower[1]) ** 2) ** 0.5
            mouth_width = ((left[0] - right[0]) ** 2 + (left[1] - right[1]) ** 2) ** 0.5

            return mouth_height / mouth_width if mouth_width > 1e-6 else None
        except Exception:
            return None

    def _audio_energy_windows(self, video_path, clip_start, clip_end, window, num_buckets, ffmpeg_bin):
        """
        Extracts [clip_start, clip_end]'s audio via ffmpeg into a mono 16kHz WAV
        and returns RMS energy per `window`-second bucket, normalized to [0,1]
        against this clip's own peak (so the silence threshold adapts to whatever
        recording level this specific clip has, rather than a fixed dB number).
        Returns [] on any failure (ffmpeg missing, no audio track, etc.) — callers
        treat that as "no signal, don't gate on silence."
        """
        with tempfile.TemporaryDirectory() as tmp:
            wav_path = os.path.join(tmp, "audio.wav")
            try:
                subprocess.run(
                    [
                        ffmpeg_bin, "-y",
                        "-ss", str(clip_start), "-t", str(max(0.1, clip_end - clip_start)), "-i", video_path,
                        "-vn", "-ac", "1", "-ar", "16000", "-f", "wav", wav_path,
                    ],
                    capture_output=True, timeout=120, check=True,
                )
                with wave.open(wav_path, "rb") as wf:
                    raw = wf.readframes(wf.getnframes())
                    sample_rate = wf.getframerate()
            except Exception as e:
                logger.warning(f"Audio extraction for active-speaker detection failed, continuing without it: {e}")
                return []

        samples = np.frombuffer(raw, dtype=np.int16).astype(np.float64)
        if samples.size == 0:
            return []

        window_samples = max(1, int(sample_rate * window))
        energies = []
        for start in range(0, samples.size, window_samples):
            chunk = samples[start:start + window_samples]
            energies.append(float(np.sqrt(np.mean(chunk ** 2))) if chunk.size else 0.0)

        while len(energies) < num_buckets:
            energies.append(0.0)

        peak = max(energies) if energies else 0.0
        if peak <= 0:
            return [0.0] * len(energies)

        return [e / peak for e in energies]

    def _resolve_active_speaker(self, dense_samples, audio_energy, decision_window, num_buckets):
        """
        @return {bucket_index: track_id_or_None} — None means "no confident
        active-speaker pick for this window" (silence, a tie, or MAR unavailable);
        callers fall back to largest-face-in-window positioning for those.
        """
        decisions = {}

        for bucket in range(num_buckets):
            b_start = bucket * decision_window
            b_end = b_start + decision_window
            bucket_tracks = [tracks for t, tracks in dense_samples if b_start <= t < b_end]

            mars_by_track = {}
            for tracks in bucket_tracks:
                for tr in tracks:
                    if tr["mar"] is not None:
                        mars_by_track.setdefault(tr["id"], []).append(tr["mar"])

            if len(mars_by_track) <= 1:
                decisions[bucket] = next(iter(mars_by_track), None)
                continue

            energy = audio_energy[bucket] if bucket < len(audio_energy) else None
            if energy is not None and energy < self._SILENCE_ENERGY_FRACTION:
                decisions[bucket] = None
                continue

            best_track, best_variance = None, -1.0
            for track_id, mars in mars_by_track.items():
                variance = float(np.var(mars)) if len(mars) > 1 else 0.0
                if variance > best_variance:
                    best_track, best_variance = track_id, variance

            decisions[bucket] = best_track

        return decisions

    def _points_from_windows(self, dense_samples, decisions, num_buckets, decision_window, source_width, source_height):
        """@return list of (time, (cx, cy), speaker_label_or_None)."""
        points = []
        last_center = (source_width / 2.0, source_height / 2.0)

        for bucket in range(num_buckets):
            b_start = bucket * decision_window
            b_end = b_start + decision_window
            bucket_tracks = [tr for t, tracks in dense_samples if b_start <= t < b_end for tr in tracks]

            chosen_id = decisions.get(bucket)
            candidates = [tr for tr in bucket_tracks if tr["id"] == chosen_id] if chosen_id is not None else bucket_tracks

            if candidates:
                best = max(candidates, key=lambda tr: tr["w"] * tr["h"])
                last_center = (best["cx"], best["cy"])

            speaker_label = str(chosen_id) if chosen_id is not None else None
            points.append((round(b_start, 2), last_center, speaker_label))

        return points

    def _smooth_with_speaker(self, points, alpha=0.35):
        """Same exponential moving average as _smooth(), plus passing the speaker label through untouched."""
        smoothed = []
        prev = None
        for t, (cx, cy), speaker in points:
            prev = (cx, cy) if prev is None else (
                alpha * cx + (1 - alpha) * prev[0],
                alpha * cy + (1 - alpha) * prev[1],
            )
            smoothed.append((t, prev, speaker))
        return smoothed

    # ------------------------------------------------------------------
    # Shared by both paths.
    # ------------------------------------------------------------------

    def _smooth(self, points, alpha=0.35):
        """Exponential moving average so the crop pans instead of jittering with every detection."""
        smoothed = []
        prev = None
        for t, (cx, cy) in points:
            prev = (cx, cy) if prev is None else (
                alpha * cx + (1 - alpha) * prev[0],
                alpha * cy + (1 - alpha) * prev[1],
            )
            smoothed.append((t, prev))
        return smoothed

    def _to_keyframes(self, smoothed_points, crop_w, crop_h, source_width, source_height):
        keyframes = []
        prev_box = None
        for point in smoothed_points:
            if len(point) == 3:
                t, (cx, cy), speaker = point
            else:
                t, (cx, cy) = point
                speaker = None

            x, y = self._clamp_box(cx, cy, crop_w, crop_h, source_width, source_height)
            box = (round(x, 1), round(y, 1))
            if box != prev_box or speaker != (keyframes[-1]["active_speaker"] if keyframes else None):
                keyframes.append({
                    "time": t,
                    "x": box[0],
                    "y": box[1],
                    "width": float(crop_w),
                    "height": float(crop_h),
                    "active_speaker": speaker,
                })
                prev_box = box

        if not keyframes:
            cx, cy = source_width / 2.0, source_height / 2.0
            x, y = self._clamp_box(cx, cy, crop_w, crop_h, source_width, source_height)
            keyframes.append({
                "time": 0.0, "x": x, "y": y,
                "width": float(crop_w), "height": float(crop_h), "active_speaker": None,
            })

        return keyframes

    def _crop_size(self, source_width, source_height, target_aspect_ratio):
        ar_w, ar_h = (int(p) for p in target_aspect_ratio.split(":"))
        target_ratio = ar_w / ar_h
        source_ratio = source_width / source_height

        if target_ratio < source_ratio:
            crop_h = source_height
            crop_w = round(crop_h * target_ratio)
        else:
            crop_w = source_width
            crop_h = round(crop_w / target_ratio)

        return crop_w, crop_h

    def _clamp_box(self, center_x, center_y, crop_w, crop_h, source_width, source_height):
        x = max(0.0, min(center_x - crop_w / 2.0, source_width - crop_w))
        y = max(0.0, min(center_y - crop_h / 2.0, source_height - crop_h))
        return x, y
