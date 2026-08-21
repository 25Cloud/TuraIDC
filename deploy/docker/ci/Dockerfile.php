# 本地 Docker CI：backend job 运行镜像（对齐 .github/workflows/ci.yml 的
# shivammathur/setup-php 核心能力：php 8.3 + composer:v2）。
#
# 说明：gh.yealqp.cn/php:8.3-cli 基础镜像已内置 mbstring/openssl/mysqlnd/pdo，
# 足以支撑 composer validate/install、artisan package:discover、Pint 与 PHPStan；
# 无需编译额外扩展，避免 redis(pecl) 等无关开销。
#
# 构建在网络受限环境时请使用：docker build --network=host ...
FROM gh.yealqp.cn/php:8.3-cli

# 换用国内 Debian 镜像源（路径与官方 deb.debian.org 一一对应），加快 apt
RUN set -eux; \
    sed -i 's|deb.debian.org|mirrors.tuna.tsinghua.edu.cn|g' /etc/apt/sources.list.d/debian.sources; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip; \
    # pdo_mysql：backend 启动时 AppServiceProvider 会连库探测 settings 表（composer install 的 post-autoload-dump 阶段）
    docker-php-ext-install pdo_mysql; \
    rm -rf /var/lib/apt/lists/*

# composer v2（与 CI tools: composer:v2 对齐）
COPY --from=gh.yealqp.cn/library/composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_CACHE_DIR=/tmp/composer-cache

WORKDIR /workspace
CMD ["php", "-v"]