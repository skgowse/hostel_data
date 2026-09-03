# Dockerfile for Node.js Express Mess & Hostel System
FROM node:20-alpine

WORKDIR /usr/src/app

# Install dependencies
COPY package*.json ./
RUN npm ci --only=production

# Copy application source code
COPY . .

# Environment Defaults
ENV PORT=8000
ENV HOST=0.0.0.0
ENV NODE_ENV=production

EXPOSE 8000

CMD ["node", "server.js"]
