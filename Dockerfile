
FROM composer:latest AS quill-build

VOLUME /var/www/html/Quill
WORKDIR /var/www/html/Quill
COPY . .
RUN composer install

FROM php:7.2-fpm-alpine
WORKDIR /var/www/html/Quill
COPY --from=quill-build /var/www/html/Quill .






# docker build . -t quill:latest
# container-diff analyze daemon://quill
