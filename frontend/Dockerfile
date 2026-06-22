FROM node:20-alpine AS build

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm ci --legacy-peer-deps 2>/dev/null || npm install

COPY . .

# Cockpit bundle should be pre-built into public/cockpit (yarn cockpit:build)
RUN npm run build

FROM nginx:alpine

COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/build /usr/share/nginx/html
COPY --from=build /app/public/cockpit /usr/share/nginx/html/cockpit

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
