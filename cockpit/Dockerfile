FROM node:20-alpine

WORKDIR /app

# This image builds with `context: ./cockpit`, so it cannot see the workspace
# root or its lockfile. That is why cockpit/package-lock.json exists even though
# npm itself resolves through the root one everywhere else — and why this is
# `npm ci`, not `npm install`: an unpinned install in an image is not a build,
# it is a roll of the dice.
COPY package.json package-lock.json ./
RUN npm ci

COPY . .

RUN chown -R node:node /app
USER node

EXPOSE 3000

# Align with vite.config.js / tunnel apex (spidernetos.com → :3000)
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0", "--port", "3000"]
