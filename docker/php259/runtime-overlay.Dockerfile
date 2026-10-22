ARG BASE
FROM ${BASE} AS builder
USER root
RUN apk add --no-cache autoconf build-base bison re2c pkgconf argon2-dev curl-dev libsodium-dev libxml2-dev oniguruma-dev openssl-dev readline-dev sqlite-dev zlib-dev gettext-dev icu-dev libxslt-dev libzip-dev openldap-dev linux-headers gnu-libiconv-dev
RUN rm -f /usr/include/iconv.h
COPY php-8.3.36.tar.xz /tmp/php-8.3.36.tar.xz
RUN echo 'dd54693aa334c5c4d72e9aee96b082c2473750a18a6c1d105789c93a3b1692fd  /tmp/php-8.3.36.tar.xz' | sha256sum -c - \
 && mkdir /tmp/php-build && tar -xJf /tmp/php-8.3.36.tar.xz -C /tmp/php-build --strip-components=1
WORKDIR /tmp/php-build
RUN export CFLAGS="$PHP_CFLAGS" CPPFLAGS="$PHP_CPPFLAGS" LDFLAGS="$PHP_LDFLAGS" \
 && ./configure $(php-config --configure-options) --enable-calendar=shared --with-gettext=shared --enable-intl=shared --with-mysqli=shared,mysqlnd --enable-pcntl=shared --with-pdo-mysql=shared,mysqlnd --enable-sockets=shared --with-xsl=shared --with-zip=shared --with-ldap=shared \
 && make -j2 \
 && make install INSTALL_ROOT=/tmp/php-install
FROM ${BASE}
COPY --from=builder /tmp/php-install/usr/local/bin/ /usr/local/bin/
COPY --from=builder /tmp/php-install/usr/local/sbin/ /usr/local/sbin/
COPY --from=builder /tmp/php-install/usr/local/include/php/ /usr/local/include/php/
COPY --from=builder /tmp/php-install/usr/local/lib/php/build/ /usr/local/lib/php/build/
COPY --from=builder /tmp/php-install/usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY php-8.3.36.tar.xz /usr/src/php.tar.xz
ENV PHP_VERSION=8.3.36 PHP_SHA256=dd54693aa334c5c4d72e9aee96b082c2473750a18a6c1d105789c93a3b1692fd PHP_URL=https://www.php.net/distributions/php-8.3.36.tar.xz PHP_ASC_URL=https://www.php.net/distributions/php-8.3.36.tar.xz.asc
LABEL ch.unil.ops.php-remediation="259" ch.unil.ops.php-source-sha256="dd54693aa334c5c4d72e9aee96b082c2473750a18a6c1d105789c93a3b1692fd"
RUN php -r 'exit(PHP_VERSION === "8.3.36" ? 0 : 1);' && php-fpm -v
