# edulution-moodle Dockerfile
# Basierend auf Ubuntu 22.04 (NICHT Bitnami!)
#
# Build: docker build -t edulution-moodle:latest --build-arg MOODLE_VERSION=MOODLE_405_STABLE -f build/Dockerfile .
# Run:   docker run -d -p 8080:80 edulution-moodle:latest

FROM ubuntu:22.04

# =============================================================================
# BUILD ARGUMENTS
# =============================================================================
ARG MOODLE_VERSION=MOODLE_405_STABLE

# =============================================================================
# ENVIRONMENT VARIABLES
# =============================================================================
ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=Europe/Berlin
ENV LANG=C.UTF-8
ENV LC_ALL=C.UTF-8

# Moodle paths
ENV MOODLE_PATH=/var/www/html/moodle
ENV MOODLE_DATA=/var/moodledata
ENV SYNC_DATA=/srv/data
ENV BACKUP_PATH=/srv/backups
ENV LOG_PATH=/var/log/moodle-sync
ENV CONFIG_PATH=/srv/config

# PHP settings
ENV PHP_MEMORY_LIMIT=256M
ENV PHP_MAX_EXECUTION_TIME=300
ENV PHP_POST_MAX_SIZE=100M
ENV PHP_UPLOAD_MAX_FILESIZE=100M
ENV PHP_MAX_INPUT_VARS=5000

# =============================================================================
# INSTALL SYSTEM DEPENDENCIES
# =============================================================================
RUN apt-get update && apt-get install -y --no-install-recommends \
    # Apache web server
    apache2 \
    libapache2-mod-php8.1 \
    # PHP 8.1 core and all Moodle-required extensions
    php8.1 \
    php8.1-cli \
    php8.1-common \
    php8.1-mysql \
    php8.1-pgsql \
    php8.1-gd \
    php8.1-curl \
    php8.1-zip \
    php8.1-intl \
    php8.1-xml \
    php8.1-mbstring \
    php8.1-soap \
    php8.1-xmlrpc \
    php8.1-ldap \
    php8.1-redis \
    php8.1-opcache \
    php8.1-apcu \
    php8.1-igbinary \
    php8.1-imagick \
    php8.1-bcmath \
    # Database clients
    mariadb-client \
    postgresql-client \
    # Version control and utilities
    git \
    unzip \
    curl \
    wget \
    ca-certificates \
    # Python 3 for sync module
    python3 \
    python3-pip \
    python3-venv \
    # Process management
    supervisor \
    # Cron for scheduled tasks
    cron \
    # Additional tools
    vim \
    nano \
    htop \
    rsync \
    ghostscript \
    graphviz \
    aspell \
    aspell-de \
    aspell-en \
    && rm -rf /var/lib/apt/lists/* \
    && apt-get clean

# =============================================================================
# INSTALL COMPOSER (PHP Dependency Manager)
# =============================================================================
RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin \
    --filename=composer \
    && composer --version

# =============================================================================
# CONFIGURE PHP FOR MOODLE
# =============================================================================
RUN echo "[PHP]" > /etc/php/8.1/apache2/conf.d/99-moodle.ini && \
    echo "memory_limit = ${PHP_MEMORY_LIMIT}" >> /etc/php/8.1/apache2/conf.d/99-moodle.ini && \
    echo "max_execution_time = ${PHP_MAX_EXECUTION_TIME}" >> /etc/php/8.1/apache2/conf.d/99-moodle.ini && \
    echo "post_max_size = ${PHP_POST_MAX_SIZE}" >> /etc/php/8.1/apache2/conf.d/99-moodle.ini && \
    echo "upload_max_filesize = ${PHP_UPLOAD_MAX_FILESIZE}" >> /etc/php/8.1/apache2/conf.d/99-moodle.ini && \
    echo "max_input_vars = ${PHP_MAX_INPUT_VARS}" >> /etc/php/8.1/apache2/conf.d/99-moodle.ini && \
    echo "max_input_time = 300" >> /etc/php/8.1/apache2/conf.d/99-moodle.ini && \
    echo "date.timezone = Europe/Berlin" >> /etc/php/8.1/apache2/conf.d/99-moodle.ini && \
    echo "" >> /etc/php/8.1/apache2/conf.d/99-moodle.ini && \
    echo "; OPcache settings for Moodle" >> /etc/php/8.1/apache2/conf.d/99-moodle.ini && \
    echo "opcache.enable = 1" >> /etc/php/8.1/apache2/conf.d/99-moodle.ini && \
    echo "opcache.memory_consumption = 128" >> /etc/php/8.1/apache2/conf.d/99-moodle.ini && \
    echo "opcache.max_accelerated_files = 10000" >> /etc/php/8.1/apache2/conf.d/99-moodle.ini && \
    echo "opcache.revalidate_freq = 60" >> /etc/php/8.1/apache2/conf.d/99-moodle.ini && \
    echo "opcache.use_cwd = 1" >> /etc/php/8.1/apache2/conf.d/99-moodle.ini && \
    echo "opcache.validate_timestamps = 1" >> /etc/php/8.1/apache2/conf.d/99-moodle.ini && \
    echo "opcache.save_comments = 1" >> /etc/php/8.1/apache2/conf.d/99-moodle.ini && \
    echo "opcache.enable_file_override = 0" >> /etc/php/8.1/apache2/conf.d/99-moodle.ini

# Copy the same settings for CLI
RUN cp /etc/php/8.1/apache2/conf.d/99-moodle.ini /etc/php/8.1/cli/conf.d/99-moodle.ini

# =============================================================================
# DOWNLOAD AND SETUP MOODLE FROM GITHUB
# =============================================================================
RUN git clone --branch ${MOODLE_VERSION} --depth 1 \
    https://github.com/moodle/moodle.git ${MOODLE_PATH} \
    && rm -rf ${MOODLE_PATH}/.git

# =============================================================================
# INSTALL MOOSH (Moodle Shell)
# =============================================================================
RUN git clone https://github.com/tmuras/moosh.git /opt/moosh \
    && cd /opt/moosh \
    && composer install --no-dev --optimize-autoloader \
    && ln -s /opt/moosh/moosh.php /usr/local/bin/moosh \
    && chmod +x /usr/local/bin/moosh

# =============================================================================
# SETUP PYTHON VIRTUAL ENVIRONMENT FOR SYNC MODULE
# =============================================================================
RUN python3 -m venv /opt/sync-venv \
    && /opt/sync-venv/bin/pip install --upgrade pip setuptools wheel

# Pre-install common sync dependencies (will be overwritten if requirements.txt exists)
RUN /opt/sync-venv/bin/pip install \
    requests \
    python-keycloak \
    mysql-connector-python \
    psycopg2-binary \
    fastapi \
    uvicorn \
    pydantic \
    python-dotenv \
    schedule \
    aiohttp

# =============================================================================
# CREATE ALL REQUIRED DIRECTORIES
# =============================================================================
RUN mkdir -p ${MOODLE_DATA} \
    && mkdir -p ${MOODLE_DATA}/cache \
    && mkdir -p ${MOODLE_DATA}/localcache \
    && mkdir -p ${MOODLE_DATA}/sessions \
    && mkdir -p ${MOODLE_DATA}/temp \
    && mkdir -p ${MOODLE_DATA}/trashdir \
    && mkdir -p ${MOODLE_DATA}/lang \
    && mkdir -p ${SYNC_DATA} \
    && mkdir -p ${BACKUP_PATH} \
    && mkdir -p ${LOG_PATH} \
    && mkdir -p ${CONFIG_PATH} \
    && mkdir -p /var/run/apache2 \
    && mkdir -p /var/lock/apache2 \
    && mkdir -p /var/log/apache2 \
    && mkdir -p /var/log/supervisor

# =============================================================================
# CONFIGURE APACHE FOR MOODLE
# =============================================================================
RUN a2enmod rewrite \
    && a2enmod ssl \
    && a2enmod headers \
    && a2enmod expires \
    && a2enmod deflate

# Create Apache VirtualHost configuration for Moodle
RUN echo '<VirtualHost *:80>' > /etc/apache2/sites-available/moodle.conf && \
    echo '    ServerAdmin admin@localhost' >> /etc/apache2/sites-available/moodle.conf && \
    echo '    DocumentRoot /var/www/html/moodle' >> /etc/apache2/sites-available/moodle.conf && \
    echo '' >> /etc/apache2/sites-available/moodle.conf && \
    echo '    <Directory /var/www/html/moodle>' >> /etc/apache2/sites-available/moodle.conf && \
    echo '        Options -Indexes +FollowSymLinks' >> /etc/apache2/sites-available/moodle.conf && \
    echo '        AllowOverride All' >> /etc/apache2/sites-available/moodle.conf && \
    echo '        Require all granted' >> /etc/apache2/sites-available/moodle.conf && \
    echo '' >> /etc/apache2/sites-available/moodle.conf && \
    echo '        # Security headers' >> /etc/apache2/sites-available/moodle.conf && \
    echo '        Header always set X-Content-Type-Options "nosniff"' >> /etc/apache2/sites-available/moodle.conf && \
    echo '        Header always set X-Frame-Options "SAMEORIGIN"' >> /etc/apache2/sites-available/moodle.conf && \
    echo '        Header always set X-XSS-Protection "1; mode=block"' >> /etc/apache2/sites-available/moodle.conf && \
    echo '    </Directory>' >> /etc/apache2/sites-available/moodle.conf && \
    echo '' >> /etc/apache2/sites-available/moodle.conf && \
    echo '    # Logging' >> /etc/apache2/sites-available/moodle.conf && \
    echo '    ErrorLog ${APACHE_LOG_DIR}/moodle_error.log' >> /etc/apache2/sites-available/moodle.conf && \
    echo '    CustomLog ${APACHE_LOG_DIR}/moodle_access.log combined' >> /etc/apache2/sites-available/moodle.conf && \
    echo '' >> /etc/apache2/sites-available/moodle.conf && \
    echo '    # Compression' >> /etc/apache2/sites-available/moodle.conf && \
    echo '    <IfModule mod_deflate.c>' >> /etc/apache2/sites-available/moodle.conf && \
    echo '        AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript application/json' >> /etc/apache2/sites-available/moodle.conf && \
    echo '    </IfModule>' >> /etc/apache2/sites-available/moodle.conf && \
    echo '</VirtualHost>' >> /etc/apache2/sites-available/moodle.conf

# Enable Moodle site, disable default
RUN a2dissite 000-default.conf \
    && a2ensite moodle.conf

# Set ServerName to suppress warning
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# =============================================================================
# CONFIGURE SUPERVISOR FOR PROCESS MANAGEMENT
# =============================================================================
RUN echo '[supervisord]' > /etc/supervisor/conf.d/supervisord.conf && \
    echo 'nodaemon=true' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'user=root' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'logfile=/var/log/supervisor/supervisord.log' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'pidfile=/var/run/supervisord.pid' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo '' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo '[program:apache2]' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'command=/usr/sbin/apache2ctl -D FOREGROUND' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'autostart=true' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'autorestart=true' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'priority=10' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stdout_logfile=/var/log/supervisor/apache2_stdout.log' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stderr_logfile=/var/log/supervisor/apache2_stderr.log' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo '' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo '[program:cron]' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'command=/usr/sbin/cron -f' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'autostart=true' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'autorestart=true' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'priority=20' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stdout_logfile=/var/log/supervisor/cron_stdout.log' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stderr_logfile=/var/log/supervisor/cron_stderr.log' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo '' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo '[program:moodle-sync]' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'command=/opt/sync-venv/bin/python /opt/edulution-moodle-sync/sync.py' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'directory=/opt/edulution-moodle-sync' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'autostart=%(ENV_SYNC_ENABLED)s' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'autorestart=true' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'startsecs=10' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'startretries=3' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'priority=30' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'user=www-data' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stdout_logfile=/var/log/moodle-sync/sync_stdout.log' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stderr_logfile=/var/log/moodle-sync/sync_stderr.log' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stdout_logfile_maxbytes=10MB' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stderr_logfile_maxbytes=10MB' >> /etc/supervisor/conf.d/supervisord.conf

# =============================================================================
# SETUP CRON FOR MOODLE SCHEDULED TASKS
# =============================================================================
RUN echo '# Moodle cron job - runs every minute' > /etc/cron.d/moodle-cron && \
    echo '* * * * * www-data /usr/bin/php /var/www/html/moodle/admin/cli/cron.php > /dev/null 2>&1' >> /etc/cron.d/moodle-cron && \
    chmod 0644 /etc/cron.d/moodle-cron && \
    crontab /etc/cron.d/moodle-cron

# =============================================================================
# SET CORRECT PERMISSIONS (www-data)
# =============================================================================
RUN chown -R www-data:www-data ${MOODLE_PATH} \
    && chown -R www-data:www-data ${MOODLE_DATA} \
    && chown -R www-data:www-data ${SYNC_DATA} \
    && chown -R www-data:www-data ${BACKUP_PATH} \
    && chown -R www-data:www-data ${LOG_PATH} \
    && chown -R www-data:www-data ${CONFIG_PATH} \
    && chmod -R 755 ${MOODLE_PATH} \
    && chmod -R 770 ${MOODLE_DATA} \
    && chmod -R 770 ${SYNC_DATA} \
    && chmod -R 770 ${BACKUP_PATH} \
    && chmod -R 770 ${LOG_PATH} \
    && chmod -R 755 ${CONFIG_PATH}

# =============================================================================
# COPY APPLICATION FILES
# =============================================================================
# Sync Module
COPY build/edulution-moodle-sync /opt/edulution-moodle-sync

# Install sync module dependencies
RUN if [ -f /opt/edulution-moodle-sync/requirements.txt ]; then \
        /opt/sync-venv/bin/pip install -r /opt/edulution-moodle-sync/requirements.txt; \
    fi

# Create symlink for easy access
RUN ln -sf /opt/edulution-moodle-sync/sync.py /opt/sync.py

# Entrypoint script
COPY build/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Admin UI (optional)
COPY build/admin-ui /opt/admin-ui

# Set permissions for sync module
RUN chown -R www-data:www-data /opt/edulution-moodle-sync \
    && chown -R www-data:www-data /opt/admin-ui 2>/dev/null || true

# =============================================================================
# HEALTHCHECK
# =============================================================================
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -f http://localhost/login/index.php || exit 1

# =============================================================================
# WORKING DIRECTORY
# =============================================================================
WORKDIR ${MOODLE_PATH}

# =============================================================================
# EXPOSE PORTS
# =============================================================================
EXPOSE 80 443

# =============================================================================
# ENTRYPOINT AND DEFAULT COMMAND
# =============================================================================
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# =============================================================================
# LABELS
# =============================================================================
LABEL maintainer="edulution.io" \
    org.opencontainers.image.title="edulution-moodle" \
    org.opencontainers.image.description="Moodle LMS with Keycloak SSO integration for edulution" \
    org.opencontainers.image.source="https://github.com/edulution-io/edulution-moodle" \
    org.opencontainers.image.vendor="edulution.io" \
    org.opencontainers.image.licenses="GPL-3.0"
