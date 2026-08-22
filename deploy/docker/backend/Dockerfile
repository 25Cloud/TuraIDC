# syntax=docker/dockerfile:1
# ============================================================================
# TuraIDC 后端镜像：PHP 8.3-FPM + Nginx + Supervisor
# Supervisor 统一托管：php-fpm、nginx、cron(schedule:run)、vnc:relay
# 构建上下文 = 仓库根目录（compose 中 context: ../..）
# ============================================================================

FROM php:8.3-fpm-bookworm AS base

# ---- 系统依赖（运行时保留的部分）----
# 这里只装最终镜像真正需要的包：nginx/supervisor/cron 由 Supervisor 托管，
# default-mysql-client 供 entrypoint 探活与 MySqlSchemaState 加载 schema dump 使用，
# python3 供 install_db.py 初始化空库。git/unzip 保留给 composer（lock 变化时
# 可能需要源码安装），curl 供健康检查。
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        cron \
        default-mysql-client \
        python3 \
        unzip \
        git \
        curl \
    # PHP 扩展在运行时链接的共享库。必须在这一层显式安装（而非依赖 *-dev 包
    # 顺带带入），否则下一层 purge 掉 *-dev 时会把它们当孤儿依赖一并删除，
    # 表现为 gd/zip 等扩展在容器启动时报 "cannot open shared object file"。
    # 清单由实测导出：ldd 各扩展 .so → dpkg-query 反查所属包。
    # libc6/libssl3/zlib1g/libbz2/libsodium/libstdc++/libgcc 属基础必备包，无需列出。
        libpng16-16 \
        libjpeg62-turbo \
        libfreetype6 \
        libbrotli1 \
        libzip4 \
        libicu72 \
    && rm -rf /var/lib/apt/lists/*

# ---- PHP 扩展 ----
# pdo_mysql: 数据库；redis: phpredis 缓存/分布式锁；
# pcntl: 任务超时强杀(SIGALRM)；intl/bcmath/gd/zip/exif/sockets: 常规生产依赖
#
# *-dev 头文件与 pkg-config 只在编译扩展时需要，编译完就是死重量。此前它们和
# 运行时包装在同一层且从不清理，永久留在最终镜像里。
# 清理必须与安装同处一个 RUN 层——分层文件系统是叠加的，在后续层里 purge
# 不会回收前面层已占的空间。
# 做法：先记下当前的 manual 标记（含上一层显式安装的运行时库），装构建依赖、
# 编译扩展，然后把 manual 列表还原并 purge --auto-remove——构建依赖及其私有
# 依赖被清掉，上一层显式声明的运行时库因仍在 manual 列表中而保留。
# 最后做一次扩展自检：purge 若误删运行时库，构建立即失败，不会把坏镜像发出去。
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
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
    ; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        opcache \
        exif \
        sockets \
    ; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    pecl clear-cache; \
    apt-mark auto '.*' > /dev/null; \
    [ -z "$savedAptMark" ] || apt-mark manual $savedAptMark > /dev/null; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    php -r '$need = ["pdo_mysql","pcntl","bcmath","gd","zip","intl","exif","sockets","redis","mbstring","curl","openssl","xml"]; $bad = array_values(array_filter($need, fn ($e) => ! extension_loaded($e))); if ($bad !== []) { fwrite(STDERR, "构建自检失败：扩展未加载 ".implode(", ", $bad)."\n"); exit(1); } $gd = gd_info(); if (empty($gd["FreeType Support"]) || empty($gd["JPEG Support"])) { fwrite(STDERR, "构建自检失败：gd 缺少 FreeType/JPEG 支持\n"); exit(1); } echo "扩展自检通过\n";' \
    || { \
        # 自检失败时把「哪个扩展缺哪个 .so」直接打出来。
        # 上面只报扩展名，而根因总是「第一层的运行时库清单漏了某个包」——
        # 缺了这一步就只能靠 buildkit 日志里 PHP 启动告警去猜，而那段常被截断。
        # 结果按「补进第一层显式清单」的口径直接可用：dpkg -S <缺失的 .so> 即得包名。
        echo '===== 自检失败：以下扩展存在未解析的动态库依赖 =====' >&2; \
        for so in /usr/local/lib/php/extensions/*/*.so; do \
            [ -e "$so" ] || continue; \
            missing="$(ldd "$so" 2>/dev/null | grep 'not found' || true)"; \
            [ -n "$missing" ] || continue; \
            echo "--- $so" >&2; \
            echo "$missing" >&2; \
        done; \
        echo '（若上面为空，则扩展未加载与共享库无关，请检查 docker-php-ext-enable 与 php.ini）' >&2; \
        exit 1; \
    }

# ---- Composer（国内镜像已在 backend/composer.json 配置为腾讯云源）----
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /opt/backend-src

# ---- 依赖安装（生产模式，跳过 dev）----
# 先只拷清单再装依赖：依赖层的缓存键此后只取决于 composer.json/lock，
# 改一行业务代码不会再让这一层（约 57 MB + 全部网络下载）失效重跑。
# 此前是先 COPY backend/ 再 install，任何代码变动都会连带重装依赖。
COPY backend/composer.json backend/composer.lock /opt/backend-src/

# --no-scripts：post-autoload-dump 会触发 package:discover，而本项目部分
# ServiceProvider 启动时查询 settings 表（需数据库）；构建期无 MySQL。
# packages.php 会在容器启动、MySQL 就绪后由 Laravel 自动重建。
# --no-autoloader：此时业务代码还没拷进来，autoload 类映射要等代码到位后再生成。
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

# ---- 应用代码（vendor/.env/storage/tests 等由 .dockerignore 排除）----
# 代码放在 /opt/backend-src（镜像层），entrypoint 启动时拷贝到
# /var/www/backend（容器可写层）。这是 Docker Desktop Windows overlayfs
# 的 workaround：php-fpm fork 的 worker 读镜像层文件会 EPERM，
# 读可写层文件则正常。Linux 生产环境无此问题，但拷贝开销可忽略。
# 上一层装出的 vendor/ 不会被这次 COPY 破坏：.dockerignore 已排除 **/vendor，
# 它不在构建上下文里。
COPY backend/ /opt/backend-src/

# 代码到位后再生成优化过的 autoload 类映射（秒级，不触网）
RUN composer dump-autoload --no-dev --optimize --no-scripts

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
