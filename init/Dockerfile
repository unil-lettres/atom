# Use the official PHP 8.3 FPM base image
FROM php:8.3-fpm

ENV DEBIAN_FRONTEND=noninteractive

# --------------------------------------------------------
# 1) Install system libraries and PHP dependencies
# --------------------------------------------------------
    RUN apt-get update -qq && \
    apt-get install -y -qq \
      build-essential \
      pkg-config \
      libjpeg-dev \
      libpng-dev \
      libtiff-dev \
      libgif-dev \
      libwebp-dev \
      libxslt1-dev \
      libpcre3-dev \
      curl \
      unzip \
      netcat-openbsd \
      sendmail \
      mariadb-client \
      ghostscript \
      poppler-utils \
      ffmpeg \
      default-jre-headless \
      rsync \
      grep && \
    pecl install apcu && \
    docker-php-ext-enable apcu && \
    docker-php-ext-install xsl pdo_mysql calendar && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# --------------------------------------------------------
# 2) Install ImageMagick from provided archive
# --------------------------------------------------------
COPY init/ImageMagick-6.9.11-60.tar.xz /tmp/
RUN cd /tmp && \
    tar -xf ImageMagick-6.9.11-60.tar.xz && \
    cd ImageMagick-6.9.11-60 && \
    ./configure --disable-dependency-tracking && \
    make -j"$(nproc)" && \
    make install && \
    ldconfig && \
    cd .. && \
    rm -rf ImageMagick-6.9.11-60 ImageMagick-6.9.11-60.tar.xz

# --------------------------------------------------------
# 3) Install extra PHP extensions not in the base image
# --------------------------------------------------------
RUN docker-php-ext-install xsl

# --------------------------------------------------------
# 4) Install FOP (includes Saxon)
# --------------------------------------------------------
RUN mkdir -p /opt/fop && \
    curl -L https://archive.apache.org/dist/xmlgraphics/fop/binaries/fop-2.6-bin.tar.gz \
    | tar -xz -C /opt/fop --strip-components=1 && \
    ln -s /opt/fop/fop /usr/local/bin/fop

# --------------------------------------------------------
# 5) PHP-FPM production settings
# --------------------------------------------------------
#  - Official php:fpm images store the default pool config in /usr/local/etc/php-fpm.d/www.conf
#  - We'll listen on port 9000 (the official default is often 9000 anyway).
RUN sed -i 's|^listen = .*|listen = 9000|' /usr/local/etc/php-fpm.d/www.conf

# Create a custom ini for “production” style error settings
RUN echo "display_errors = Off"                        >  /usr/local/etc/php/conf.d/atom.ini && \
    echo "log_errors = On"                             >> /usr/local/etc/php/conf.d/atom.ini && \
    echo "error_reporting = E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_STRICT" \
                                                        >> /usr/local/etc/php/conf.d/atom.ini

# --------------------------------------------------------
# 6) Copy AtoM init files
# --------------------------------------------------------
COPY init/atom-entrypoint.sh /usr/local/bin/atom-entrypoint.sh
COPY init/configure-memcached.sh /init/configure-memcached.sh
RUN chmod +x /init/configure-memcached.sh
COPY init/atom-2.9.0.tar.gz  /init/atom-2.9.0.tar.gz
RUN chmod +x /usr/local/bin/atom-entrypoint.sh

# --------------------------------------------------------
# 7) Working dir, expose FPM port, set entrypoint
# --------------------------------------------------------
WORKDIR /usr/share/nginx/atom
EXPOSE 9000

ENTRYPOINT ["atom-entrypoint.sh"]
CMD ["php-fpm", "--nodaemonize"]
