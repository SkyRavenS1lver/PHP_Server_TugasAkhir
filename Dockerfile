FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    python3 \
    python3-pip \
    python3-venv \
    && docker-php-ext-install pdo pdo_pgsql pgsql \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean

# Install Celery
RUN pip3 install celery redis --break-system-packages

WORKDIR /var/www/html

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 9000