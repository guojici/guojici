#!/bin/bash
# ============================================================================
#  guojici云 - 卸载脚本
#  支持三种模式：控制台 / KVM节点 / 全量
# ============================================================================

# ========== 颜色定义 ==========
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

# ========== 输出函数 ==========
print_info()  { echo -e "${GREEN}[INFO]${NC} $1"; }
print_warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
print_error() { echo -e "${RED}[ERROR]${NC} $1"; }
print_step()  { echo -e "${CYAN}[STEP]${NC} $1"; }

read_input() {
    local prompt="$1"
    local var_name="$2"
    local silent="${3:-}"

    if [[ "$0" == "bash" ]] || [[ "$0" == "-bash" ]] || [[ "$0" == "/bin/bash" ]] || [[ "$0" == "/usr/bin/bash" ]]; then
        if [ -n "$silent" ]; then
            read -s -p "$prompt" "$var_name" < /dev/tty
        else
            read -p "$prompt" "$var_name" < /dev/tty
        fi
    else
        if [ -n "$silent" ]; then
            read -s -p "$prompt" "$var_name"
        else
            read -p "$prompt" "$var_name"
        fi
    fi
}

# ========== 检查root权限 ==========
check_root() {
    if [[ $EUID -ne 0 ]]; then
        print_error "必须以root用户运行此脚本"
        exit 1
    fi
}

# ============================================================================
#  卸载函数
# ============================================================================

# 停止服务
stop_services() {
    print_step "停止相关服务..."

    # 停止websockify
    if [ -f /tmp/websockify.pid ]; then
        local PID=$(cat /tmp/websockify.pid 2>/dev/null)
        if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
            kill "$PID" 2>/dev/null
            print_info "已停止websockify (PID: $PID)"
        fi
        rm -f /tmp/websockify.pid
    fi

    if [ -f /tmp/node_websockify.pid ]; then
        local PID=$(cat /tmp/node_websockify.pid 2>/dev/null)
        if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
            kill "$PID" 2>/dev/null
            print_info "已停止节点websockify (PID: $PID)"
        fi
        rm -f /tmp/node_websockify.pid
    fi

    # 停止systemd服务
    systemctl stop guojici-websockify 2>/dev/null && print_info "已停止guojici-websockify服务"
    systemctl disable guojici-websockify 2>/dev/null

    print_info "✓ 服务已停止"
}

# 卸载Web控制台
uninstall_console() {
    print_step "卸载Web控制台..."

    # 移除Nginx配置
    if [ -f /etc/nginx/sites-enabled/guojici.conf ]; then
        rm -f /etc/nginx/sites-enabled/guojici.conf
        print_info "已移除Nginx站点配置"
    fi
    if [ -f /etc/nginx/sites-available/guojici.conf ]; then
        rm -f /etc/nginx/sites-available/guojici.conf
        print_info "已移除Nginx配置文件"
    fi

    # 重新加载Nginx
    nginx -t 2>/dev/null && systemctl reload nginx 2>/dev/null && print_info "已重新加载Nginx"

    # 移除sudoers配置
    if grep -q "www.*NOPASSWD" /etc/sudoers 2>/dev/null; then
        sed -i '/www.*NOPASSWD.*virsh/d' /etc/sudoers
        print_info "已移除www用户sudo权限"
    fi

    # 移除crontab定时任务
    crontab -l 2>/dev/null | grep -v traffic_monitor | grep -v node_monitor | crontab - 2>/dev/null
    print_info "已移除定时任务"

    # 删除项目文件
    if [ -d /www/wwwroot/default ]; then
        rm -rf /www/wwwroot/default
        print_info "已删除项目文件 /www/wwwroot/default"
    fi

    # 删除临时/日志文件
    rm -f /tmp/websockify.pid /tmp/websockify.log
    rm -rf /tmp/ssh_sessions 2>/dev/null

    print_info "✓ Web控制台卸载完成"
}

# 卸载KVM节点
uninstall_kvm_node() {
    print_step "卸载KVM虚拟化节点..."

    # 销毁所有虚拟机
    local VM_LIST
    VM_LIST=$(virsh list --all --name 2>/dev/null | grep -v '^$')
    if [ -n "$VM_LIST" ]; then
        print_warn "检测到以下虚拟机："
        echo "$VM_LIST" | while read -r vm; do
            print_warn "  - $vm"
        done

        read_input "是否销毁所有虚拟机? 这将删除所有虚拟机数据! [y/N]: " DESTROY_VMS
        if [[ "$DESTROY_VMS" =~ ^[Yy]$ ]]; then
            echo "$VM_LIST" | while read -r vm; do
                if [ -n "$vm" ]; then
                    virsh destroy "$vm" 2>/dev/null
                    virsh undefine "$vm" --remove-all-storage 2>/dev/null
                    print_info "已销毁虚拟机: $vm"
                fi
            done
        else
            print_warn "跳过虚拟机销毁，虚拟机仍在运行"
        fi
    fi

    # 销毁存储池
    if virsh pool-list --all 2>/dev/null | grep -q "kvm-storage"; then
        virsh pool-destroy kvm-storage 2>/dev/null
        virsh pool-undefine kvm-storage 2>/dev/null
        print_info "已移除KVM存储池"
    fi

    # 销毁虚拟网络
    if virsh net-list --all 2>/dev/null | grep -q "default"; then
        virsh net-destroy default 2>/dev/null
        virsh net-undefine default 2>/dev/null
        print_info "已移除default虚拟网络"
    fi

    # 删除存储目录
    if [ -d /guojici/kvm-storage ]; then
        read_input "是否删除KVM存储目录 /guojici/kvm-storage? [y/N]: " DEL_STORAGE
        if [[ "$DEL_STORAGE" =~ ^[Yy]$ ]]; then
            rm -rf /guojici/kvm-storage
            print_info "已删除存储目录 /guojici/kvm-storage"
        else
            print_warn "保留存储目录 /guojici/kvm-storage"
        fi
    fi

    # 删除节点信息
    if [ -d /opt/guojici-node ]; then
        rm -rf /opt/guojici-node
        print_info "已删除节点目录 /opt/guojici-node"
    fi

    # 移除systemd服务
    if [ -f /etc/systemd/system/guojici-websockify.service ]; then
        rm -f /etc/systemd/system/guojici-websockify.service
        systemctl daemon-reload
        print_info "已移除guojici-websockify systemd服务"
    fi

    # 移除sudoers配置
    if grep -q "www.*NOPASSWD" /etc/sudoers 2>/dev/null; then
        sed -i '/www.*NOPASSWD.*virsh/d' /etc/sudoers
        print_info "已移除www用户sudo权限"
    fi

    # 删除临时文件
    rm -f /tmp/node_websockify.pid /tmp/node_websockify.log

    print_info "✓ KVM节点卸载完成"
}

# 卸载数据库
uninstall_database() {
    print_step "卸载数据库..."

    read_input "是否删除数据库 mnbt_mall? 所有数据将丢失! [y/N]: " DEL_DB
    if [[ ! "$DEL_DB" =~ ^[Yy]$ ]]; then
        print_warn "保留数据库"
        return
    fi

    if command -v mysql &>/dev/null; then
        read_input "MySQL root用户名 [root]: " DB_USER
        DB_USER=${DB_USER:-root}
        read_input "MySQL root密码: " DB_PASS "silent"
        echo ""

        if [ -n "$DB_PASS" ]; then
            mysql -u"$DB_USER" -p"$DB_PASS" -e "DROP DATABASE IF EXISTS mnbt_mall;" 2>/dev/null && print_info "已删除数据库 mnbt_mall"
            mysql -u"$DB_USER" -p"$DB_PASS" -e "DROP USER IF EXISTS 'guojici_app'@'localhost';" 2>/dev/null && print_info "已删除数据库用户 guojici_app"
            mysql -u"$DB_USER" -p"$DB_PASS" -e "FLUSH PRIVILEGES;" 2>/dev/null
        else
            mysql -u"$DB_USER" -e "DROP DATABASE IF EXISTS mnbt_mall;" 2>/dev/null && print_info "已删除数据库 mnbt_mall"
            mysql -u"$DB_USER" -e "DROP USER IF EXISTS 'guojici_app'@'localhost';" 2>/dev/null && print_info "已删除数据库用户 guojici_app"
            mysql -u"$DB_USER" -e "FLUSH PRIVILEGES;" 2>/dev/null
        fi
    else
        print_warn "mysql命令未找到，请手动删除数据库: DROP DATABASE mnbt_mall;"
    fi

    print_info "✓ 数据库卸载完成"
}

# 移除防火墙规则（恢复默认）
reset_firewall() {
    print_step "重置防火墙规则..."

    # 清除自定义iptables规则
    iptables -F 2>/dev/null || true
    iptables -X 2>/dev/null || true
    iptables -P INPUT ACCEPT 2>/dev/null || true
    iptables -P FORWARD ACCEPT 2>/dev/null || true
    iptables -P OUTPUT ACCEPT 2>/dev/null || true

    print_warn "防火墙规则已重置为默认（全部放行），请根据需要重新配置"
    print_info "✓ 防火墙重置完成"
}

# 移除www用户
remove_www_user() {
    if id www &>/dev/null; then
        userdel www 2>/dev/null && print_info "已删除www用户"
    fi
}

# ============================================================================
#  卸载模式选择
# ============================================================================
show_menu() {
    echo ""
    echo -e "${CYAN}================================================================${NC}"
    echo -e "${CYAN}    guojici云 - 卸载脚本${NC}"
    echo -e "${CYAN}================================================================${NC}"
    echo ""
    echo "请选择卸载模式："
    echo ""
    echo -e "  ${GREEN}1)${NC} 仅卸载Web控制台（保留KVM虚拟机和数据库）"
    echo -e "  ${GREEN}2)${NC} 仅卸载KVM节点（保留Web控制台和数据库）"
    echo -e "  ${GREEN}3)${NC} 全量卸载（删除所有组件和数据）"
    echo -e "  ${RED}4)${NC} 取消退出"
    echo ""
    read_input "请输入选择 [1/2/3/4]: " UNINSTALL_MODE
}

# ============================================================================
#  主流程
# ============================================================================
main() {
    check_root

    echo ""
    echo -e "${RED}================================================================${NC}"
    echo -e "${RED}    ⚠️  警告：卸载操作不可逆！${NC}"
    echo -e "${RED}    所有相关数据和配置将被永久删除${NC}"
    echo -e "${RED}================================================================${NC}"
    echo ""

    read_input "确认执行卸载? [y/N]: " CONFIRM
    if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
        print_info "卸载已取消"
        exit 0
    fi

    show_menu

    case "$UNINSTALL_MODE" in
        1)
            print_info "已选择: 仅卸载Web控制台"
            stop_services
            uninstall_console
            ;;
        2)
            print_info "已选择: 仅卸载KVM节点"
            stop_services
            uninstall_kvm_node
            ;;
        3)
            print_info "已选择: 全量卸载"
            stop_services
            uninstall_console
            uninstall_kvm_node
            uninstall_database
            reset_firewall
            remove_www_user
            ;;
        4)
            print_info "卸载已取消"
            exit 0
            ;;
        *)
            print_error "无效选择"
            exit 1
            ;;
    esac

    echo ""
    echo -e "${CYAN}================================================================${NC}"
    echo -e "${CYAN}         guojici云 卸载完成！${NC}"
    echo -e "${CYAN}================================================================${NC}"
    echo ""

    if [ "$UNINSTALL_MODE" = "3" ]; then
        print_warn "如需彻底清理，可手动卸载以下系统包："
        echo ""
        echo "  Ubuntu/Debian:"
        echo "    apt-get purge -y nginx php*-fpm mysql-server qemu-kvm libvirt-daemon-system python3-pip"
        echo ""
        echo "  CentOS/RHEL:"
        echo "    yum remove -y nginx php php-fpm mariadb-server qemu-kvm libvirt python3-pip"
        echo ""
    fi
}

main "$@"
