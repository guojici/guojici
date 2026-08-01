#!/bin/bash
# guojici云性能优化一键脚本
# 适用系统：Ubuntu 20.04+ / Debian 10+
# 功能：OPcache + PHP-FPM调优 + Nginx优化 + Redis安装

set -e

# 颜色
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${GREEN}=== guojici云性能优化一键脚本 ===${NC}"
echo ""

# 检测PHP版本
PHP_VERSION=$(php -v | head -1 | grep -oP 'PHP \K[0-9]+\.[0-9]+')
echo -e "检测到PHP版本: ${GREEN}$PHP_VERSION${NC}"

# 检测PHP-FPM服务
if systemctl is-active --quiet php${PHP_VERSION}-fpm; then
    echo -e "PHP-FPM状态: ${GREEN}运行中${NC}"
else
    echo -e "PHP-FPM状态: ${YELLOW}未运行或路径不同${NC}"
fi

# 检测CPU核心数
CPU_CORES=$(nproc)
echo -e "CPU核心数: ${GREEN}$CPU_CORES${NC}"

echo ""
echo "开始优化..."
echo ""

# ========== 1. 安装OPcache ==========
echo -e "${GREEN}[1/6] 安装/启用 OPcache${NC}"
if php -m | grep -q 'Zend OPcache'; then
    echo -e "  OPcache 已安装，优化配置..."
else
    echo "  安装 OPcache..."
    apt-get install -y php${PHP_VERSION}-opcache
fi

# 写入OPcache配置
OPCACHE_INI="/etc/php/${PHP_VERSION}/fpm/conf.d/10-opcache.ini"
cat > "$OPCACHE_INI" << 'EOF'
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
EOF
echo -e "  ✓ OPcache 配置已写入"

# ========== 2. PHP-FPM进程池调优 ==========
echo -e "${GREEN}[2/6] PHP-FPM 进程池调优${NC}"

FPM_CONF="/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"
if [ -f "$FPM_CONF" ]; then
    # 计算建议值
    MAX_CHILDREN=$((CPU_CORES * 3))
    START_SERVERS=$((CPU_CORES * 2))
    MIN_SPARE=$CPU_CORES
    MAX_SPARE=$((CPU_CORES * 2 + CPU_CORES))
    
    # 备份原配置
    cp "$FPM_CONF" "${FPM_CONF}.bak.$(date +%Y%m%d%H%M%S)"
    
    # 修改配置
    sed -i "s/^pm = .*/pm = static/" "$FPM_CONF"
    sed -i "s/^pm.max_children = .*/pm.max_children = $MAX_CHILDREN/" "$FPM_CONF"
    sed -i "s/^pm.start_servers = .*/pm.start_servers = $START_SERVERS/" "$FPM_CONF"
    sed -i "s/^pm.min_spare_servers = .*/pm.min_spare_servers = $MIN_SPARE/" "$FPM_CONF"
    sed -i "s/^pm.max_spare_servers = .*/pm.max_spare_servers = $MAX_SPARE/" "$FPM_CONF"
    sed -i "s/^;pm.max_requests = .*/pm.max_requests = 1000/" "$FPM_CONF"
    sed -i "s/^pm.max_requests = .*/pm.max_requests = 1000/" "$FPM_CONF"
    
    # 慢请求日志
    sed -i "s|^;slowlog = .*|slowlog = /var/log/php-fpm/slow.log|" "$FPM_CONF"
    sed -i "s|^slowlog = .*|slowlog = /var/log/php-fpm/slow.log|" "$FPM_CONF"
    sed -i "s/^;request_slowlog_timeout = .*/request_slowlog_timeout = 2/" "$FPM_CONF"
    sed -i "s/^request_slowlog_timeout = .*/request_slowlog_timeout = 2/" "$FPM_CONF"
    
    mkdir -p /var/log/php-fpm
    
    echo -e "  ✓ FPM配置已优化"
    echo -e "    pm = static"
    echo -e "    pm.max_children = $MAX_CHILDREN"
    echo -e "    pm.start_servers = $START_SERVERS"
else
    echo -e "  ${YELLOW}未找到www.conf，跳过${NC}"
fi

# ========== 3. 安装Redis ==========
echo -e "${GREEN}[3/6] 安装 Redis 缓存${NC}"
if command -v redis-server &> /dev/null; then
    echo -e "  Redis 已安装"
else
    echo "  安装 Redis..."
    apt-get install -y redis-server
    systemctl enable redis-server
    systemctl start redis-server
fi

# 安装PHP Redis扩展
if php -m | grep -q 'redis'; then
    echo -e "  PHP Redis扩展 已安装"
else
    echo "  安装PHP Redis扩展..."
    apt-get install -y php${PHP_VERSION}-redis
fi

# ========== 4. 安装APCu ==========
echo -e "${GREEN}[4/6] 安装 APCu 本地缓存${NC}"
if php -m | grep -q 'apcu'; then
    echo -e "  APCu 已安装"
else
    echo "  安装 APCu..."
    apt-get install -y php${PHP_VERSION}-apcu
fi

# ========== 5. Nginx优化 ==========
echo -e "${GREEN}[5/6] Nginx gzip & 静态资源优化${NC}"

# 检测Nginx
if command -v nginx &> /dev/null; then
    NGINX_CONF="/etc/nginx/nginx.conf"
    if [ -f "$NGINX_CONF" ]; then
        # 检查gzip是否已开启
        if grep -q "^[[:space:]]*gzip on;" "$NGINX_CONF"; then
            echo -e "  gzip 已开启"
        else
            echo "  开启 gzip..."
            # 在http块中添加gzip配置
            sed -i '/^http {/a\    gzip on;\n    gzip_vary on;\n    gzip_min_length 1024;\n    gzip_types text/plain text/css text/xml text/javascript application/javascript application/xml+rss application/json;\n    gzip_comp_level 6;' "$NGINX_CONF"
            echo -e "  ✓ gzip 已开启"
        fi
    fi
else
    echo -e "  ${YELLOW}未检测到Nginx，跳过${NC}"
fi

# ========== 6. 重启服务 ==========
echo -e "${GREEN}[6/6] 重启服务使配置生效${NC}"

if systemctl is-active --quiet php${PHP_VERSION}-fpm; then
    systemctl restart php${PHP_VERSION}-fpm
    echo -e "  ✓ PHP-FPM 已重启"
fi

if command -v nginx &> /dev/null; then
    nginx -t
    if systemctl is-active --quiet nginx; then
        systemctl reload nginx
        echo -e "  ✓ Nginx 已重载"
    else
        systemctl start nginx
        echo -e "  ✓ Nginx 已启动"
    fi
fi

if systemctl is-active --quiet redis-server; then
    echo -e "  ✓ Redis 运行中"
fi

echo ""
echo -e "${GREEN}=== 优化完成 ===${NC}"
echo ""
echo "优化效果："
echo "  1. OPcache - PHP脚本编译缓存，性能提升50%+"
echo "  2. PHP-FPM进程池调优 - 并发能力提升2-4倍"
echo "  3. Redis缓存 - 减少数据库压力，热点数据毫秒级响应"
echo "  4. APCu - 本地内存缓存，比Redis更快"
echo "  5. Nginx gzip - 传输体积减少60%+"
echo ""
echo "注意事项："
echo "  - 生产环境稳定后可将 opcache.revalidate_freq 改为 0（完全不检测文件变更）"
echo "  - 建议配合数据库优化（索引、慢查询）一起使用"
echo "  - AI助手建议使用独立FPM进程池，避免长连接阻塞正常请求"
echo ""
echo "运行 php /www/wwwroot/192.168.3.2_4561/data/optimize_check.php 查看当前性能状态"