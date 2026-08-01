#!/bin/bash

# guojici云 - KVM计算节点一键安装脚本
# 本脚本仅安装KVM虚拟化运行环境，不安装Web控制台
# 安装完成后在主控台「多机管理」中添加此节点即可

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

print_info()  { echo -e "${GREEN}[INFO]${NC} $1"; }
print_warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
print_error() { echo -e "${RED}[ERROR]${NC} $1"; }
print_step()  { echo -e "${CYAN}[STEP]${NC} $1"; }

# ========== 全局变量 ==========
STORAGE_PATH=""
WEBSOCKIFY_PORT=6080
VNC_START_PORT=5900
VNC_END_PORT=6000
BRIDGE_NAME="virbr0"
NODE_IP=""
SSH_PORT=22

# ========== 步骤1：检查root权限 ==========
check_root() {
    print_step "检查root权限..."
    if [[ $EUID -ne 0 ]]; then
        print_error "必须以root用户运行此脚本"
        exit 1
    fi
    print_info "root权限确认"
}

# ========== 步骤2：检查虚拟化支持 ==========
check_virtualization() {
    print_step "检查硬件虚拟化支持..."
    if ! grep -E 'vmx|svm' /proc/cpuinfo > /dev/null; then
        print_error "CPU不支持硬件虚拟化，请在BIOS中开启VT-x/AMD-V"
        exit 1
    fi
    print_info "硬件虚拟化支持已确认"

    # 检查KVM模块
    print_info "检查KVM内核模块..."
    if ! lsmod | grep -q kvm; then
        print_warn "KVM模块未加载，尝试加载..."
        modprobe kvm 2>/dev/null || print_warn "无法加载kvm模块"
        if grep -q 'vmx' /proc/cpuinfo; then
            modprobe kvm_intel 2>/dev/null || true
        else
            modprobe kvm_amd 2>/dev/null || true
        fi
    fi
    if lsmod | grep -q kvm; then
        print_info "KVM内核模块已加载"
    else
        print_warn "KVM模块未加载，安装完成后重启可能解决"
    fi
}

# ========== 步骤3：交互式配置 ==========
ask_config() {
    print_step "配置KVM节点参数..."
    echo ""
    print_info "======================================"
    print_info "    KVM计算节点配置"
    print_info "======================================"
    echo ""

    # 存储路径
    print_info "请输入KVM虚拟机磁盘存储路径"
    print_info "默认路径: /guojici/kvm-storage"
    print_info "注意: 请确保该路径所在磁盘有足够空间"
    echo ""
    read -p "存储路径 [/guojici/kvm-storage]: " STORAGE_PATH
    STORAGE_PATH=${STORAGE_PATH:-/guojici/kvm-storage}
    echo ""

    # Websockify端口
    print_info "请输入websockify监听端口（用于noVNC Web控制台）"
    print_info "默认端口: 6080"
    read -p "Websockify端口 [6080]: " WEBSOCKIFY_PORT
    WEBSOCKIFY_PORT=${WEBSOCKIFY_PORT:-6080}
    echo ""

    # VNC端口范围
    print_info "请输入VNC端口范围（虚拟机控制台端口）"
    print_info "默认范围: 5900-6000"
    read -p "VNC起始端口 [5900]: " VNC_START_PORT
    VNC_START_PORT=${VNC_START_PORT:-5900}
    read -p "VNC结束端口 [6000]: " VNC_END_PORT
    VNC_END_PORT=${VNC_END_PORT:-6000}
    echo ""

    # 确认
    print_info "配置摘要："
    print_info "  存储路径:       $STORAGE_PATH"
    print_info "  Websockify端口: $WEBSOCKIFY_PORT"
    print_info "  VNC端口范围:    $VNC_START_PORT-$VNC_END_PORT"
    echo ""
    read -p "确认使用以上配置? [Y/n]: " CONFIRM
    CONFIRM=${CONFIRM:-Y}
    if [[ ! $CONFIRM =~ ^[Yy]$ ]]; then
        ask_config
    fi
}

# ========== 步骤4：安装KVM依赖 ==========
install_dependencies() {
    print_step "安装KVM虚拟化依赖..."
    echo ""

    if command -v apt-get &> /dev/null; then
        print_info "检测到Ubuntu/Debian系统，使用apt-get..."
        apt-get update -y || print_warn "apt-get update 失败，继续尝试..."
        apt-get install -y \
            qemu-kvm libvirt-daemon-system libvirt-clients bridge-utils \
            virt-manager libguestfs-tools \
            openssh-server sshpass \
            tigervnc-standalone-server \
            python3-pip wget net-tools \
            iptables-persistent 2>/dev/null || true

    elif command -v yum &> /dev/null; then
        print_info "检测到CentOS/RHEL系统，使用yum..."
        yum update -y || print_warn "yum update 失败，继续尝试..."
        yum install -y \
            qemu-kvm libvirt libvirt-daemon-kvm virt-install virt-manager \
            libguestfs-tools \
            openssh-server sshpass \
            tigervnc-server \
            python3-pip wget net-tools \
            iptables-services 2>/dev/null || true

    elif command -v dnf &> /dev/null; then
        print_info "检测到Fedora系统，使用dnf..."
        dnf update -y || print_warn "dnf update 失败，继续尝试..."
        dnf install -y \
            qemu-kvm libvirt libvirt-daemon-kvm virt-install virt-manager \
            libguestfs-tools \
            openssh-server sshpass \
            tigervnc-server \
            python3-pip wget net-tools \
            iptables-services 2>/dev/null || true
    else
        print_error "不支持的包管理器，请手动安装以下包："
        print_info "qemu-kvm libvirt bridge-utils virt-manager libguestfs-tools openssh-server sshpass tigervnc python3-pip"
        exit 1
    fi

    print_info "KVM依赖安装完成"
}

# ========== 步骤5：安装websockify ==========
install_websockify() {
    print_step "安装websockify..."
    print_info "安装websockify（noVNC Web控制台代理）..."

    pip3 install websockify --break-system-packages 2>/dev/null || \
    pip3 install websockify 2>/dev/null || {
        print_warn "pip安装websockify失败，尝试其他方式..."
        # 尝试从GitHub安装
        cd /tmp
        wget -q https://github.com/novnc/websockify/archive/refs/tags/v0.11.0.tar.gz -O websockify.tar.gz 2>/dev/null || true
        if [ -f websockify.tar.gz ]; then
            tar xzf websockify.tar.gz
            cd websockify-0.11.0
            python3 setup.py install 2>/dev/null || print_warn "websockify手动安装失败"
            cd /tmp
            rm -rf websockify-0.11.0 websockify.tar.gz
        fi
    }

    if command -v websockify &> /dev/null; then
        print_info "websockify安装成功"
    else
        print_warn "websockify可能未正确安装，请手动安装: pip3 install websockify"
    fi
}

# ========== 步骤6：配置Libvirt ==========
configure_libvirt() {
    print_step "配置Libvirt..."

    print_info "启动libvirtd服务..."
    systemctl enable libvirtd
    systemctl start libvirtd

    print_info "配置qemu.conf..."
    if ! grep -q 'user = "root"' /etc/libvirt/qemu.conf 2>/dev/null; then
        echo 'user = "root"' >> /etc/libvirt/qemu.conf
        echo 'group = "root"' >> /etc/libvirt/qemu.conf
    fi

    systemctl restart libvirtd
    print_info "Libvirt配置完成"
}

# ========== 步骤7：配置网络 ==========
configure_network() {
    print_step "配置虚拟网络..."

    print_info "检查default虚拟网络..."
    if ! virsh net-list --all 2>/dev/null | grep -q "default"; then
        print_info "创建default虚拟网络..."
        if [ -f /usr/share/libvirt/networks/default.xml ]; then
            virsh net-define /usr/share/libvirt/networks/default.xml
            virsh net-autostart default
            virsh net-start default
        else
            print_warn "default.xml不存在，手动创建..."
            cat > /tmp/default_network.xml << 'EOF'
<network>
  <name>default</name>
  <forward mode='nat'/>
  <bridge name='virbr0' stp='on' delay='0'/>
  <ip address='192.168.122.1' family='ipv4' prefix='24'>
    <dhcp>
      <range start='192.168.122.2' end='192.168.122.254'/>
    </dhcp>
  </ip>
</network>
EOF
            virsh net-define /tmp/default_network.xml
            virsh net-autostart default
            virsh net-start default
            rm -f /tmp/default_network.xml
        fi
    else
        print_info "default网络已存在"
        virsh net-start default 2>/dev/null || true
    fi

    if ! ip addr show 2>/dev/null | grep -q "virbr0"; then
        print_warn "虚拟网桥virbr0未激活，正在尝试激活..."
        virsh net-start default 2>/dev/null || true
    fi

    print_info "网络配置完成"
}

# ========== 步骤8：配置存储池 ==========
configure_storage() {
    print_step "配置KVM存储池..."

    print_info "创建存储目录: $STORAGE_PATH"
    mkdir -p "$STORAGE_PATH"

    # 创建ISO子目录
    mkdir -p "$STORAGE_PATH/iso"

    print_info "检查存储池..."
    if ! virsh pool-list --all 2>/dev/null | grep -q "kvm-storage"; then
        print_info "创建KVM存储池..."
        virsh pool-define-as --name kvm-storage --type dir --target "$STORAGE_PATH"
        virsh pool-autostart kvm-storage
        virsh pool-start kvm-storage
    else
        print_info "存储池kvm-storage已存在，更新路径..."
        virsh pool-destroy kvm-storage 2>/dev/null || true
        virsh pool-undefine kvm-storage 2>/dev/null || true
        virsh pool-define-as --name kvm-storage --type dir --target "$STORAGE_PATH"
        virsh pool-autostart kvm-storage
        virsh pool-start kvm-storage
    fi

    print_info "存储池路径: $STORAGE_PATH"
}

# ========== 步骤9：配置SSH ==========
configure_ssh() {
    print_step "配置SSH..."

    print_info "启用SSH root登录和密码认证..."
    SSHD_CONFIG="/etc/ssh/sshd_config"

    if [ -f "$SSHD_CONFIG" ]; then
        sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin yes/' "$SSHD_CONFIG"
        sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication yes/' "$SSHD_CONFIG"
    fi

    systemctl restart sshd 2>/dev/null || systemctl restart ssh 2>/dev/null || true
    print_info "SSH配置完成"
}

# ========== 步骤10：配置防火墙 ==========
configure_firewall() {
    print_step "配置防火墙..."

    print_info "设置iptables规则（仅开放必要端口）..."
    iptables -F INPUT 2>/dev/null || true
    iptables -A INPUT -i lo -j ACCEPT 2>/dev/null || true
    iptables -A INPUT -m state --state RELATED,ESTABLISHED -j ACCEPT 2>/dev/null || true

    # SSH端口
    iptables -A INPUT -p tcp --dport $SSH_PORT -j ACCEPT 2>/dev/null || true

    # Websockify端口
    iptables -A INPUT -p tcp --dport $WEBSOCKIFY_PORT -j ACCEPT 2>/dev/null || true

    # VNC端口范围
    iptables -A INPUT -p tcp --dport ${VNC_START_PORT}:${VNC_END_PORT} -j ACCEPT 2>/dev/null || true

    # Libvirt迁移端口（可选）
    iptables -A INPUT -p tcp --dport 16509 -j ACCEPT 2>/dev/null || true

    iptables -P INPUT DROP 2>/dev/null || true

    print_info "保存防火墙规则..."
    if command -v netfilter-persistent &> /dev/null; then
        netfilter-persistent save 2>/dev/null || true
    elif command -v iptables-save &> /dev/null; then
        mkdir -p /etc/iptables 2>/dev/null || true
        iptables-save > /etc/iptables/rules.v4 2>/dev/null || true
    fi

    systemctl enable netfilter-persistent 2>/dev/null || true
    print_info "防火墙配置完成"
}

# ========== 步骤11：创建websockify启动脚本 ==========
create_websockify_script() {
    print_step "创建websockify启动脚本..."

    mkdir -p /opt/guojici-node
    mkdir -p /opt/guojici-node/tokens

    cat > /opt/guojici-node/start_websockify.sh << WSEOF
#!/bin/bash
# websockify启动脚本 - guojici云KVM计算节点
# 监听端口: $WEBSOCKIFY_PORT
# Token文件: /opt/guojici-node/tokens/tokens.list

if [ -f /tmp/node_websockify.pid ]; then
    OLD_PID=\$(cat /tmp/node_websockify.pid)
    if kill -0 \$OLD_PID 2>/dev/null; then
        kill \$OLD_PID
        sleep 1
    fi
    rm -f /tmp/node_websockify.pid
fi

mkdir -p /opt/guojici-node/tokens
touch /opt/guojici-node/tokens/tokens.list

websockify --web /opt/guojici-node --daemon \
    --pid /tmp/node_websockify.pid \
    --log-file /tmp/node_websockify.log \
    --token-plugin TokenFile \
    --token-source /opt/guojici-node/tokens/tokens.list \
    $WEBSOCKIFY_PORT

echo "websockify已启动，监听端口: $WEBSOCKIFY_PORT"
WSEOF
    chmod +x /opt/guojici-node/start_websockify.sh

    # 创建Token目录
    touch /opt/guojici-node/tokens/tokens.list

    # 创建systemd服务
    cat > /etc/systemd/system/guojici-websockify.service << SVCEOF
[Unit]
Description=guojici Cloud KVM Node - Websockify Service
After=network.target libvirtd.service

[Service]
Type=forking
PIDFile=/tmp/node_websockify.pid
ExecStart=/opt/guojici-node/start_websockify.sh
ExecStop=/bin/kill \$(cat /tmp/node_websockify.pid)
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
SVCEOF

    systemctl daemon-reload
    systemctl enable guojici-websockify

    print_info "websockify启动脚本已创建"
}

# ========== 步骤12：配置sudo权限 ==========
configure_sudoers() {
    print_step "配置sudo权限..."

    # 确保www用户存在（主控台通过SSH连接时可能用到）
    if ! id -u www &> /dev/null; then
        useradd -r -s /sbin/nologin www 2>/dev/null || true
    fi

    if ! grep -q "www.*NOPASSWD" /etc/sudoers 2>/dev/null; then
        echo "www ALL=(ALL) NOPASSWD:/usr/bin/virsh,/usr/bin/qemu-img,/usr/bin/rm,/usr/bin/mkdir,/usr/bin/ls,/usr/bin/cat" >> /etc/sudoers
        print_info "www用户sudo权限已配置"
    else
        print_info "www用户sudo权限已存在"
    fi
}

# ========== 步骤13：生成节点信息文件 ==========
generate_node_info() {
    print_step "生成节点信息文件..."

    # 获取本机IP
    NODE_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
    if [ -z "$NODE_IP" ]; then
        NODE_IP=$(ip addr show 2>/dev/null | grep 'inet ' | grep -v '127.0.0.1' | awk '{print $2}' | cut -d'/' -f1 | head -1)
    fi

    cat > /opt/guojici-node/node-info.json << INFOEOF
{
    "node_name": "kvm-node-$(hostname)",
    "node_ip": "$NODE_IP",
    "ssh_port": $SSH_PORT,
    "ssh_user": "root",
    "storage_pool": "$STORAGE_PATH",
    "bridge_name": "$BRIDGE_NAME",
    "websockify_port": $WEBSOCKIFY_PORT,
    "vnc_start_port": $VNC_START_PORT,
    "vnc_end_port": $VNC_END_PORT,
    "max_vms": 50,
    "installed_at": "$(date '+%Y-%m-%d %H:%M:%S')"
}
INFOEOF

    print_info "节点信息已保存到 /opt/guojici-node/node-info.json"
}

# ========== 步骤14：启动服务 ==========
start_services() {
    print_step "启动服务..."

    print_info "启动libvirtd..."
    systemctl restart libvirtd
    systemctl enable libvirtd

    print_info "启动websockify..."
    /opt/guojici-node/start_websockify.sh
    sleep 2

    print_info "服务启动完成"
}

# ========== 步骤15：安装后验证 ==========
post_install_check() {
    print_step "安装后验证..."
    echo ""

    local ALL_OK=true

    print_info "检查KVM模块..."
    if lsmod | grep -q kvm; then
        echo "  ✓ KVM内核模块已加载"
    else
        echo "  ✗ KVM内核模块未加载"
        ALL_OK=false
    fi

    print_info "检查libvirtd服务..."
    if systemctl is-active libvirtd &> /dev/null; then
        echo "  ✓ libvirtd服务运行中"
    else
        echo "  ✗ libvirtd服务未运行"
        ALL_OK=false
    fi

    print_info "检查存储池..."
    if virsh pool-list 2>/dev/null | grep -q "kvm-storage"; then
        echo "  ✓ 存储池kvm-storage已激活"
    else
        echo "  ✗ 存储池未激活"
        ALL_OK=false
    fi

    print_info "检查虚拟网络..."
    if virsh net-list 2>/dev/null | grep -q "default"; then
        echo "  ✓ default虚拟网络已激活"
    else
        echo "  ✗ default虚拟网络未激活"
        ALL_OK=false
    fi

    print_info "检查websockify..."
    if ss -tlnp 2>/dev/null | grep -q ":$WEBSOCKIFY_PORT"; then
        echo "  ✓ websockify监听端口 $WEBSOCKIFY_PORT"
    else
        echo "  △ websockify可能未启动，请手动执行: /opt/guojici-node/start_websockify.sh"
    fi

    print_info "检查SSH..."
    if systemctl is-active sshd &> /dev/null || systemctl is-active ssh &> /dev/null; then
        echo "  ✓ SSH服务运行中"
    else
        echo "  ✗ SSH服务未运行"
        ALL_OK=false
    fi

    echo ""
    if [ "$ALL_OK" = true ]; then
        print_info "所有检查项通过！"
    else
        print_warn "部分检查项未通过，请根据提示排查"
    fi
}

# ========== 步骤16：输出安装结果 ==========
print_result() {
    echo ""
    echo ""
    print_info "================================================================"
    print_info "         guojici云 KVM计算节点安装完成！"
    print_info "================================================================"
    echo ""
    echo -e "${CYAN}节点信息（在主控台「多机管理」中添加时填写）：${NC}"
    echo ""
    echo "  ┌──────────────────────────────────────────────┐"
    echo "  │  节点名称:  kvm-node-$(hostname)"
    echo "  │  节点IP:    $NODE_IP"
    echo "  │  SSH端口:   $SSH_PORT"
    echo "  │  SSH用户:   root"
    echo "  │  SSH密码:   （您设置的root密码）"
    echo "  │  存储路径:  $STORAGE_PATH"
    echo "  │  网桥:      $BRIDGE_NAME"
    echo "  │  Websockify: $WEBSOCKIFY_PORT"
    echo "  │  VNC范围:   $VNC_START_PORT-$VNC_END_PORT"
    echo "  └──────────────────────────────────────────────┘"
    echo ""
    echo -e "${GREEN}下一步操作：${NC}"
    echo ""
    echo "  1. 确保root密码已设置（如未设置请执行: passwd root）"
    echo "  2. 在主控台管理后台访问「多机管理」页面"
    echo "  3. 点击「添加节点」，填入上方节点信息"
    echo "  4. 点击「测试连接」验证节点连通性"
    echo "  5. 测试通过后节点即可用于创建虚拟机"
    echo ""
    echo -e "${YELLOW}常用命令：${NC}"
    echo ""
    echo "  查看存储池:       virsh pool-list"
    echo "  查看虚拟机:       virsh list --all"
    echo "  重启websockify:   /opt/guojici-node/start_websockify.sh"
    echo "  查看节点信息:     cat /opt/guojici-node/node-info.json"
    echo "  查看服务状态:     systemctl status guojici-websockify"
    echo ""
    echo -e "${CYAN}节点信息已保存到: /opt/guojici-node/node-info.json${NC}"
    echo ""
    print_info "================================================================"
}

# ========== 主流程 ==========
main() {
    echo ""
    echo -e "${CYAN}"
    echo "================================================================"
    echo "    guojici云 - KVM计算节点一键安装脚本"
    echo "    仅安装KVM虚拟化运行环境（不含Web控制台）"
    echo "================================================================"
    echo -e "${NC}"
    echo ""

    check_root
    check_virtualization
    ask_config
    install_dependencies
    install_websockify
    configure_libvirt
    configure_network
    configure_storage
    configure_ssh
    configure_firewall
    create_websockify_script
    configure_sudoers
    generate_node_info
    start_services
    post_install_check
    print_result
}

main "$@"
