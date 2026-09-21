FROM python:3.11-slim
# mediapipe/opencv need these shared libs even in headless use.
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg libgl1 libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY . .
ENV FACE_TRACKER_HOST=0.0.0.0
EXPOSE 8200
CMD ["python", "main.py"]
