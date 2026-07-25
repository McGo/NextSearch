FROM node:24-alpine AS build

WORKDIR /src
COPY frontend/package.json frontend/package-lock.json ./
# --ignore-scripts: the postinstall calls `nuxt prepare`, and the configuration
# isn't in the image at that point yet. Prepare runs explicitly below, once the
# source is present.
RUN npm ci --ignore-scripts

COPY frontend/ ./
# The release version, shown in the footer. Passed by CI from the git tag;
# defaults to the package version for local builds.
ARG APP_VERSION=
ENV NUXT_PUBLIC_APP_VERSION=$APP_VERSION
RUN npx nuxt prepare && npm run build

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
