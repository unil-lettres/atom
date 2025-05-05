# Use the official PHP 8.3 FPM base image
FROM php:8.3-fpm-bookworm

ENV DEBIAN_FRONTEND=noninteractive

# --------------------------------------------------------
# 1) Install system libraries, PHP deps, Node & LESS
# --------------------------------------------------------
RUN set -eux; \
    apt-get update -qq && \
    apt-get install -y -qq --no-install-recommends \
      build-essential pkg-config \
      libjpeg62-turbo-dev libpng-dev libtiff-dev libwebp-dev \
      libxslt1-dev libpcre2-dev libmemcached-dev libzstd-dev \
      libssl-dev zlib1g-dev curl unzip netcat-openbsd \
      mariadb-client ghostscript poppler-utils ffmpeg rsync grep git \
      nodejs npm && \
    npm install -g less@3.13.1 && \
    pecl install apcu memcache && \
    docker-php-ext-enable apcu memcache && \
    docker-php-ext-install xsl pdo_mysql calendar && \
    apt-get purge -y --auto-remove && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

# --------------------------------------------------------
# 2) Install ImageMagick and FOP as before
# --------------------------------------------------------
COPY init/ImageMagick-6.9.11-60.tar.xz /tmp/
RUN cd /tmp && \
    tar -xf ImageMagick-6.9.11-60.tar.xz && \
    cd ImageMagick-6.9.11-60 && \
    ./configure --disable-dependency-tracking && \
    make -j"$(nproc)" && \
    make install && \
    ldconfig && \
    cd .. && rm -rf ImageMagick-6.9.11-60*

RUN mkdir -p /opt/fop && \
    curl -L https://archive.apache.org/dist/xmlgraphics/fop/binaries/fop-2.6-bin.tar.gz \
      | tar -xz -C /opt/fop --strip-components=1 && \
    ln -s /opt/fop/fop /usr/local/bin/fop

# --------------------------------------------------------
# 3) PHP-FPM tweaks & entrypoint scripts
# --------------------------------------------------------
RUN sed -i 's|^listen = .*|listen = 9000|' /usr/local/etc/php-fpm.d/www.conf
RUN printf "display_errors = Off\nlog_errors = On\nerror_reporting = E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_STRICT\n" \
    > /usr/local/etc/php/conf.d/atom.ini

COPY init/atom-entrypoint.sh /usr/local/bin/atom-entrypoint.sh
COPY init/configure-memcached.sh  /init/configure-memcached.sh
COPY init/atom-2.9.0.tar.gz       /init/atom-2.9.0.tar.gz
RUN chmod +x /usr/local/bin/atom-entrypoint.sh /init/configure-memcached.sh

# --------------------------------------------------------
# 4) Extract and configure AtoM
# --------------------------------------------------------
WORKDIR /usr/share/nginx/atom
RUN mkdir -p /usr/share/nginx && \
    tar -xf /init/atom-2.9.0.tar.gz -C /usr/share/nginx && \
    mv /usr/share/nginx/atom-2.9.0/* /usr/share/nginx/atom/ && \
    rm -rf /usr/share/nginx/atom-2.9.0

# (Your existing entrypoint logic will merge plugins, fix perms, etc.)

# --------------------------------------------------------
# 5) Bake in and compile the UNIL Lettres theme
# --------------------------------------------------------
# Copy the theme source (with its config/class.php already in place)
COPY plugins/arLettresPlugin /usr/share/nginx/atom/plugins/arLettresPlugin

# Now compile its LESS _inside_ the PHP image so imports resolve
RUN set -eux; \
    cd /usr/share/nginx/atom/plugins/arLettresPlugin && \
    lessc --compress --rewrite-urls=all css/main.less > css/min.css

# --------------------------------------------------------
# 6) Finalize
# --------------------------------------------------------
WORKDIR /usr/share/nginx/atom
EXPOSE 9000
ENTRYPOINT ["atom-entrypoint.sh"]
CMD ["php-fpm", "--nodaemonize"]
