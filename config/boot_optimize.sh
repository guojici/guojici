#!/bin/bash
# ============================================================================
#  guojici云 - 性能优化开机自启脚本
#  确保所有优化项在每次开机后自动生效
# ============================================================================

# ========== 颜色定义 ==========
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

log()  { echo "[$(date '+%H:%M:%S')] $1"; }
ok()   { echo -e "${GREEN}[$(date '+%H:%M:%S')] ✓ $1${NC}"; }
warn() { echo -e "${YELLOW}[$(date '+%H:%M:%S')] ⚠ $1${NC}"; }
err()  { echo -e "${RED}[$(date '+%H:%M:%S')] ✗ $1${NC}"; }

log "========== guojici云 性能优化自启 =========="

# 检测PHP版本
detect_php_version() {
    local VER=""
    # 优先检测FPM
    VER=$(ls /etc/php/*/fpm/pool.d/www.conf 2>/dev/null | head -1 | grep -oP '\d+\.\d+')
    if [ -z "$VER" ]; then
        VER=$(php -v 2>/dev/null | grep -oP 'PHP \K\d+\.\d+' | head -1)
    fi
    echo "$VER"
}

PHP_VER=$(detect_php_version)
log "检测到 PHP 版本: ${PHP_VER:-未知}"

# ========== 1. 确保服务开机自启 ==========
log "[1/8] 配置服务开机自启..."

SERVICES="nginx redis-server redis"
if [ -n "$PHP_VER" ]; then
    SERVICES="$SERVICES php${PHP_VER}-fpm"
else
    SERVICES="$SERVICES php-fpm"
fi
SERVICES="$SERVICES mysql mysqld mariadb"

for svc in $SERVICES; do
    if systemctl cat "$svc" &>/dev/null; then
        systemctl enable "$svc" 2>/dev/null && ok "$svc 已设置开机自启" || warn "$svc enable 失败"
    fi
done

# 如果有宝塔的PHP-FPM
for bt_php in /www/server/php/*/sbin/php-fpm; do
    bt_ver=$(echo "$bt_php" | grep -oP '\d+')
    if [ -n "$bt_ver" ]; then
        # 宝塔PHP服务名通常为 php-fpm-{版本}
        if systemctl cat "php-fpm-$bt_ver" &>/dev/null 2>&1; then
            systemctl enable "php-fpm-$bt_ver" 2>/dev/null && ok "php-fpm-$bt_ver 已设置开机自启"
        fi
    fi
done

# ========== 2. 启动 Redis（处理 libjemalloc 问题） ==========
log "[2/8] 启动 Redis..."

if ! redis-cli ping &>/dev/null; then
    # 尝试正常启动
    systemctl start redis-server 2>/dev/null || true
    sleep 2

    if ! redis-cli ping &>/dev/null; then
        warn "Redis 正常启动失败，尝试修复 libjemalloc 问题..."

        # 方法1: 临时禁用 jemalloc
        if [ -f /etc/default/redis-server ]; then
            sed -i 's/^LD_PRELOAD=.*/# &/' /etc/default/redis-server 2>/dev/null || true
        fi

        # 方法2: 创建 systemd override 禁用环境变量
        mkdir -p /etc/systemd/system/redis-server.service.d
        cat > /etc/systemd/system/redis-server.service.d/override.conf << 'EOF'
[Service]
Environment=LD_PRELOAD=
Environment=MALLOC_ARENA_MAX=2
LimitNOFILE=65535
Restart=always
RestartSec=3
EOF
        systemctl daemon-reload
        systemctl restart redis-server 2>/dev/null || true
        sleep 2

        # 方法3: 直接手动启动
        if ! redis-cli ping &>/dev/null; then
            warn "systemd 启动失败，使用直接启动方式..."
            redis-server --port 6379 --daemonize yes --save "" --appendonly no 2>/dev/null
            sleep 1
        fi
    fi
fi

if redis-cli ping &>/dev/null; then
    ok "Redis 运行中"
else
    err "Redis 启动失败"
fi

# ========== 3. 确保 OPcache 配置 ==========
log "[3/8] 检查 OPcache 配置..."

if [ -n "$PHP_VER" ]; then
    OPCACHE_INI_FPM="/etc/php/${PHP_VER}/fpm/conf.d/10-opcache.ini"
    OPCACHE_INI_CLI="/etc/php/${PHP_VER}/cli/conf.d/10-opcache.ini"

    ensure_opcache_ini() {
        local ini_file="$1"
        if [ -f "$ini_file" ]; then
            # 检查是否已有 zend_extension
            if ! grep -q 'zend_extension=opcache.so' "$ini_file" 2>/dev/null; then
                warn "$ini_file 缺少 zend_extension，修复中..."
            fi
        else
            warn "$ini_file 不存在，创建..."
        fi

        cat > "$ini_file" << 'OPCACHEEOF'
zend_extension=opcache.so
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=256
opcache.max_accelerated_files=10000
opcache.interned_strings_buffer=16
opcache.revalidate_freq=60
opcache.validate_timestamps=1
opcache.save_comments=0
opcache.optimization_level=0x7FFFBFFF
opcache.fast_shutdown=1
OPCACHEEOF
        ok "$ini_file 已配置"
    }

    ensure_opcache_ini "$OPCACHE_INI_FPM"
    ensure_opcache_ini "$OPCACHE_INI_CLI"

    # 宝塔PHP的OPcache配置
    for bt_ini in /www/server/php/*/etc/php.ini; do
        bt_ver=$(echo "$bt_ini" | grep -oP '\d+')
        # 只处理非当前版本（避免冲突）
        if [ -n "$bt_ver" ] && [ "$bt_ver" != "$PHP_VER" ]; then
            if grep -q '^opcache.enable' "$bt_ini" 2>/dev/null; then
                if ! grep -q '^opcache.enable=1' "$bt_ini" 2>/dev/null; then
                    sed -i 's/^opcache.enable.*/opcache.enable=1/' "$bt_ini"
                    sed -i 's/^opcache.memory_consumption.*/opcache.memory_consumption=256/' "$bt_ini"
                    sed -i 's/^opcache.max_accelerated_files.*/opcache.max_accelerated_files=10000/' "$bt_ini"
                    ok "宝塔 PHP $bt_ver OPcache 已启用"
                fi
            fi
        fi
    done
fi

# ========== 4. 确保 PHP-FPM 进程池优化 ==========
log "[4/8] 检查 PHP-FPM 进程池配置..."

if [ -n "$PHP_VER" ]; then
    FPM_CONF="/etc/php/${PHP_VER}/fpm/pool.d/www.conf"
    if [ -f "$FPM_CONF" ]; then
        # 检测CPU核心数
        CPU_CORES=$(grep -c ^processor /proc/cpuinfo 2>/dev/null || echo 4)
        MAX_CHILDREN=$((CPU_CORES * 3))

        # 确保是 static 模式
        if ! grep -q '^pm = static' "$FPM_CONF" 2>/dev/null; then
            sed -i 's/^pm = .*/pm = static/' "$FPM_CONF"
        fi

        # 确保进程数足够
        CURRENT_MAX=$(grep '^pm.max_children' "$FPM_CONF" 2>/dev/null | awk '{print $3}')
        if [ -z "$CURRENT_MAX" ] || [ "$CURRENT_MAX" -lt "$((CPU_CORES * 2))" ] 2>/dev/null; then
            sed -i "s/^pm.max_children = .*/pm.max_children = ${MAX_CHILDREN}/" "$FPM_CONF"
        fi

        # 确保 max_requests
        if ! grep -q '^pm.max_requests' "$FPM_CONF" 2>/dev/null; then
            echo "pm.max_requests = 1000" >> "$FPM_CONF"
        else
            sed -i 's/^pm.max_requests = .*/pm.max_requests = 1000/' "$FPM_CONF"
        fi

        # 慢日志
        if ! grep -q '^slowlog' "$FPM_CONF" 2>/dev/null; then
            echo "slowlog = /var/log/php-fpm/slow.log" >> "$FPM_CONF"
            echo "request_slowlog_timeout = 2" >> "$FPM_CONF"
        fi

        mkdir -p /var/log/php-fpm
        ok "PHP-FPM 进程池: static, max_children=${MAX_CHILDREN}, max_requests=1000"
    fi
fi

# ========== 5. 确保 php.ini 生产参数 ==========
log "[5/8] 检查 php.ini 生产参数..."

set_php_ini() {
    local ini_path="$1"
    if [ ! -f "$ini_path" ]; then return 1; fi

    # 设置或追加
    local key="$1_val"
    local val="$2_val"

    _set() {
        local k="$1" v="$2" f="$3"
        if grep -qE "^\s*${k}\s*=" "$f" 2>/dev/null; then
            sed -i -E "s|^\s*${k}\s*=.*|${k} = ${v}|" "$f"
        elif grep -qE "^\s*;\s*${k}\s*=" "$f" 2>/dev/null; then
            sed -i -E "s|^\s*;\s*${k}\s*=.*|${k} = ${v}|" "$f"
        else
            echo "${k} = ${v}" >> "$f"
        fi
    }

    _set "memory_limit" "256M" "$ini_path"
    _set "max_execution_time" "300" "$ini_path"
    _set "upload_max_filesize" "100M" "$ini_path"
    _set "post_max_size" "100M" "$ini_path"
    _set "max_input_time" "300" "$ini_path"
    _set "display_errors" "Off" "$ini_path"
    _set "opcache.enable" "1" "$ini_path"
}

if [ -n "$PHP_VER" ]; then
    set_php_ini "/etc/php/${PHP_VER}/fpm/php.ini"
    ok "php.ini (fpm) 生产参数已确保"
fi

# ========== 6. 确保 Nginx gzip ==========
log "[6/8] 检查 Nginx gzip..."

NGINX_CONF=""
for f in /etc/nginx/nginx.conf /usr/local/nginx/conf/nginx.conf /www/server/nginx/conf/nginx.conf; do
    if [ -f "$f" ]; then NGINX_CONF="$f"; break; fi
done

if [ -n "$NGINX_CONF" ]; then
    if ! grep -qE '^\s*gzip\s+on' "$NGINX_CONF" 2>/dev/null; then
        # 在 http {} 块中添加 gzip 配置
        sed -i '/^http\s*{/a\    gzip on;\n    gzip_min_length 1k;\n    gzip_comp_level 6;\n    gzip_types text/plain text/css text/javascript application/json application/javascript application/xml;\n    gzip_vary on;\n    gzip_proxied any;' "$NGINX_CONF"
        ok "Nginx gzip 已添加"
    else
        ok "Nginx gzip 已开启"
    fi
fi

# ========== 7. 确保 MySQL 调优 ==========
log "[7/8] 检查 MySQL 配置..."

# 确保 innodb_buffer_pool_size 合理
MYCNF="/etc/mysql/my.cnf"
if [ -f "$MYCNF" ]; then
    if ! grep -q 'innodb_buffer_pool_size' "$MYCNF" 2>/dev/null; then
        # 计算合理的buffer pool（内存的60%）
        TOTAL_MEM_KB=$(grep MemTotal /proc/meminfo | awk '{print $2}')
        BUFFER_POOL=$((TOTAL_MEM_KB * 60 / 100 / 1024))M

        # 追加到mysqld段
        if grep -q '\[mysqld\]' "$MYCNF"; then
            sed -i "/\[mysqld\]/a innodb_buffer_pool_size = ${BUFFER_POOL}\ninnodb_flush_log_at_trx_commit = 2\ninnodb_log_file_size = 256M\nslow_query_log = 1\nslow_query_log_file = /var/log/mysql/slow.log\nlong_query_time = 2" "$MYCNF"
            ok "MySQL innodb_buffer_pool_size=${BUFFER_POOL} 已添加"
        fi
    else
        ok "MySQL 配置已存在"
    fi
fi

# 宝塔的MySQL配置
BT_MYCNF="/www/server/mysql/my.cnf"
if [ -f "$BT_MYCNF" ]; then
    if ! grep -q 'innodb_buffer_pool_size' "$BT_MYCNF" 2>/dev/null; then
        TOTAL_MEM_KB=$(grep MemTotal /proc/meminfo | awk '{print $2}')
        BUFFER_POOL=$((TOTAL_MEM_KB * 60 / 100 / 1024))M
        if grep -q '\[mysqld\]' "$BT_MYCNF"; then
            sed -i "/\[mysqld\]/a innodb_buffer_pool_size = ${BUFFER_POOL}\ninnodb_flush_log_at_trx_commit = 2\nslow_query_log = 1\nslow_query_log_file = /www/server/mysql/slow.log\nlong_query_time = 2" "$BT_MYCNF"
            ok "宝塔 MySQL innodb_buffer_pool_size=${BUFFER_POOL} 已添加"
        fi
    else
        ok "宝塔 MySQL 配置已存在"
    fi
fi

# ========== 8. 启动/重启服务 ==========
log "[8/8] 启动/重启服务..."

# MySQL
systemctl start mysql 2>/dev/null || systemctl start mysqld 2>/dev/null || systemctl start mariadb 2>/dev/null || true
ok "MySQL 已启动"

# PHP-FPM
if [ -n "$PHP_VER" ]; then
    systemctl restart "php${PHP_VER}-fpm" 2>/dev/null || true
    ok "PHP ${PHP_VER}-FPM 已重启"
fi

# 宝塔PHP-FPM
for bt_php in /www/server/php/*/sbin/php-fpm; do
    bt_ver=$(echo "$bt_php" | grep -oP '\d+')
    if [ -n "$bt_ver" ]; then
        systemctl restart "php-fpm-$bt_ver" 2>/dev/null || true
    fi
done

# Nginx
systemctl restart nginx 2>/dev/null || true
ok "Nginx 已重启"

# 验证
sleep 2
log ""
log "========== 验证结果 =========="

# OPcache
if php -m 2>/dev/null | grep -qi 'Zend OPcache'; then
    ok "OPcache: 运行中"
else
    err "OPcache: 未加载"
fi

# Redis
if redis-cli ping 2>/dev/null | grep -q PONG; then
    ok "Redis: 运行中"
else
    err "Redis: 未运行"
fi

# APCu
if php -m 2>/dev/null | grep -qi 'apcu'; then
    ok "APCu: 运行中"
else
    warn "APCu: 未加载（CLI，FPM可能已加载）"
fi

# Nginx
if systemctl is-active --quiet nginx 2>/dev/null; then
    ok "Nginx: 运行中"
else
    err "Nginx: 未运行"
fi

# PHP-FPM
if [ -n "$PHP_VER" ]; then
    if systemctl is-active --quiet "php${PHP_VER}-fpm" 2>/dev/null; then
        ok "PHP ${PHP_VER}-FPM: 运行中"
    else
        err "PHP ${PHP_VER}-FPM: 未运行"
    fi
fi

# MySQL
if systemctl is-active --quiet mysql 2>/dev/null || systemctl is-active --quiet mysqld 2>/dev/null || systemctl is-active --quiet mariadb 2>/dev/null; then
    ok "MySQL: 运行中"
else
    err "MySQL: 未运行"
fi

log ""
log "========== 性能优化自启完成 =========="