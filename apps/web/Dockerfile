# syntax=docker/dockerfile:1
# Production image for the Evenbooks web app: Next.js standalone server, non-root.
# Built and pushed to GHCR by .github/workflows/deploy.yml.
# Runtime: LEDGERLY_API_URL=https://api.example (server side only; without it the app runs in demo mode).

FROM node:22-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

FROM node:22-alpine AS build
WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1
COPY --from=deps /app/node_modules ./node_modules
COPY . .
# public/ holds no files yet, and git does not keep empty folders.
RUN mkdir -p public && npm run build

FROM node:22-alpine AS runtime
WORKDIR /app
ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    PORT=3000 \
    HOSTNAME=0.0.0.0
RUN addgroup -S -g 10001 ledgerly && adduser -S -u 10001 -G ledgerly ledgerly
COPY --from=build --chown=ledgerly:ledgerly /app/.next/standalone ./
COPY --from=build --chown=ledgerly:ledgerly /app/.next/static ./.next/static
COPY --from=build --chown=ledgerly:ledgerly /app/public ./public
USER ledgerly
EXPOSE 3000
HEALTHCHECK --interval=15s --timeout=3s --retries=5 CMD ["node", "-e", "fetch('http://127.0.0.1:3000/').then(r => process.exit(r.ok ? 0 : 1)).catch(() => process.exit(1))"]
CMD ["node", "server.js"]
