FROM mcr.microsoft.com/devcontainers/php:8.2-bookworm

RUN apt-get update && apt-get install -y \
    libzip-dev \
    libicu-dev \
    unzip \
    git \
    zip \
    curl \
    && docker-php-ext-install intl zip pcntl