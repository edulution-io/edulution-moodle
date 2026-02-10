FROM ubuntu:22.04

LABEL maintainer="edulution.io"
LABEL description="Moodle for edulution.io - optimized for reverse proxy and iframe embedding"

# Avoid interactive prompts
ENV DEBIAN_FRONTEND=noninteractive

# Moodle version
ENV MOODLE_VERSION=4.5
ENV MOODLE_BRANCH=MOODLE_405_STABLE

# Default environment variables
ENV MOODLE_DATABASE_HOST=moodle-db \
    MOODLE_DATABASE_NAME=moodle \
    MOODLE_DATABASE_USER=moodle \
    MOODLE_DATABASE_PASSWORD=moodle \
    MOODLE_ADMIN_USER=admin \
    MOODLE_ADMIN_PASSWORD=changeme \
    MOODLE_ADMIN_EMAIL=admin@example.com \
    MOODLE_SITE_NAME="Edulution Moodle" \
    MOODLE_HOSTNAME=localhost \
    MOODLE_PATH=/moodle \
    MOODLE_DATA=/var/moodledata \
    MOODLE_REVERSEPROXY=true \
    MOODLE_SSLPROXY=true \
    MOODLE_ALLOWFRAMEMBEDDING=true \
    ENABLE_SSO=0 \
    SYNC_ENABLED=0

# Install dependencies
RUN apt-get update && apt-get install -y \
    apache2 \
    libapache2-mod-php \
    php \
    php-mysql \
    php-pgsql \
    php-intl \
    php-xmlrpc \
    php-soap \
    php-gd \
    php-json \
    php-cli \
    php-curl \
    php-zip \
    php-xml \
    php-mbstring \
    php-bcmath \
    php-ldap \
    php-redis \
    curl \
    wget \
    git \
    unzip \
    cron \
    supervisor \
    mariadb-client \
    locales \
    && rm -rf /var/lib/apt/lists/*

# Generate locales
RUN locale-gen en_US.UTF-8 de_DE.UTF-8
ENV LANG=en_US.UTF-8

# Configure PHP
RUN echo "max_execution_time = 300" >> /etc/php/*/apache2/php.ini && \
    echo "memory_limit = 512M" >> /etc/php/*/apache2/php.ini && \
    echo "post_max_size = 100M" >> /etc/php/*/apache2/php.ini && \
    echo "upload_max_filesize = 100M" >> /etc/php/*/apache2/php.ini && \
    echo "max_input_vars = 5000" >> /etc/php/*/apache2/php.ini && \
    echo "date.timezone = Europe/Berlin" >> /etc/php/*/apache2/php.ini

# Enable Apache modules
RUN a2enmod rewrite headers ssl

# Download Moodle
RUN cd /tmp && \
    curl -L https://download.moodle.org/download.php/direct/stable405/moodle-latest-405.tgz -o moodle.tgz && \
    tar -xzf moodle.tgz && \
    mv moodle /var/www/html/moodle && \
    rm moodle.tgz

# Create moodledata directory
RUN mkdir -p /var/moodledata && \
    chown -R www-data:www-data /var/moodledata && \
    chmod 755 /var/moodledata

# Set ownership
RUN chown -R www-data:www-data /var/www/html/moodle

# Copy configuration files
COPY config/apache-moodle.conf /etc/apache2/sites-available/moodle.conf
COPY config/config-template.php /var/www/html/moodle/config-template.php
COPY scripts/entrypoint.sh /entrypoint.sh
COPY scripts/configure-moodle.sh /usr/local/bin/configure-moodle.sh
COPY config/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Make scripts executable
RUN chmod +x /entrypoint.sh /usr/local/bin/configure-moodle.sh

# Disable default site and enable moodle
RUN a2dissite 000-default && a2ensite moodle

# Create log directory
RUN mkdir -p /var/log/moodle && chown www-data:www-data /var/log/moodle

# Expose port 80
EXPOSE 80

# Health check
HEALTHCHECK --interval=60s --timeout=10s --start-period=120s --retries=3 \
    CMD curl -fs http://localhost/moodle/login/index.php || exit 1

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
