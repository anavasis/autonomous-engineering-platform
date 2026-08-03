FROM php:8.3-cli
WORKDIR /app
COPY backend /app/backend
RUN docker-php-ext-install pdo_sqlite
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "backend/public"]
