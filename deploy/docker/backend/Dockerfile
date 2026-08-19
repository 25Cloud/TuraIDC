# syntax=docker/dockerfile:1
# ============================================================================
# TuraIDC 后端镜像：PHP 8.3-FPM + Nginx + Supervisor
# Supervisor 统一托管：php-fpm、nginx、cron(schedule:run)、vnc:relay
# 构建上下文 = 仓库根目录（compose 中 context: ../..）
# ============================================================================

FROM php:8.3-fpm-bookworm AS base

# ---- 系统依赖 ----
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        cron \
        default-mysql-client \
        python3 \
        unzip \
        git \
        curl \
        libzip-dev \
        libicu-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libonig-dev \
        libxml2-dev \
        libcurl4-openssl-dev \
        libssl-dev \
        pkg-config \
    && rm -rf /var/lib/apt/lists/*

# ---- PHP 扩展 ----
# pdo_mysql: 数据库；redis: phpredis 缓存/分布式锁；
# pcntl: 任务超时强杀(SIGALRM)；intl/bcmath/gd/zip/exif/sockets: 常规生产依赖
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        opcache \
        exif \
        sockets \
    && pecl install redis \
    && docker-php-ext-enable redis

# ---- Composer（国内镜像已在 backend/composer.json 配置为腾讯云源）----
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /opt/backend-src

# ---- 应用代码（vendor/.env/storage 等由 .dockerignore 排除）----
# 代码放在 /opt/backend-src（镜像层），entrypoint 启动时拷贝到
# /var/www/backend（容器可写层）。这是 Docker Desktop Windows overlayfs
# 的 workaround：php-fpm fork 的 worker 读镜像层文件会 EPERM，
# 读可写层文件则正常。Linux 生产环境无此问题，但拷贝开销可忽略。
COPY backend/ /opt/backend-src/

# ---- 依赖安装（生产模式，跳过 dev）----
# --no-scripts：post-autoload-dump 会触发 package:discover，而本项目部分
# ServiceProvider 启动时查询 settings 表（需数据库）；构建期无 MySQL。
# packages.php 会在容器启动、MySQL 就绪后由 Laravel 自动重建。
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts

# ---- 运行时配置 ----
COPY deploy/docker/backend/php.ini /usr/local/etc/php/conf.d/zz-turaidc.ini
COPY deploy/docker/backend/nginx.conf /etc/nginx/sites-available/turaidc
RUN ln -sf /etc/nginx/sites-available/turaidc /etc/nginx/sites-enabled/turaidc \
    && rm -f /etc/nginx/sites-enabled/default

COPY deploy/docker/backend/supervisord.conf /etc/supervisor/conf.d/turaidc.conf
COPY deploy/docker/backend/entrypoint.sh /usr/local/bin/turaidc-entrypoint
RUN chmod +x /usr/local/bin/turaidc-entrypoint

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/turaidc-entrypoint"]
