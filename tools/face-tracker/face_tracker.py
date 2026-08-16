import logging
import os

import cv2

logger = logging.getLogger(__name__)

_MODEL_PATH = os.path.join(os.path.dirname(__file__), "models", "face_detection_yunet_2023mar.onnx")


class FaceTracker:
    """
    Detects the primary face's position across a clip's time range and produces
    crop keyframes that follow it, instead of a fixed or blindly-alternating box.

    Uses OpenCV's YuNet DNN face detector (models/face_detection_yunet_2023mar.onnx,
    from the official opencv_zoo) rather than a Haar cascade — opencv-python-headless
    5.x no longer bundles Haar cascade XML data or cv2.CascadeClassifier at all, and
    YuNet is more accurate anyway (handles off-angle faces better). On a missed frame
    we keep the last known face position rather than snapping the crop back to
    center, since a stale-but-correct position looks better than a visible jump.
    """

    def __init__(self):
        if not os.path.exists(_MODEL_PATH):
            raise RuntimeError(
                f"Face detection model not found at {_MODEL_PATH}. "
                "Download it from https://github.com/opencv/opencv_zoo/raw/main/models/"
                "face_detection_yunet/face_detection_yunet_2023mar.onnx into tools/face-tracker/models/."
            )
        self._model_path = _MODEL_PATH

    def detect_crop_keyframes(
        self,
        video_path,
        clip_start,
        clip_end,
        source_width,
        source_height,
        target_aspect_ratio,
        sample_interval=1.0,
    ):
        crop_w, crop_h = self._crop_size(source_width, source_height, target_aspect_ratio)

        detector = cv2.FaceDetectorYN.create(
            self._model_path, "", (source_width, source_height),
            score_threshold=0.6, nms_threshold=0.3, top_k=5000,
        )

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
        for t, (cx, cy) in smoothed_points:
            x, y = self._clamp_box(cx, cy, crop_w, crop_h, source_width, source_height)
            box = (round(x, 1), round(y, 1))
            if box != prev_box:
                keyframes.append({
                    "time": t,
                    "x": box[0],
                    "y": box[1],
                    "width": float(crop_w),
                    "height": float(crop_h),
                    "active_speaker": None,
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
