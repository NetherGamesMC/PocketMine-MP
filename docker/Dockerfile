#Prebuilt PHP needs glibc 2.38+
FROM ubuntu:resolute AS build-php
ARG PHP_BINARY_URL="https://github.com/NetherGamesMC/php-build-scripts/releases/download/pm5-php-8.5-latest/PHP-8.5-Linux-x86_64-PM5.tar.gz"

WORKDIR /opt/pocketmine
ADD ${PHP_BINARY_URL} /tmp/php.tar.gz
RUN tar -xzf /tmp/php.tar.gz -C /opt/pocketmine && rm /tmp/php.tar.gz
#php.ini's extension_dir points at the CI build path
RUN EXTENSION_DIR=$(echo /opt/pocketmine/bin/php7/lib/php/extensions/no-debug-zts-*) && \
	test -d "$EXTENSION_DIR" && \
	sed -i "s|^extension_dir=.*|extension_dir=$EXTENSION_DIR|" /opt/pocketmine/bin/php7/bin/php.ini

FROM ubuntu:resolute AS build-pocketmine
ARG GIT_HASH=""
RUN test ! -z ${GIT_HASH} || (echo "Missing build-arg GIT_HASH" && false)

RUN apt-get update && apt-get install --no-install-recommends -y curl ca-certificates 7zip
RUN mkdir /opt/pocketmine
COPY --from=build-php /opt/pocketmine/bin /opt/pocketmine/bin
RUN ln -s /opt/pocketmine/bin/php7/bin/php /usr/bin/php
RUN curl -L https://getcomposer.org/installer | php
RUN mv composer.phar /usr/bin/composer

COPY ./build/server-phar.php ./build/server-phar-stub.php /opt/pocketmine/repo/build/
COPY ./resources /opt/pocketmine/repo/resources
COPY ./src /opt/pocketmine/repo/src
COPY ./generated /opt/pocketmine/repo/generated
COPY ./composer.json ./composer.lock /opt/pocketmine/repo/

WORKDIR /opt/pocketmine/repo
RUN composer install --classmap-authoritative --no-dev --prefer-dist
RUN php vendor/nethergamesmc/bedrock-data/.minify_json.php
RUN php -dphar.readonly=0 ./build/server-phar.php --git ${GIT_HASH}
# Just to make sure the build script didn't false-positive-exit
RUN test -f /opt/pocketmine/repo/PocketMine-MP.phar

# Only the phar, so CI can export it with `--target phar --output type=local,dest=<dir>`
FROM scratch AS phar
COPY --from=build-pocketmine /opt/pocketmine/repo/PocketMine-MP.phar /PocketMine-MP.phar

FROM ubuntu:resolute
LABEL maintainer="PMMP Team <team@pmmp.io>"

RUN apt-get update && apt-get install --no-install-recommends -y ca-certificates wget

#Frees uid/gid 1000
RUN userdel ubuntu && rm -rf /home/ubuntu
RUN groupadd -g 1000 pocketmine
RUN useradd -r --no-create-home -u 1000 -g pocketmine pocketmine

WORKDIR /opt/pocketmine
COPY --from=build-php /opt/pocketmine/bin /opt/pocketmine/bin
RUN ln -s /opt/pocketmine/bin/php7/bin/php /usr/bin/php
COPY --from=build-pocketmine /opt/pocketmine/repo/PocketMine-MP.phar PocketMine-MP.phar
ADD ./docker/start.sh /usr/bin/start-pocketmine

RUN mkdir /plugins /data
RUN chown 1000:1000 /plugins /data . -R
RUN chmod o+x /usr/bin/php

USER pocketmine

ENV TERM=xterm

EXPOSE 19132/tcp
EXPOSE 19132/udp
EXPOSE 19133/udp

VOLUME ["/data", "/plugins"]

CMD ["start-pocketmine"]
