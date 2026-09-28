FROM php:8.5-fpm

ARG UID=1000
ARG GID=1000

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip tzdata \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql intl zip bcmath pcntl opcache

ENV TZ=America/Sao_Paulo
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

RUN groupadd -g ${GID} app && useradd -u ${UID} -g app -m app \
    && sed -i 's/^user = www-data/user = app/; s/^group = www-data/group = app/' /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html
USER app
