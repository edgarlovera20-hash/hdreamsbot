FROM node:20-slim
RUN apt-get update && apt-get install -y --no-install-recommends git openssh-client python3 make g++ ca-certificates && rm -rf /var/lib/apt/lists/*
# Convertir URLs SSH de GitHub a HTTPS (requerido para libsignal-node en build)
RUN printf '[url "https://github.com/"]\n  insteadOf = ssh://git@github.com/\n  insteadOf = git@github.com:\n' > /root/.gitconfig
WORKDIR /app
COPY package.json .
RUN npm install --omit=dev
COPY server.js .
VOLUME ["/app/auth"]
EXPOSE 4000
CMD ["node", "server.js"]
