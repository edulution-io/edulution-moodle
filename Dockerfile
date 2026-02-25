FROM ubuntu:24.04

LABEL maintainer="edulution.io"
LABEL description="Moodle for edulution.io - optimized for reverse proxy and iframe embedding"

# Avoid interactive prompts
ENV DEBIAN_FRONTEND=noninteractive

# Moodle version - can be overridden at build time with --build-arg
ARG MOODLE_VERSION=5.1.3
ARG MOODLE_BRANCH=501

# Store version in environment for runtime access
ENV MOODLE_VERSION=${MOODLE_VERSION}
ENV MOODLE_BRANCH_NUM=${MOODLE_BRANCH}

# Default environment variables (non-sensitive defaults only)
ENV MOODLE_DATABASE_HOST=moodle-db \
    MOODLE_DATABASE_NAME=moodle \
    MOODLE_DATABASE_USER=moodle \
    MOODLE_ADMIN_USER=admin \
    MOODLE_ADMIN_EMAIL=admin@example.com \
    MOODLE_SITE_NAME="Edulution Moodle" \
    MOODLE_HOSTNAME=localhost \
    MOODLE_DATA=/var/moodledata \
    MOODLE_REVERSEPROXY=false \
    MOODLE_SSLPROXY=true \
    MOODLE_ALLOWFRAMEMBEDDING=true \
    ENABLE_SSO=0

# Install dependencies (PHP 8.3 from Ubuntu 24.04)
RUN apt-get update && apt-get install -y \
    apache2 \
    libapache2-mod-php \
    php \
    php-mysql \
    php-pgsql \
    php-intl \
    php-soap \
    php-gd \
    php-cli \
    php-curl \
    php-zip \
    php-xml \
    php-mbstring \
    php-bcmath \
    php-ldap \
    php-redis \
    php-apcu \
    curl \
    wget \
    git \
    unzip \
    cron \
    supervisor \
    mariadb-client \
    locales \
    gettext-base \
    sudo \
    openssl \
    && rm -rf /var/lib/apt/lists/*

# Generate locales
RUN locale-gen en_US.UTF-8 de_DE.UTF-8
ENV LANG=en_US.UTF-8

# Configure PHP for both Apache AND CLI
RUN for PHP_INI in $(find /etc/php -name "php.ini"); do \
        echo "max_execution_time = 300" >> "$PHP_INI" && \
        echo "memory_limit = 512M" >> "$PHP_INI" && \
        echo "post_max_size = 100M" >> "$PHP_INI" && \
        echo "upload_max_filesize = 100M" >> "$PHP_INI" && \
        echo "max_input_vars = 5000" >> "$PHP_INI" && \
        echo "date.timezone = Europe/Berlin" >> "$PHP_INI"; \
    done

# Enable Apache modules
RUN a2enmod rewrite headers ssl expires deflate

# Generate self-signed SSL certificate for internal Traefik → Apache traffic
RUN openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
    -keyout /etc/ssl/private/moodle-selfsigned.key \
    -out /etc/ssl/certs/moodle-selfsigned.crt \
    -subj "/C=DE/O=edulution/CN=moodle-internal"

# Download Moodle (version set via build args)
RUN cd /tmp && \
    echo "Downloading Moodle ${MOODLE_VERSION} from stable${MOODLE_BRANCH}..." && \
    curl -L "https://download.moodle.org/download.php/direct/stable${MOODLE_BRANCH}/moodle-${MOODLE_VERSION}.tgz" -o moodle.tgz && \
    tar -xzf moodle.tgz && \
    mv moodle /var/www/html/moodle && \
    rm moodle.tgz

# Create moodledata directory
RUN mkdir -p /var/moodledata && \
    chown -R www-data:www-data /var/moodledata && \
    chmod 755 /var/moodledata

# Install Composer and Moosh (Moodle Shell)
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    git clone --depth 1 https://github.com/tmuras/moosh.git /opt/moosh && \
    cd /opt/moosh && composer install --no-dev --no-interaction && \
    ln -s /opt/moosh/moosh.php /usr/local/bin/moosh

# Copy edulution local plugin (Moodle 5.x: plugins go into public/)
COPY local/edulution /var/www/html/moodle/public/local/edulution

# Set ownership
RUN chown -R www-data:www-data /var/www/html/moodle

# Copy configuration files
COPY config/apache-moodle.conf.template /etc/apache2/sites-available/moodle.conf.template
COPY config/config-template.php /var/www/html/moodle/config-template.php
COPY scripts/entrypoint.sh /entrypoint.sh
COPY scripts/configure-moodle.sh /usr/local/bin/configure-moodle.sh
COPY scripts/generate-config.sh /usr/local/bin/generate-config.sh
COPY scripts/set-branding.php /usr/local/bin/set-branding.php
COPY config/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Make scripts executable
RUN chmod +x /entrypoint.sh /usr/local/bin/configure-moodle.sh /usr/local/bin/generate-config.sh

# Disable default site and enable moodle (will be generated at runtime)
RUN a2dissite 000-default

# Create log directories
RUN mkdir -p /var/log/moodle /var/log/supervisor && \
    chown www-data:www-data /var/log/moodle

# Expose ports (80 for healthcheck, 443 for Traefik → Moodle HTTPS)
EXPOSE 80 443

# Health check - Apache serves Moodle at /, Traefik handles path prefix
HEALTHCHECK --interval=60s --timeout=10s --start-period=180s --retries=3 \
    CMD curl -fs http://localhost/login/index.php || exit 1

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
