# Use the official PHP 8.2 Apache image
FROM php:8.2-apache

# Install the required system packages for image processing (GD and Curl)
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libfreetype6-dev \
    libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install gd \
    && docker-php-ext-install curl

# Copy all your project files into the server's web root
COPY . /var/www/html/

# Create the upload and output directories
RUN mkdir -p /var/www/html/uploads \
    && mkdir -p /var/www/html/output \
    && mkdir -p /var/www/html/php/uploads \
    && mkdir -p /var/www/html/php/output

# Give the Apache web server permission to write and delete files in these folders
RUN chown -R www-data:www-data /var/www/html/
RUN chmod -R 777 /var/www/html/uploads /var/www/html/output
