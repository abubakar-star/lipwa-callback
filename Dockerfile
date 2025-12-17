FROM php:8.2-apache

# Install PostgreSQL PDO
RUN docker-php-ext-install pdo pdo_pgsql

# Enable Apache rewrite
RUN a2enmod rewrite

# Copy all project files
COPY . /var/www/html/

WORKDIR /var/www/html

EXPOSE 80
