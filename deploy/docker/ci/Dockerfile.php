# 本地 Docker CI：backend job 运行镜像（对齐 .github/workflows/ci.yml 的
# shivammathur/setup-php 核心能力：php 8.3 + composer:v2）。
#
# 说明：php:8.3-cli 官方镜像已内置 mbstring/openssl/mysqlnd/pdo，
# 足以支撑 composer validate/install、artisan package:discover、Pint 与 PHPStan；
# 无需编译额外扩展，避免 redis(pecl) 等无关开销。
#
# 构建在网络受限环境时请使用：docker build --network=host ...
FROM php:8.3-cli

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip; \
    # pdo_mysql：backend 启动时 AppServiceProvider 会连库探测 settings 表（composer install 的 post-autoload-dump 阶段）
    docker-php-ext-install pdo_mysql; \
    rm -rf /var/lib/apt/lists/*

# composer v2（与 CI tools: composer:v2 对齐）
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_CACHE_DIR=/tmp/composer-cache

WORKDIR /workspace
CMD ["php", "-v"]