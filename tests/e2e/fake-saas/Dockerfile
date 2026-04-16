FROM php:8.2-cli-alpine
# No extensions needed — the fake-saas stores and returns envelopes
# opaquely (no crypto). If signature verification is added later,
# install sodium: apk add libsodium-dev && docker-php-ext-install sodium

WORKDIR /app
COPY server.php /app/server.php

RUN mkdir -p /var/lib/fake-saas

EXPOSE 8003

CMD ["php", "-S", "0.0.0.0:8003", "/app/server.php"]
