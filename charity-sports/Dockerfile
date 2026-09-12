# Charity Sports: public site, API and admin panel in one process.
FROM node:22-bookworm-slim

WORKDIR /app

# sharp and @node-rs/argon2 ship prebuilt binaries, so no build toolchain
# is needed here.
COPY package*.json ./
RUN npm ci --omit=dev

COPY . .

# Content and uploads belong on a mounted volume. Without one, every edit is
# lost when the container restarts.
RUN mkdir -p /data/content /data/uploads && chown -R node:node /data /app
USER node

ENV NODE_ENV=production \
    CONTENT_DIR=/data/content \
    UPLOAD_DIR=/data/uploads \
    PORT=3000 \
    HOST=0.0.0.0

VOLUME ["/data"]
EXPOSE 3000

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD node -e "fetch('http://127.0.0.1:3000/api/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

CMD ["node", "server/index.js"]
