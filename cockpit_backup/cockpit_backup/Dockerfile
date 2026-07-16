FROM node:20-alpine

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm install

COPY . .

EXPOSE 3000

# Align with vite.config.js / tunnel apex (spidernetos.com → :3000)
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0", "--port", "3000"]
