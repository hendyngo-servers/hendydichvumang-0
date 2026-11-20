FROM node:22-alpine AS runtime
ENV NODE_ENV=production
WORKDIR /app
COPY package*.json ./
RUN npm ci --omit=dev --no-audit --no-fund && npm cache clean --force
COPY src ./src
COPY migrations ./migrations
COPY public ./public
RUN chown -R node:node /app
USER node
EXPOSE 10000
CMD ["npm", "start"]
