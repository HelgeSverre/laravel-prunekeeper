ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libbz2-dev \
    libpq-dev \
    libsqlite3-dev \
    libicu-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
    zip \
    bz2 \
    pdo_mysql \
    pdo_pgsql \
    pdo_sqlite \
    pcntl \
    intl

# Install pcov for code coverage (faster than xdebug)
RUN pecl install pcov && docker-php-ext-enable pcov

# Enable phar writing for TarGzip compression tests
RUN echo "phar.readonly=0" > /usr/local/etc/php/conf.d/phar.ini

# Copy composer from official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
