FROM php:7.2-fpm-alpine

FROM composer:latest




VOLUME /var/www/html/Quill

COPY . /var/www/html/Quill


RUN cd /var/www/html/Quill && \
    composer install

