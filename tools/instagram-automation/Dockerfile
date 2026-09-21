FROM node:22-bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends chromium fonts-liberation \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
ENV PUPPETEER_SKIP_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium \
    IG_AUTOMATION_HOST=0.0.0.0 \
    IG_NO_SANDBOX=true
COPY package.json package-lock.json ./
RUN npm ci --omit=dev
COPY . .
EXPOSE 8300
CMD ["npm", "start"]
