FROM node:24-alpine AS build

WORKDIR /src
COPY frontend/package.json frontend/package-lock.json ./
# --ignore-scripts: das postinstall ruft `nuxt prepare`, und die Konfiguration
# liegt zu diesem Zeitpunkt noch nicht im Image. `nuxt build` bereitet ohnehin
# selbst vor.
RUN npm ci --ignore-scripts

COPY frontend/ ./
RUN npm run build

# ---------------------------------------------------------------------------
FROM node:24-alpine AS runtime

RUN apk add --no-cache tini

WORKDIR /app
COPY --from=build /src/.output ./

ENV NODE_ENV=production \
    NITRO_HOST=0.0.0.0 \
    NITRO_PORT=3000

EXPOSE 3000

USER node
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["node", "server/index.mjs"]
