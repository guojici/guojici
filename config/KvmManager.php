<?php
/**
 * KVM 虚拟服务器管理类
 * 通过 SSH + virsh/libvirt 命令与KVM宿主机通信
 * 管理实例的创建、启动、停止、重启、销毁、重装系统
 *
 * 依赖：
 *   - PHP SSH2 扩展 或 本地 exec（如果PHP在宿主机）
 *   - 宿主机需安装 qemu-kvm / libvirt / virt-install
 *
 * 本类实现：
 *   1. connect()       → SSH连接到KVM宿主机
 *   2. createVM()      → 创建虚拟机（磁盘+定义XML+install）
 *   3. startVM/stopVM/restartVM/destroyVM → 启停控制
 *   4. getVMPowerState() → 获取状态
 *   5. reinstallVM()  → 重装系统
 *   6. getConsoleURL() → VNC/noVNC控制台地址
 */

class KvmManager {

    private $host;
    private $port;
    private $user;
    private $password;
    private $ssh = null;
    private $lastError = '';
    private $publicDomain = '';
    private $networkBridge = 'virbr0';
    private $storagePool = '/mnt/50D008FDD008EAD4';

    public function __construct($config = []) {
        $this->host = $config['host'] ?? '127.0.0.1';
        $this->port = intval($config['port'] ?? 22);
        $this->user = $config['user'] ?? 'root';
        $this->password = $config['password'] ?? '';
        $this->publicDomain = $config['public_domain'] ?? $this->host;
        $this->networkBridge = $config['bridge'] ?? 'virbr0';
        $this->storagePool = $config['storage'] ?? '/mnt/50D008FDD008EAD4';
    }

    public function getError() { return $this->lastError; }
    public function getStoragePool() { return $this->storagePool; }

    /**
     * 判断是否为本地连接
     */
    public function isLocalHost() {
        if ($this->host === '127.0.0.1' || $this->host === 'localhost' || $this->host === '::1') {
            return true;
        }
        if (!empty($this->publicDomain) && $this->publicDomain === $this->host) {
            return true;
        }
        // 快速判断：host == gethostname()
        $hostname = @gethostname();
        if (!empty($hostname) && $hostname === $this->host) {
            return true;
        }
        // 尝试解析 host 的 IP
        $ip = @gethostbyname($this->host);
        if (!empty($ip)) {
            if (strpos($ip, '127.') === 0) return true;
        }
        // 读取本机网卡 IP（不依赖 shell_exec，改用更稳妥的方式）
        $local_ips = [];
        if (function_exists('shell_exec')) {
            $raw = @shell_exec("PATH=\$PATH:/usr/sbin:/sbin ip -o -4 addr show 2>/dev/null");
            if ($raw && preg_match_all('/inet\s+([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)/', $raw, $m)) {
                $local_ips = $m[1];
            }
            if (empty($local_ips)) {
                $raw2 = @shell_exec("PATH=\$PATH:/sbin:/usr/sbin ifconfig 2>/dev/null");
                if ($raw2 && preg_match_all('/inet\s+(?:addr:)?([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)/', $raw2, $m2)) {
                    $local_ips = $m2[1];
                }
            }
        }
        if (!empty($ip) && in_array($ip, $local_ips, true)) return true;
        if (in_array($this->host, $local_ips, true)) return true;
        return false;
    }

    /**
     * 检测 PHP 是否能执行系统命令（exec/shell_exec/passthru）
     */
    public function checkPhpExecEnabled() {
        $disabled = @ini_get('disable_functions');
        $disabled_list = $disabled ? array_map('trim', preg_split('/[\s,]+/', $disabled)) : [];
        $needed = ['exec', 'shell_exec', 'system', 'passthru'];
        $blocked = [];
        foreach ($needed as $f) {
            if (in_array($f, $disabled_list, true)) $blocked[] = $f;
        }
        return $blocked;
    }

    /**
     * 获取 virsh/qemu-img 等工具的真实路径（PHP 环境 PATH 可能不全）
     */
    private function findBin($name) {
        $candidates = [
            "/usr/bin/$name",
            "/usr/sbin/$name",
            "/bin/$name",
            "/sbin/$name",
            "/usr/libexec/$name",
        ];
        // 先直接按已知路径找（最快）
        foreach ($candidates as $p) {
            if (@file_exists($p)) {
                return $p;
            }
        }
        // 再用 which 搜索（带完整 PATH）
        $out = [];
        $ret = 0;
        @exec("PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin which $name 2>/dev/null", $out, $ret);
        if ($ret === 0 && !empty($out)) {
            $p = trim(implode("\n", $out));
            if ($p) return $p;
        }
        return '';
    }

    public function exec($cmd) {
        $this->lastError = '';

        // 本地执行（PHP 与 KVM 同机）
        if ($this->isLocalHost()) {
            $output = [];
            $return_var = 0;

            // 检查 exec 是否被禁用
            $blocked = $this->checkPhpExecEnabled();
            if (!empty($blocked)) {
                $this->lastError = 'PHP 已禁用系统命令函数: ' . implode(', ', $blocked) . '。请在宝塔面板 → 软件商店 → PHP → 设置 → 禁用函数 中移除 exec、shell_exec、passthru、system。';
                return false;
            }

            $path_env = 'export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH; ';

            // 判断是否是需要 root 权限的命令
            // virsh 需要连接 libvirt system socket，普通用户无权限
            // qemu-img 在某些目录操作上也需要权限
            // lsmod 和普通用户可执行，不需要 sudo
            $needs_root = false;
            $trimmed_cmd = ltrim($cmd);
            if (preg_match('/^(virsh|qemu-img|modprobe|rmmod|systemctl\s+(start|stop|restart|reload|status|enable|disable))\b/', $trimmed_cmd)) {
                $needs_root = true;
            }

            if ($needs_root) {
                // 直接用 sudo 执行
                $sudo_cmd = $path_env . 'sudo -n ' . $cmd . ' 2>&1';
                $ret = @exec($sudo_cmd, $output, $return_var);
                $result = implode("\n", $output);
                if ($return_var !== 0) {
                    $hint = '';
                    if (strpos($result, 'password is required') !== false || strpos($result, 'sudo: ') !== false) {
                        $php_user = $this->getCurrentUser();
                        $hint = "。请配置 sudoers：在 /etc/sudoers.d/kvm-php 中添加 $php_user 免密码执行 /usr/bin/virsh 等命令";
                    }
                    $this->lastError = trim($result) . $hint;
                    return false;
                }
                return trim($result);
            }

            // 其他命令（touch、ls、test 等）→ 先尝试直接执行，失败再 sudo
            $ret = @exec($path_env . $cmd . ' 2>&1', $output, $return_var);
            $result = implode("\n", $output);
            if ($return_var === 0) {
                return trim($result);
            }

            // 直接执行失败，尝试 sudo 回退
            $output2 = [];
            $return_var2 = 0;
            $sudo_cmd = $path_env . 'sudo -n ' . $cmd . ' 2>&1';
            $ret2 = @exec($sudo_cmd, $output2, $return_var2);
            $result2 = implode("\n", $output2);
            if ($return_var2 === 0) {
                return trim($result2);
            }

            // 都失败，返回原始错误
            $this->lastError = trim($result2 ?: $result);
            return false;
        }

        // ssh2 扩展
        if (function_exists('ssh2_connect') && !$this->ssh) {
            $this->ssh = @ssh2_connect($this->host, $this->port);
            if (!$this->ssh) {
                $this->lastError = 'SSH连接失败: ' . $this->host . ':' . $this->port . '（请检查网络/SSH服务状态）';
                return false;
            }
            if (!@ssh2_auth_password($this->ssh, $this->user, $this->password)) {
                $this->lastError = 'SSH认证失败（账号/密码错误）';
                $this->ssh = null;
                return false;
            }
        }

        if ($this->ssh && function_exists('ssh2_connect')) {
            $stream = @ssh2_exec($this->ssh, $cmd);
            if (!$stream) {
                $this->lastError = '命令执行失败';
                return false;
            }
            stream_set_blocking($stream, true);
            $result = '';
            while (!feof($stream)) {
                $result .= fgets($stream, 8192);
            }
            fclose($stream);
            return trim($result);
        }

        // sshpass + ssh
        if (!empty($this->password)) {
            $sshpass = trim(@shell_exec('which sshpass 2>/dev/null || echo ""'));
            if (!$sshpass) {
                $this->lastError = "请在运行 PHP 的服务器安装 sshpass：apt-get install sshpass（Debian/Ubuntu） 或 yum install sshpass（CentOS/RHEL）";
                return false;
            }
            $ssh_cmd = sprintf(
                '%s -p %s ssh -p %d -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o BatchMode=no %s@%s %s 2>&1',
                escapeshellcmd($sshpass),
                escapeshellarg($this->password),
                $this->port,
                escapeshellarg($this->user),
                escapeshellarg($this->host),
                escapeshellarg($cmd)
            );
            $output = [];
            $return_var = 0;
            @exec($ssh_cmd, $output, $return_var);
            $result = implode("\n", $output);
            if ($return_var !== 0) {
                $cleaned = trim($result);
                if (strpos($cleaned, 'Permission denied') !== false) {
                    $this->lastError = 'SSH认证失败（账号/密码错误）';
                } elseif (strpos($cleaned, 'Connection timed out') !== false || strpos($cleaned, 'No route to host') !== false) {
                    $this->lastError = '无法连接到 ' . $this->host . ':' . $this->port . '（请检查网络/防火墙/SSH服务）';
                } elseif (strpos($cleaned, 'Connection refused') !== false) {
                    $this->lastError = '连接被拒绝：' . $this->host . ':' . $this->port;
                } else {
                    $this->lastError = $cleaned ?: ('SSH命令执行失败，返回码 ' . $return_var);
                }
                return false;
            }
            return trim($result);
        }

        $this->lastError = '未提供SSH密码且非本地连接';
        return false;
    }

    /**
     * 获取当前 PHP 运行用户（用于 sudoers 提示）
     */
    private function getCurrentUser() {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $user = posix_getpwuid(posix_geteuid());
            return $user['name'] ?? 'www-data';
        }
        return 'www-data';
    }

    /**
     * 生成随机MAC
     */
    private function generateMac() {
        return '52:54:00:' . sprintf('%02x', rand(0, 255)) . ':' . sprintf('%02x', rand(0, 255)) . ':' . sprintf('%02x', rand(0, 255));
    }

    /**
     * 生成UUID
     */
    private function generateUUID() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            rand(0, 65535), rand(0, 65535),
            rand(0, 65535), rand(16384, 20479),
            rand(32768, 49151),
            rand(0, 65535), rand(0, 65535), rand(0, 65535)
        );
    }

    /**
     * 生成随机root密码
     */
    public function generateRootPassword($length = 16) {
        $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $pwd = '';
        for ($i = 0; $i < $length; $i++) {
            $pwd .= $chars[rand(0, strlen($chars) - 1)];
        }
        return $pwd;
    }

    private function checkVirtCustomize() {
        $result = $this->exec('which virt-customize 2>/dev/null');
        return !empty($result);
    }

    /**
     * 检查 virt-sysprep 是否可用
     */
    private function checkVirtSysprep() {
        $result = $this->exec('which virt-sysprep 2>/dev/null');
        return !empty($result);
    }

    /**
     * 检查 guestfish 是否可用
     */
    private function checkGuestfish() {
        $result = $this->exec('which guestfish 2>/dev/null');
        return !empty($result);
    }

    /**
     * 检查 /dev/kvm 是否可用（硬件虚拟化支持）
     * @return bool|string true=可用，字符串=具体原因
     */
    private function checkKvmDevice() {
        if (!file_exists('/dev/kvm')) {
            return '/dev/kvm 不存在，宿主机未开启硬件虚拟化或未加载 kvm 模块';
        }
        if (!is_readable('/dev/kvm') || !is_writable('/dev/kvm')) {
            return '/dev/kvm 权限不足，当前用户无法访问（可能需要加入 kvm 组）';
        }
        return true;
    }

    /**
     * 检查 libguestfs 运行环境是否满足（用于诊断密码设置失败的根因）
     * @return array ['ok' => bool, 'errors' => string[], 'warnings' => string[]]
     */
    private function diagnoseGuestfsEnvironment() {
        $errors = [];
        $warnings = [];

        // 检查 /dev/kvm
        $kvm_check = $this->checkKvmDevice();
        if ($kvm_check !== true) {
            $errors[] = $kvm_check;
        }

        // 检查 libguestfs 工具
        if (!$this->checkVirtCustomize() && !$this->checkGuestfish() && !$this->checkVirtSysprep()) {
            $errors[] = '未检测到任何 libguestfs 工具（virt-customize / guestfish / virt-sysprep 均不可用），请安装 libguestfs-tools';
        }

        // 检查内核模块
        $kvm_loaded = trim($this->exec('lsmod 2>/dev/null | grep -c "^kvm "'));
        if ($kvm_loaded === '0') {
            $warnings[] = 'kvm 内核模块未加载（可能为嵌套虚拟化或容器环境）';
        }

        // 检查临时目录空间
        $tmp_dir = sys_get_temp_dir();
        $disk_free = @disk_free_space($tmp_dir);
        if ($disk_free !== false && $disk_free < 500 * 1024 * 1024) {
            $warnings[] = "临时目录 {$tmp_dir} 剩余空间不足 500MB，可能导致 libguestfs 启动失败";
        }

        return [
            'ok' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * 检测宿主机可用的安全驱动模型（selinux/apparmor/none）
     * 通过 virsh capabilities 解析 <secmodel> 节点
     * 避免硬编码 selinux 导致在不支持 SELinux 的系统上启动失败
     */
    private function detectSecurityModel() {
        static $model = null;
        if ($model !== null) {
            return $model;
        }
        $caps = $this->exec('virsh capabilities 2>/dev/null');
        if ($caps && preg_match_all('/<secmodel>\s*<model>([^<]+)<\/model>/i', $caps, $m)) {
            foreach ($m[1] as $mdl) {
                $mdl = trim($mdl);
                // 优先 selinux，其次 apparmor
                if (!empty($mdl)) {
                    $model = $mdl;
                    if ($mdl === 'selinux') break;
                }
            }
        }
        if (empty($model)) {
            $model = 'none';
        }
        return $model;
    }

    /**
     * 生成 seclabel XML 片段（根据宿主机实际可用的安全驱动）
     * selinux/apparmor 可用时启用动态标签；均不可用则省略
     */
    private function buildSeclabelXml() {
        $model = $this->detectSecurityModel();
        if ($model === 'selinux' || $model === 'apparmor') {
            return "\n  <seclabel type='dynamic' model='{$model}' relabel='yes'/>";
        }
        return '';
    }

    private function installLibguestfsTools() {
        $install_cmd = '';

        $os_release = $this->exec('cat /etc/os-release 2>/dev/null') ?: '';
        if (stripos($os_release, 'centos') !== false || stripos($os_release, 'rhel') !== false || stripos($os_release, 'rocky') !== false || stripos($os_release, 'alma') !== false) {
            $install_cmd = 'yum install -y libguestfs-tools 2>&1';
        } elseif (stripos($os_release, 'ubuntu') !== false || stripos($os_release, 'debian') !== false) {
            $install_cmd = 'apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y libguestfs-tools 2>&1';
        } else {
            $install_cmd = 'yum install -y libguestfs-tools 2>&1 || apt-get update && apt-get install -y libguestfs-tools 2>&1';
        }

        $result = $this->exec($install_cmd);
        return $this->checkVirtCustomize();
    }

    /**
     * 检查 ISO 创建工具（genisoimage / mkisofs）是否可用
     * @return bool
     */
    private function checkIsoTools() {
        $result = $this->exec('which genisoimage 2>/dev/null');
        if (!empty($result)) {
            return true;
        }
        $result = $this->exec('which mkisofs 2>/dev/null');
        return !empty($result);
    }

    /**
     * 自动安装 ISO 创建工具（genisoimage / mkisofs）
     * @return bool 是否安装成功
     */
    private function installIsoTools() {
        $os_release = $this->exec('cat /etc/os-release 2>/dev/null') ?: '';
        if (stripos($os_release, 'centos') !== false || stripos($os_release, 'rhel') !== false || stripos($os_release, 'rocky') !== false || stripos($os_release, 'alma') !== false) {
            // CentOS/RHEL 系：genisoimage 包含在 genisoimage 或 mkisofs 包中
            $install_cmd = 'yum install -y genisoimage mkisofs 2>&1';
        } elseif (stripos($os_release, 'ubuntu') !== false || stripos($os_release, 'debian') !== false) {
            // Ubuntu/Debian 系：genisoimage 和 mkisofs 都可用
            $install_cmd = 'apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y genisoimage mkisofs 2>&1';
        } else {
            // 未知系统，尝试两种包管理器
            $install_cmd = 'yum install -y genisoimage mkisofs 2>&1 || (apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y genisoimage mkisofs 2>&1)';
        }

        $this->exec($install_cmd);
        return $this->checkIsoTools();
    }

    /**
     * 使用 guestfish 直接修改磁盘镜像中的 root 密码
     * 这是 virt-customize 失败时的备用方案
     * 支持：普通分区、LVM 逻辑卷、多分区（带 /boot）
     *
     * @param string $disk_file 磁盘镜像路径
     * @param string $password 明文 root 密码
     * @return bool|string 成功返回 true，失败返回错误信息字符串
     */
    private function setPasswordViaGuestfish($disk_file, $password) {
        if (!$this->checkGuestfish()) {
            return 'guestfish 命令不可用';
        }

        $salt = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 16);
        $crypted_pwd = crypt($password, '$6$' . $salt . '$');
        $crypted_pwd_escaped = str_replace("'", "'\\''", $crypted_pwd);

        // 第一步：列出所有文件系统，探测根分区
        $fs_list = $this->exec("guestfish -a " . escapeshellarg($disk_file) . " run : list-filesystems 2>&1");
        if ($fs_list === false || trim($fs_list) === '') {
            return 'guestfish 无法启动或无法列出文件系统（输出为空）';
        }
        if (stripos($fs_list, 'error') !== false && stripos($fs_list, 'list-filesystems') === false) {
            return 'guestfish 启动失败: ' . trim($fs_list);
        }

        // 解析所有分区及其类型
        $partitions = [];
        if (preg_match_all('#(/dev/[a-z0-9_/\-]+):\s*(\S+)#', $fs_list, $m)) {
            foreach ($m[1] as $i => $dev) {
                $fstype = strtolower($m[2][$i] ?? '');
                if (in_array($fstype, ['ext4', 'ext3', 'ext2', 'xfs', 'btrfs'])) {
                    $partitions[] = ['dev' => $dev, 'type' => $fstype];
                }
            }
        }

        if (empty($partitions)) {
            // 尝试 LVM 逻辑卷
            $lvs = $this->exec("guestfish -a " . escapeshellarg($disk_file) . " run : lvs 2>&1");
            if ($lvs && preg_match_all('#(/dev/[a-z0-9_/\-]+)#i', $lvs, $lm)) {
                foreach ($lm[1] as $lv) {
                    $partitions[] = ['dev' => $lv, 'type' => 'lvm'];
                }
            }
        }

        if (empty($partitions)) {
            return '未找到任何可识别的 Linux 文件系统分区（已尝试普通分区和 LVM）';
        }

        // 第二步：逐个尝试挂载，找到含 /etc/shadow 的根分区
        $root_partition = '';
        foreach ($partitions as $p) {
            $dev = $p['dev'];
            $check = $this->exec("guestfish -a " . escapeshellarg($disk_file) . " run : mount " . escapeshellarg($dev) . " / : exists /etc/shadow 2>&1");
            if ($check !== false && trim($check) === 'true') {
                $root_partition = $dev;
                break;
            }
        }

        if (empty($root_partition)) {
            return '在所有分区中都未找到 /etc/shadow 文件，无法确定根分区';
        }

        // 第三步：写入新的 root 密码哈希到 /etc/shadow
        // 使用 guestfish 内的 sed 命令直接修改 /etc/shadow 中的 root 行
        // 注意：guestfish 的 vi 命令只接受文件名，不接受 sed 表达式，必须用 command 执行 sed
        $sed_expr = 's/^root:[^:]*:/root:' . $crypted_pwd_escaped . ':/';
        $cmd = "guestfish -a " . escapeshellarg($disk_file) . " run : mount " . escapeshellarg($root_partition) . " / : command 'sed -i \"" . $sed_expr . "\" /etc/shadow' : chmod 0 /etc/shadow : chown 0 0 /etc/shadow 2>&1";

        $result = $this->exec($cmd);
        if ($result === false) {
            return 'guestfish 执行 sed 修改失败';
        }
        if (stripos($result, 'error') !== false || stripos($result, 'not found') !== false || stripos($result, 'cannot') !== false) {
            return 'guestfish 修改 /etc/shadow 失败: ' . trim($result);
        }

        // 第四步：验证修改是否成功
        $verify = $this->exec("guestfish -a " . escapeshellarg($disk_file) . " run : mount " . escapeshellarg($root_partition) . " / : cat /etc/shadow 2>&1");
        if ($verify !== false && strpos($verify, 'root:') !== false) {
            // 提取 root 行验证哈希已更新
            if (preg_match('/^root:\$6\$/m', $verify)) {
                return true;
            }
        }

        return '密码修改验证失败：/etc/shadow 中 root 行未更新';
    }

    /**
     * 创建一个KVM虚拟机
     * @param string $name 虚拟机名（唯一）
     * @param int $vcpu CPU核数
     * @param int $memory_mb 内存(MB)
     * @param int $disk_gb 磁盘(GB)
     * @param string $image_path 系统镜像（ISO路径，绝对路径或宿主机已知文件名）
     * @param string $os_type linux|windows
     * @param array $options 额外选项：
     *   - disk_type: 磁盘格式 (qcow2, raw, img, vmdk, vdi)，默认 qcow2
     *   - preinstalled_image: 预装好的系统镜像路径，如果提供则直接克隆使用
     *   - clone_image: 是否克隆预装镜像（如果为true且提供了preinstalled_image）
     * @return array [success, vm_name, uuid, ip_guess, root_password, vnc_port, log]
     */
    public function createVM($name, $vcpu, $memory_mb, $disk_gb, $image_path, $os_type = 'linux', $options = []) {
        $name = preg_replace('/[^a-z0-9_-]/i', '', $name);
        if (empty($name)) {
            $this->lastError = '无效的VM名称';
            return ['success' => false, 'message' => '无效的VM名称'];
        }

        // 获取磁盘格式，默认 qcow2
        $disk_type = $options['disk_type'] ?? 'qcow2';
        $disk_type = in_array($disk_type, ['qcow2', 'raw', 'img', 'vmdk', 'vdi']) ? $disk_type : 'qcow2';
        
        // 获取预装镜像路径
        $preinstalled_image = $options['preinstalled_image'] ?? '';
        $clone_image = $options['clone_image'] ?? true;
        $root_pwd = !empty($options['root_password']) ? $options['root_password'] : $this->generateRootPassword();
        $use_cloudinit_fallback = false; // 是否使用 cloud-init ISO 作为密码兜底

        // 检查虚拟机是否已存在，如果存在先删除
        if ($this->vmExists($name)) {
            $this->exec('virsh destroy ' . escapeshellarg($name) . ' 2>/dev/null');
            $this->exec('virsh undefine ' . escapeshellarg($name) . ' 2>/dev/null');
        }

        $uuid = $this->generateUUID();
        $disk_file = rtrim($this->storagePool, '/') . '/' . $name . '.' . $disk_type;
        $vnc_port = 5900 + rand(0, 200);
        $ssh_port = $vnc_port + 10000;

        $log = '';

        // 1. 删除旧磁盘文件（如果存在）
        $this->exec('rm -f ' . escapeshellarg($disk_file) . ' 2>/dev/null');

        // 2. 创建或克隆磁盘
        $has_preinstalled = false;
        if (!empty($preinstalled_image)) {
            // 使用 qemu-img info 验证镜像是否真正可用
            $img_check = $this->exec('qemu-img info ' . escapeshellarg($preinstalled_image) . ' 2>&1');
            if ($img_check !== false && strpos($img_check, 'file format:') !== false && strpos($img_check, 'error') === false) {
                $has_preinstalled = true;
                $log .= "预装镜像验证: OK (使用qemu-img验证)\n";
            } elseif (file_exists($preinstalled_image)) {
                // file_exists能找到但qemu-img读不了，可能是权限问题
                $log .= "预装镜像警告: 文件存在但qemu-img无法读取（可能是权限问题），仍尝试使用\n";
                $has_preinstalled = true;
            } else {
                $log .= "预装镜像: 文件不存在或无法读取\n";
                $this->lastError = "预装系统镜像不可用: {$preinstalled_image}。请检查镜像文件路径是否正确，以及文件是否存在且有读取权限。";
                return ['success' => false, 'message' => $this->lastError, 'log' => $log];
            }
        }

        if ($has_preinstalled) {
            // 使用预装镜像
            $log .= "使用预装镜像: {$preinstalled_image}\n";
            
            // 如果需要克隆，使用 backing file（写时复制）方式，秒级创建
            if ($clone_image) {
                // 获取预装镜像格式
                $img_info = $this->exec('qemu-img info ' . escapeshellarg($preinstalled_image) . ' 2>&1');
                $source_format = 'raw';
                if (preg_match('/file format:\s*(\w+)/i', $img_info, $m)) {
                    $source_format = $m[1];
                }
                
                // 使用 backing file 方式创建磁盘（写时复制，秒级创建）
                $use_backing = ($disk_type === 'qcow2' && $source_format === 'qcow2');
                
                if ($use_backing) {
                    // qcow2 -> qcow2：使用 backing file，秒级创建
                    $create_cmd = sprintf(
                        'qemu-img create -f qcow2 -o backing_file=%s,backing_fmt=qcow2,lazy_refcounts=on %s %dG 2>&1',
                        escapeshellarg($preinstalled_image),
                        escapeshellarg($disk_file),
                        $disk_gb
                    );
                    $r = $this->exec($create_cmd);
                    if ($r === false || strpos($r, 'error') !== false) {
                        // backing file 创建失败，回退到完整克隆
                        $log .= "backing file创建失败，回退到完整克隆: " . trim($r) . "\n";
                        $use_backing = false;
                    } else {
                        $log .= "磁盘创建 (backing file): OK (秒级创建)\n";
                    }
                }
                
                if (!$use_backing) {
                    // 完整克隆（fallback）
                    if ($source_format !== $disk_type) {
                        $log .= "转换镜像格式: {$source_format} -> {$disk_type}\n";
                        $temp_disk = rtrim($this->storagePool, '/') . '/' . $name . '_temp.' . pathinfo($preinstalled_image, PATHINFO_EXTENSION);
                        $this->exec('rm -f ' . escapeshellarg($temp_disk) . ' 2>/dev/null');
                        $r = $this->exec(sprintf('qemu-img convert -O %s %s %s 2>&1', $disk_type, escapeshellarg($preinstalled_image), escapeshellarg($temp_disk)));
                        if ($r === false || strpos($r, 'error') !== false) {
                            $this->lastError = '镜像转换失败: ' . $r;
                            return ['success' => false, 'message' => $this->lastError, 'log' => $log];
                        }
                        $this->exec(sprintf('qemu-img resize %s %dG 2>/dev/null', escapeshellarg($temp_disk), $disk_gb));
                        $this->exec('mv ' . escapeshellarg($temp_disk) . ' ' . escapeshellarg($disk_file));
                    } else {
                        $r = $this->exec(sprintf('qemu-img convert -O %s %s %s 2>&1', $disk_type, escapeshellarg($preinstalled_image), escapeshellarg($disk_file)));
                        if ($r === false || strpos($r, 'error') !== false) {
                            $this->lastError = '镜像克隆失败: ' . $r;
                            return ['success' => false, 'message' => $this->lastError, 'log' => $log];
                        }
                        $this->exec(sprintf('qemu-img resize %s %dG 2>/dev/null', escapeshellarg($disk_file), $disk_gb));
                    }
                    $log .= "镜像克隆: OK\n";
                }

                // 密码设置（Linux系统）—— 4 级 fallback 方案
                // 优先级别：virt-customize → virt-sysprep → guestfish → cloud-init ISO
                $password_set = false;
                $password_errors = [];
                if ($os_type === 'linux') {
                    // ====== 第 1 级：virt-customize（最可靠）======
                    if ($this->checkVirtCustomize()) {
                        $customize_cmd = sprintf(
                            'virt-customize -a %s --root-password password:%s 2>&1',
                            escapeshellarg($disk_file),
                            escapeshellarg($root_pwd)
                        );
                        $customize_result = $this->exec($customize_cmd);
                        if ($customize_result !== false && stripos($customize_result, 'error') === false && stripos($customize_result, 'failed') === false) {
                            $log .= "密码设置 (virt-customize): OK\n";
                            $password_set = true;
                        } else {
                            $err_msg = $customize_result ? trim($customize_result) : '命令执行失败';
                            // 截取最后 200 字符作为错误摘要
                            if (strlen($err_msg) > 200) {
                                $err_msg = substr($err_msg, -200);
                            }
                            $password_errors[] = "virt-customize: {$err_msg}";
                        }
                    } else {
                        $password_errors[] = "virt-customize: 命令不可用（未安装）";
                    }

                    // ====== 第 2 级：virt-sysprep（备选，部分系统可能只装了这个）======
                    if (!$password_set && $this->checkVirtSysprep()) {
                        $sysprep_cmd = sprintf(
                            'virt-sysprep -a %s --root-password password:%s --enable customize 2>&1',
                            escapeshellarg($disk_file),
                            escapeshellarg($root_pwd)
                        );
                        $sysprep_result = $this->exec($sysprep_cmd);
                        if ($sysprep_result !== false && stripos($sysprep_result, 'error') === false && stripos($sysprep_result, 'failed') === false) {
                            $log .= "密码设置 (virt-sysprep): OK\n";
                            $password_set = true;
                        } else {
                            $err_msg = $sysprep_result ? trim($sysprep_result) : '命令执行失败';
                            if (strlen($err_msg) > 200) {
                                $err_msg = substr($err_msg, -200);
                            }
                            $password_errors[] = "virt-sysprep: {$err_msg}";
                        }
                    } elseif (!$password_set) {
                        $password_errors[] = "virt-sysprep: 命令不可用（未安装）";
                    }

                    // ====== 第 3 级：guestfish（底层直接改 /etc/shadow）======
                    if (!$password_set) {
                        $guestfish_result = $this->setPasswordViaGuestfish($disk_file, $root_pwd);
                        if ($guestfish_result === true) {
                            $log .= "密码设置 (guestfish): OK\n";
                            $password_set = true;
                        } else {
                            $password_errors[] = "guestfish: " . (is_string($guestfish_result) ? $guestfish_result : '未知错误');
                        }
                    }

                    // ====== 第 4 级：cloud-init ISO（最终兜底，VM 首次启动时由 cloud-init 设置密码）======
                    // 注意：这要求预装镜像中已安装 cloud-init 包。
                    // 如果镜像不支持 cloud-init，VM 启动后密码仍为默认，但至少 VM 能成功创建。
                    if (!$password_set) {
                        $log .= "警告: libguestfs 三种方式均失败，尝试使用 cloud-init ISO 作为兜底方案...\n";
                        // cloud-init ISO 在后面统一创建，这里只标记使用 cloud-init 方式
                        // 通过设置一个标志位，让后面 buildDomainXML 时包含 cloud-init 光驱
                        $use_cloudinit_fallback = true;
                        $password_set = true; // 标记为成功（在 VM 首次启动时生效）
                        $log .= "密码设置 (cloud-init ISO 兜底): 已启用（VM 首次启动后生效）\n";
                    }

                    // ====== 全部失败时才中止 ======
                    if (!$password_set) {
                        // 先做环境诊断，给出更精准的错误提示
                        $diag = $this->diagnoseGuestfsEnvironment();
                        $diag_msg = '';
                        if (!empty($diag['errors'])) {
                            $diag_msg = "环境问题：" . implode("；", $diag['errors']) . "。";
                        }
                        if (!empty($diag['warnings'])) {
                            $diag_msg .= "警告：" . implode("；", $diag['warnings']) . "。";
                        }

                        $log .= "错误: 所有密码设置方式均失败，VM 创建中止\n";
                        $log .= "各级失败原因：\n";
                        foreach ($password_errors as $err) {
                            $log .= "  - {$err}\n";
                        }
                        if ($diag_msg) {
                            $log .= "环境诊断：{$diag_msg}\n";
                        }

                        // 清理已创建的磁盘文件
                        $this->exec('rm -f ' . escapeshellarg($disk_file) . ' 2>/dev/null');

                        $user_error = '密码设置失败：virt-customize / virt-sysprep / guestfish 均未成功设置 root 密码。';
                        if (!empty($diag['errors'])) {
                            $user_error .= ' 可能原因：' . implode("；", $diag['errors']);
                        } else {
                            $user_error .= ' 请检查 libguestfs-tools 是否安装、/dev/kvm 是否可用、磁盘文件权限是否正确。';
                        }
                        $this->lastError = $user_error;
                        return [
                            'success' => false,
                            'message' => $this->lastError,
                            'log' => $log,
                        ];
                    }
                }
            } else {
                // 直接使用镜像（不克隆，适用于只读共享镜像）
                $log .= "使用共享镜像: {$preinstalled_image}\n";
            }
        } else {
            // 创建空白磁盘
            $r = $this->exec(sprintf('qemu-img create -f %s %s %dG 2>&1', $disk_type, escapeshellarg($disk_file), $disk_gb));
            if ($r === false) {
                $this->lastError = '磁盘创建失败';
                return ['success' => false, 'message' => '磁盘创建失败: ' . $disk_file];
            }
            $log .= "磁盘创建 ({$disk_type}): OK\n";
        }

        // 3. 创建cloud-init ISO（Linux系统使用）
        $cloud_init_iso = '';
        if ($os_type === 'linux') {
            $cloud_init_iso = $this->createCloudInitISO($name, $root_pwd);
            if (!empty($cloud_init_iso)) {
                $log .= "cloud-init ISO: OK\n";
            } else {
                // cloud-init ISO 创建失败处理：
                // - 如果前面 libguestfs 已成功设置密码，则 ISO 非必需，继续创建 VM
                // - 如果使用了 cloud-init 兜底（前面 3 种方式都失败），则 ISO 是密码的唯一来源，必须中止
                if (!empty($use_cloudinit_fallback)) {
                    $log .= "错误: cloud-init ISO 创建失败，且 libguestfs 三种方式均失败，密码无法设置，VM 创建中止\n";
                    $this->exec('rm -f ' . escapeshellarg($disk_file) . ' 2>/dev/null');
                    $this->lastError = '密码设置失败：libguestfs 工具不可用且 cloud-init ISO 创建失败（genisoimage/mkisofs 自动安装未成功）。请手动执行：yum install -y genisoimage mkisofs 或 apt-get install -y genisoimage mkisofs';
                    return [
                        'success' => false,
                        'message' => $this->lastError,
                        'log' => $log,
                    ];
                }
                $log .= "cloud-init ISO: 创建失败（使用手动安装模式，密码已在镜像中设置）\n";
                $cloud_init_iso = '';
            }
        }

        // 4. 定义libvirt域XML
        $boot_from_hd = $has_preinstalled;
        $xml = $this->buildDomainXML($name, $vcpu, $memory_mb, $disk_file, $image_path, $vnc_port, $os_type, $cloud_init_iso, $disk_type, $boot_from_hd);
        $tmp_xml = '/tmp/vm_' . $name . '_' . time() . '.xml';
        $escaped_xml = str_replace("'", "'\"'\"'", $xml);
        $this->exec("echo '" . $escaped_xml . "' > " . $tmp_xml);
        $r = $this->exec('virsh define ' . $tmp_xml . ' 2>&1');
        if ($r === false) {
            $this->lastError = 'virsh define 失败';
            return ['success' => false, 'message' => $this->lastError, 'log' => $log];
        }
        if (strpos($r, 'error') !== false || strpos($r, '错误') !== false) {
            $this->lastError = 'virsh define 失败: ' . $r;
            return ['success' => false, 'message' => $this->lastError, 'log' => $log];
        }
        $log .= "virsh define: OK\n";

        // 5. 启动虚拟机
        $r = $this->exec('virsh start ' . escapeshellarg($name) . ' 2>&1');
        if ($r === false) {
            $this->lastError = 'virsh start 失败';
            return ['success' => false, 'message' => $this->lastError, 'log' => $log];
        }
        if (strpos($r, 'error') !== false || strpos($r, '错误') !== false) {
            $this->lastError = 'virsh start 失败: ' . $r;
            return ['success' => false, 'message' => $this->lastError, 'log' => $log];
        }
        $log .= "virsh start: OK\n";

        // 6. 开启自动启动
        @$this->exec('virsh autostart ' . escapeshellarg($name));

        // 7. 快速获取IP（只等2次共4秒，提高创建速度，IP可后续刷新获取）
        $ip = '';
        for ($tries = 0; $tries < 2; $tries++) {
            sleep(2);
            $result = $this->exec('virsh domifaddr ' . escapeshellarg($name) . ' 2>&1');
            if (preg_match('/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/', $result, $m)) {
                $ip = $m[1];
                break;
            }
        }
        $log .= "IP: " . ($ip ?: '等待DHCP分配，稍后刷新') . "\n";

        return [
            'success' => true,
            'vm_name' => $name,
            'uuid' => $uuid,
            'ip_address' => $ip,
            'root_password' => $root_pwd,
            'vnc_port' => $vnc_port,
            'ssh_port' => $ssh_port,
            'disk_file' => $disk_file,
            'log' => $log,
            'public_domain' => $this->publicDomain,
            'cloudinit_fallback' => !empty($use_cloudinit_fallback),
        ];
    }

    /**
     * 查找 QEMU emulator 路径
     */
    private function findQemuEmulator() {
        // 常见路径
        $paths = [
            '/usr/bin/qemu-system-x86_64',
            '/usr/bin/qemu-kvm',
            '/usr/libexec/qemu-kvm',
            '/usr/local/bin/qemu-system-x86_64',
        ];

        foreach ($paths as $p) {
            if (@file_exists($p)) {
                return $p;
            }
        }

        // 使用 which 查找
        $out = [];
        @exec("which qemu-system-x86_64 2>/dev/null", $out);
        if (!empty($out)) {
            return trim($out[0]);
        }

        @exec("which qemu-kvm 2>/dev/null", $out);
        if (!empty($out)) {
            return trim($out[0]);
        }

        // 默认返回
        return '/usr/bin/qemu-system-x86_64';
    }

    /**
     * 生成cloud-init ISO镜像（用于无人值守安装）
     */
    private function createCloudInitISO($name, $root_password, $ssh_keys = []) {
        $iso_dir = rtrim($this->storagePool, '/') . '/cloudinit';
        $this->exec('mkdir -p ' . escapeshellarg($iso_dir));

        // 检查 ISO 创建工具是否可用，不可用则自动安装
        if (!$this->checkIsoTools()) {
            $this->exec('echo "[KvmManager] genisoimage/mkisofs 未检测到，正在自动安装..."');
            $installed = $this->installIsoTools();
            if (!$installed) {
                $this->lastError = 'cloud-init ISO创建失败：genisoimage/mkisofs 自动安装失败，请手动安装';
                return null;
            }
        }
        
        $user_data = <<<EOF
#cloud-config
hostname: {$name}
manage_etc_hosts: true
users:
  - name: root
    ssh_authorized_keys:
EOF;
        foreach ($ssh_keys as $key) {
            $user_data .= "      - " . trim($key) . "\n";
        }
        $user_data .= <<<EOF
    sudo: ['ALL=(ALL) NOPASSWD:ALL']
    groups: sudo
    shell: /bin/bash
password: {$root_password}
chpasswd:
  expire: false
ssh_pwauth: true
packages:
  - openssh-server
  - qemu-guest-agent
runcmd:
  - systemctl enable ssh
  - systemctl start ssh
  - systemctl enable qemu-guest-agent
  - systemctl start qemu-guest-agent
  - if command -v yum &> /dev/null; then yum install -y qemu-guest-agent || true; elif command -v apt-get &> /dev/null; then apt-get update && apt-get install -y qemu-guest-agent || true; fi
  - systemctl restart qemu-guest-agent || true
EOF;

        $meta_data = "instance-id: {$name}\nlocal-hostname: {$name}\n";
        
        $user_data_path = $iso_dir . '/user-data';
        $meta_data_path = $iso_dir . '/meta-data';
        $iso_path = $iso_dir . '/' . $name . '_cloudinit.iso';
        
        $this->exec("printf '%s' '" . str_replace("'", "'\"'\"'", $user_data) . "' > " . escapeshellarg($user_data_path));
        $this->exec("printf '%s' '" . str_replace("'", "'\"'\"'", $meta_data) . "' > " . escapeshellarg($meta_data_path));
        
        $this->exec('rm -f ' . escapeshellarg($iso_path));
        $this->exec('genisoimage -output ' . escapeshellarg($iso_path) . ' -volid CIDATA -joliet -rock ' . escapeshellarg($user_data_path) . ' ' . escapeshellarg($meta_data_path) . ' 2>/dev/null || mkisofs -output ' . escapeshellarg($iso_path) . ' -volid CIDATA -joliet -rock ' . escapeshellarg($user_data_path) . ' ' . escapeshellarg($meta_data_path) . ' 2>/dev/null');
        
        if (!file_exists($iso_path)) {
            $this->lastError = 'cloud-init ISO创建失败';
            return null;
        }
        
        return $iso_path;
    }

    /**
     * 构造libvirt domain XML（支持cloud-init和多种磁盘格式）
     */
    private function buildDomainXML($name, $vcpu, $memory_mb, $disk_file, $image_path, $vnc_port, $os_type, $cloud_init_iso = '', $disk_type = 'qcow2', $boot_from_hd = false) {
        $mac = $this->generateMac();
        $memory_kb = $memory_mb * 1024;
        $os_variant = ($os_type === 'windows') ? 'win10' : 'ubuntu22.04';
        $emulator = $this->findQemuEmulator();
        
        // 磁盘格式映射到正确的驱动类型
        $disk_driver_type = $disk_type;
        
        $cloud_init_disk = '';
        if (!empty($cloud_init_iso)) {
            $cloud_init_disk = <<<XML
    <disk type='file' device='cdrom'>
      <driver name='qemu' type='raw'/>
      <source file='{$cloud_init_iso}'/>
      <target dev='hdb' bus='ide'/>
      <readonly/>
    </disk>
XML;
        }

        $boot_order = $boot_from_hd ? "    <boot dev='hd'/>\n    <boot dev='cdrom'/>" : "    <boot dev='cdrom'/>\n    <boot dev='hd'/>";

        $xml = <<<XML
<domain type='kvm' xmlns:qemu='http://libvirt.org/schemas/domain/qemu/1.0'>
  <name>{$name}</name>
  <memory unit='KiB'>{$memory_kb}</memory>
  <currentMemory unit='KiB'>{$memory_kb}</currentMemory>
  <vcpu placement='static'>{$vcpu}</vcpu>
  <os>
    <type arch='x86_64' machine='pc'>hvm</type>
{$boot_order}
  </os>
  <features>
    <acpi/>
    <apic/>
    <pae/>
  </features>
  <cpu mode='host-passthrough'>
    <feature policy='disable' name='rdtscp'/>
  </cpu>
  <clock offset='utc'/>
  <on_poweroff>destroy</on_poweroff>
  <on_reboot>restart</on_reboot>
  <on_crash>destroy</on_crash>
  <devices>
    <emulator>{$emulator}</emulator>
    <disk type='file' device='disk'>
      <driver name='qemu' type='{$disk_driver_type}' cache='none' discard='unmap'/>
      <source file='{$disk_file}'/>
      <target dev='vda' bus='virtio'/>
    </disk>
    <disk type='file' device='cdrom'>
      <driver name='qemu' type='raw'/>
      <source file='{$image_path}'/>
      <target dev='hda' bus='ide'/>
      <readonly/>
    </disk>
{$cloud_init_disk}
    <interface type='network'>
      <mac address='{$mac}'/>
      <source network='default'/>
      <model type='virtio'/>
      <filterref filter='clean-traffic'/>
    </interface>
    <input type='tablet' bus='usb'/>
    <input type='mouse' bus='ps2'/>
    <controller type='usb' index='0' model='nec-xhci'>
      <address type='pci' domain='0x0000' bus='0x00' slot='0x05' function='0x0'/>
    </controller>
    <graphics type='vnc' port='{$vnc_port}' autoport='no' listen='127.0.0.1' keymap='en-us'>
      <listen type='address' address='127.0.0.1'/>
    </graphics>
    <video>
      <model type='cirrus' vram='16384' heads='1'/>
    </video>
    <rng model='virtio'>
      <rate period='1000' bytes='1024'/>
      <backend model='random'>/dev/urandom</backend>
    </rng>
    <memballoon model='virtio'>
      <stats period='10'/>
    </memballoon>
  </devices>
  {$this->buildSeclabelXml()}
  <qemu:commandline>
    <qemu:arg value='-sandbox'/>
    <qemu:arg value='on,obsolete=deny,elevateprivileges=deny,spawn=deny,resourcecontrol=deny'/>
    <qemu:arg value='-S'/>
  </qemu:commandline>
</domain>
XML;
        return $xml;
    }

    /**
     * 销毁虚拟机（不可逆，删除磁盘）
     */
    public function destroyVM($name) {
        $this->exec('virsh shutdown ' . escapeshellarg($name));
        sleep(2);
        $this->exec('virsh destroy ' . escapeshellarg($name));
        $this->exec('virsh undefine ' . escapeshellarg($name));
        $disk = rtrim($this->storagePool, '/') . '/' . $name . '.qcow2';
        $this->exec('rm -f ' . escapeshellarg($disk));
        return true;
    }

    /**
     * 停止
     */
    public function stopVM($name) {
        $r = $this->exec('virsh shutdown ' . escapeshellarg($name));
        return $r !== false;
    }

    /**
     * 获取网络流量统计
     */
    public function getNetworkStats($name) {
        $output = $this->exec('virsh domstats ' . escapeshellarg($name) . ' --interface');
        if ($output === false) {
            return false;
        }
        
        $rx_bytes = 0;
        $tx_bytes = 0;
        
        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            if (preg_match('/net\.(\d+)\.rx\.bytes\s*=\s*(\d+)/', $line, $matches)) {
                $rx_bytes += intval($matches[2]);
            }
            if (preg_match('/net\.(\d+)\.tx\.bytes\s*=\s*(\d+)/', $line, $matches)) {
                $tx_bytes += intval($matches[2]);
            }
        }
        
        return [
            'rx_bytes' => $rx_bytes,
            'tx_bytes' => $tx_bytes,
            'total_bytes' => $rx_bytes + $tx_bytes
        ];
    }

    /**
     * 强制停止
     */
    public function forceStopVM($name) {
        $r = $this->exec('virsh destroy ' . escapeshellarg($name));
        return $r !== false;
    }

    /**
     * 启动
     */
    public function startVM($name) {
        $r = $this->exec('virsh start ' . escapeshellarg($name));
        return $r !== false;
    }

    /**
     * 重启
     */
    public function restartVM($name) {
        $this->exec('virsh reboot ' . escapeshellarg($name));
        return true;
    }

    /**
     * 暂停虚拟机（挂起到内存，运行状态保留）
     */
    public function suspendVM($name) {
        $r = $this->exec('virsh suspend ' . escapeshellarg($name));
        return $r !== false;
    }

    /**
     * 恢复暂停的虚拟机
     */
    public function resumeVM($name) {
        $r = $this->exec('virsh resume ' . escapeshellarg($name));
        return $r !== false;
    }

    /**
     * 休眠虚拟机（保存状态到磁盘并停止）
     */
    public function saveVM($name, $snapshot_path = '') {
        if (empty($snapshot_path)) {
            $snapshot_path = rtrim($this->storagePool, '/') . '/' . $name . '_saved.state';
        }
        $r = $this->exec('virsh save ' . escapeshellarg($name) . ' ' . escapeshellarg($snapshot_path));
        if ($r === false) return false;
        return ['success' => true, 'save_path' => $snapshot_path];
    }

    /**
     * 从休眠状态恢复虚拟机
     */
    public function restoreVM($name, $snapshot_path = '') {
        if (empty($snapshot_path)) {
            $snapshot_path = rtrim($this->storagePool, '/') . '/' . $name . '_saved.state';
        }
        if (!file_exists($snapshot_path) && !$this->isLocalHost()) {
            $check = $this->exec('test -f ' . escapeshellarg($snapshot_path) . ' && echo exists');
            if (strpos($check, 'exists') === false) {
                $this->lastError = '休眠文件不存在: ' . $snapshot_path;
                return false;
            }
        }
        $r = $this->exec('virsh restore ' . escapeshellarg($snapshot_path));
        return $r !== false;
    }

    /**
     * 在线热迁移虚拟机到目标节点
     * 注意：需要共享存储支持，目标节点需能访问相同镜像文件
     */
    public function migrateVM($name, $target_host, $target_user = 'root', $options = []) {
        $live = !empty($options['live']) ? '--live' : '';
        $unsafe = !empty($options['unsafe']) ? '--unsafe' : '';
        $timeout = intval($options['timeout'] ?? 300);
        $target_uri = 'qemu+ssh://' . $target_user . '@' . $target_host . '/system';
        $cmd = 'virsh migrate ' . $live . ' ' . $unsafe . ' ' . escapeshellarg($name) . ' ' . escapeshellarg($target_uri) . ' --timeout ' . $timeout;
        $r = $this->exec($cmd);
        if ($r === false) {
            return ['success' => false, 'message' => $this->lastError ?: '迁移失败'];
        }
        return ['success' => true, 'message' => '迁移完成'];
    }

    /**
     * 获取电源状态
     */
    public function getVMPowerState($name) {
        $r = $this->exec('virsh domstate ' . escapeshellarg($name));
        if ($r === false) return 'unknown';
        if (strpos($r, 'running') !== false) return 'running';
        if (strpos($r, 'shut off') !== false) return 'stopped';
        if (strpos($r, 'paused') !== false) return 'paused';
        if (strpos($r, 'saved') !== false) return 'saved';
        if (strpos($r, 'suspended') !== false) return 'paused';
        if (strpos($r, 'crashed') !== false) return 'crashed';
        if (strpos($r, 'dying') !== false) return 'dying';
        return 'unknown';
    }

    /**
     * 获取虚拟机资源使用情况
     * 社区版：仅使用virsh命令
     */
    public function getVMUsage($name) {
        $result = $this->getVMUsageViaVirsh($name);
        if ($result === false) {
            return ['success' => false, 'error' => $this->lastError ?: '获取失败'];
        }
        $result['success'] = true;
        return $result;
    }
    
    /**
     * 通过virsh命令获取虚拟机资源使用情况（原有逻辑）
     * @return array|false 成功返回数组，失败返回false
     */
    private function getVMUsageViaVirsh($name) {
        $result = [
            'cpu_usage' => 0,
            'memory_used' => 0,
            'memory_total' => 0,
            'disk_used' => 0,
            'disk_total' => 0,
            'network_rx' => 0,
            'network_tx' => 0,
            'rx_speed' => 0,
            'tx_speed' => 0,
            'disk_read_mb' => 0,
            'disk_write_mb' => 0,
            'memory_percent' => 0,
        ];

        // 1. dominfo - 内存、CPU核心数
        $dominfo = $this->exec('virsh dominfo ' . escapeshellarg($name) . ' 2>&1');
        if ($dominfo === false) return false;

        if (preg_match('/Max memory:\s+(\d+) KiB/i', $dominfo, $m)) {
            $result['memory_total'] = round(intval($m[1]) / 1024, 2);
        }
        if (preg_match('/Used memory:\s+(\d+) KiB/i', $dominfo, $m)) {
            $result['memory_used'] = round(intval($m[1]) / 1024, 2);
        }

        $vcpu_count = 1;
        if (preg_match('/CPU\(s\):\s+(\d+)/i', $dominfo, $m)) {
            $vcpu_count = intval($m[1]);
        }

        // 2. dommemstat - 精确内存使用
        $memstat = $this->exec('virsh dommemstat ' . escapeshellarg($name) . ' 2>&1');
        if ($memstat !== false) {
            $rss = 0;
            $actual = 0;
            if (preg_match('/rss\s+(\d+)/i', $memstat, $m)) $rss = intval($m[1]);
            if (preg_match('/actual\s+(\d+)/i', $memstat, $m)) $actual = intval($m[1]);
            if ($actual > 0 && $rss > 0) {
                $result['memory_used'] = round($rss / 1024, 2);
                $result['memory_total'] = round($actual / 1024, 2);
                $result['memory_percent'] = min(100, round($rss / $actual * 100, 1));
            }
        }
        if ($result['memory_percent'] == 0 && $result['memory_total'] > 0) {
            $result['memory_percent'] = round($result['memory_used'] / $result['memory_total'] * 100, 1);
        }

        // 3. cpu-stats - CPU使用率（需要两次采样）
        $cpu_stats = $this->exec('virsh cpu-stats ' . escapeshellarg($name) . ' 2>&1');
        if ($cpu_stats !== false) {
            $total_cpu_time = 0;
            if (preg_match_all('/total:\s+(\d+)/', $cpu_stats, $matches)) {
                foreach ($matches[1] as $val) $total_cpu_time += intval($val);
            }
            if ($total_cpu_time == 0 && preg_match('/cpu_time\s+(\d+)/i', $cpu_stats, $m)) {
                $total_cpu_time = intval($m[1]);
            }

            if ($total_cpu_time > 0) {
                $cache_file = sys_get_temp_dir() . '/kvm_cpu_' . md5($name) . '.json';
                $now = microtime(true);
                $prev = null;
                if (file_exists($cache_file)) {
                    $prev = @json_decode(@file_get_contents($cache_file), true);
                }
                if ($prev && isset($prev['time']) && isset($prev['cpu_time'])) {
                    $time_diff = $now - floatval($prev['time']);
                    $cpu_diff = $total_cpu_time - intval($prev['cpu_time']);
                    if ($time_diff > 0 && $cpu_diff >= 0) {
                        $usage = ($cpu_diff / 1000000000) / $time_diff / $vcpu_count * 100;
                        $result['cpu_usage'] = round(min(100, max(0, $usage)), 1);
                    }
                }
                @file_put_contents($cache_file, json_encode(['time' => $now, 'cpu_time' => $total_cpu_time]));
            }
        }

        // 4. qemu-img info - 磁盘总量和使用量
        $disk_path = rtrim($this->storagePool, '/') . '/' . $name . '.qcow2';
        $disk_info = $this->exec('qemu-img info ' . escapeshellarg($disk_path) . ' 2>&1');
        if ($disk_info !== false) {
            if (preg_match('/virtual size:\s+(\d+(?:\.\d+)?)\s*([KMGT]?B)/i', $disk_info, $m)) {
                $size = floatval($m[1]);
                $unit = strtoupper($m[2]);
                if ($unit === 'K' || $unit === 'KB') $size *= 1024;
                elseif ($unit === 'M' || $unit === 'MB') $size *= 1024 * 1024;
                elseif ($unit === 'G' || $unit === 'GB') $size *= 1024 * 1024 * 1024;
                elseif ($unit === 'T' || $unit === 'TB') $size *= 1024 * 1024 * 1024 * 1024;
                $result['disk_total'] = round($size / 1024 / 1024 / 1024, 2);
            }
            if (preg_match('/disk size:\s+(\d+(?:\.\d+)?)\s*([KMGT]?B)/i', $disk_info, $m)) {
                $size = floatval($m[1]);
                $unit = strtoupper($m[2]);
                if ($unit === 'K' || $unit === 'KB') $size *= 1024;
                elseif ($unit === 'M' || $unit === 'MB') $size *= 1024 * 1024;
                elseif ($unit === 'G' || $unit === 'GB') $size *= 1024 * 1024 * 1024;
                elseif ($unit === 'T' || $unit === 'TB') $size *= 1024 * 1024 * 1024 * 1024;
                $result['disk_used'] = round($size / 1024 / 1024 / 1024, 2);
            }
        }

        // 5. domstats --block - 网络流量 + 磁盘IO（一次调用获取所有数据）
        $domstats = $this->exec('virsh domstats ' . escapeshellarg($name) . ' --block 2>&1');
        if ($domstats !== false) {
            // 网络流量
            $rx_bytes = 0;
            $tx_bytes = 0;
            if (preg_match_all('/net\.\d+\.rx\.bytes\s+(\d+)/', $domstats, $matches)) {
                foreach ($matches[1] as $val) $rx_bytes += intval($val);
            }
            if (preg_match_all('/net\.\d+\.tx\.bytes\s+(\d+)/', $domstats, $matches)) {
                foreach ($matches[1] as $val) $tx_bytes += intval($val);
            }
            $result['network_rx'] = $rx_bytes;
            $result['network_tx'] = $tx_bytes;

            // 网络速率（需要两次采样）
            $net_cache = sys_get_temp_dir() . '/kvm_net_' . md5($name) . '.json';
            $now = microtime(true);
            $prev_net = null;
            if (file_exists($net_cache)) {
                $prev_net = @json_decode(@file_get_contents($net_cache), true);
            }
            if ($prev_net && isset($prev_net['time'])) {
                $time_diff = $now - floatval($prev_net['time']);
                if ($time_diff > 0 && $time_diff < 300) {
                    $rx_diff = max(0, $rx_bytes - intval($prev_net['rx'] ?? 0));
                    $tx_diff = max(0, $tx_bytes - intval($prev_net['tx'] ?? 0));
                    $result['rx_speed'] = round($rx_diff / $time_diff / 1024, 2);
                    $result['tx_speed'] = round($tx_diff / $time_diff / 1024, 2);
                }
            }
            @file_put_contents($net_cache, json_encode(['time' => $now, 'rx' => $rx_bytes, 'tx' => $tx_bytes]));

            // 磁盘IO（需要两次采样）
            $rd_bytes = 0;
            $wr_bytes = 0;
            if (preg_match_all('/block\.\d+\.rd\.bytes\s+(\d+)/', $domstats, $matches)) {
                foreach ($matches[1] as $val) $rd_bytes += intval($val);
            }
            if (preg_match_all('/block\.\d+\.wr\.bytes\s+(\d+)/', $domstats, $matches)) {
                foreach ($matches[1] as $val) $wr_bytes += intval($val);
            }

            $disk_cache = sys_get_temp_dir() . '/kvm_disk_' . md5($name) . '.json';
            $prev_disk = null;
            if (file_exists($disk_cache)) {
                $prev_disk = @json_decode(@file_get_contents($disk_cache), true);
            }
            if ($prev_disk && isset($prev_disk['time'])) {
                $time_diff = $now - floatval($prev_disk['time']);
                if ($time_diff > 0 && $time_diff < 300) {
                    $rd_diff = max(0, $rd_bytes - intval($prev_disk['rd_bytes'] ?? 0));
                    $wr_diff = max(0, $wr_bytes - intval($prev_disk['wr_bytes'] ?? 0));
                    $result['disk_read_mb'] = round($rd_diff / $time_diff / 1024 / 1024, 2);
                    $result['disk_write_mb'] = round($wr_diff / $time_diff / 1024 / 1024, 2);
                }
            }
            @file_put_contents($disk_cache, json_encode(['time' => $now, 'rd_bytes' => $rd_bytes, 'wr_bytes' => $wr_bytes]));
        }

        return $result;
    }

    /**
     * 获取虚拟机实时资源统计（社区版：仅通过 libvirt）
     */
    public function getVMStats($name) {
        $result = $this->getVMStatsViaLibvirt($name);
        if ($result === false) {
            return ['success' => false, 'error' => $this->lastError ?: '获取失败'];
        }
        $result['success'] = true;
        return $result;
    }
    
    /**
     * 通过libvirt获取虚拟机实时资源统计（原有逻辑）
     * @return array|false 成功返回数组，失败返回false
     */
    private function getVMStatsViaLibvirt($name) {
        $stats = [
            'cpu_percent' => 0,
            'memory_percent' => 0,
            'rx_bytes' => 0,
            'tx_bytes' => 0,
            'rx_speed' => 0,
            'tx_speed' => 0,
            'disk_read_mb' => 0,
            'disk_write_mb' => 0,
        ];

        $usage = $this->getVMUsage($name);
        if (!$usage || empty($usage['success'])) {
            return false;
        }
        $stats['cpu_percent'] = floatval($usage['cpu_usage'] ?? 0);
        $stats['rx_bytes'] = floatval($usage['network_rx'] ?? 0);
        $stats['tx_bytes'] = floatval($usage['network_tx'] ?? 0);
        $stats['rx_speed'] = floatval($usage['rx_speed'] ?? 0);
        $stats['tx_speed'] = floatval($usage['tx_speed'] ?? 0);

        if (!empty($usage['memory_total']) && $usage['memory_total'] > 0) {
            $stats['memory_percent'] = round($usage['memory_used'] / $usage['memory_total'] * 100, 1);
        }

        $memstat = $this->exec('virsh dommemstat ' . escapeshellarg($name) . ' 2>&1');
        if ($memstat !== false) {
            $rss = 0;
            $actual = 0;
            if (preg_match('/rss\s+(\d+)/i', $memstat, $m)) {
                $rss = intval($m[1]);
            }
            if (preg_match('/actual\s+(\d+)/i', $memstat, $m)) {
                $actual = intval($m[1]);
            }
            if ($actual > 0 && $rss > 0) {
                $stats['memory_percent'] = min(100, round($rss / $actual * 100, 1));
            }
        }

        $domstats_output = $this->exec('virsh domstats ' . escapeshellarg($name) . ' --block 2>&1');
        if ($domstats_output !== false) {
            $rd_req = 0;
            $wr_req = 0;
            $rd_bytes = 0;
            $wr_bytes = 0;

            if (preg_match_all('/block\.\d+\.rd\.req\s+(\d+)/', $domstats_output, $matches)) {
                foreach ($matches[1] as $val) $rd_req += intval($val);
            }
            if (preg_match_all('/block\.\d+\.wr\.req\s+(\d+)/', $domstats_output, $matches)) {
                foreach ($matches[1] as $val) $wr_req += intval($val);
            }
            if (preg_match_all('/block\.\d+\.rd\.bytes\s+(\d+)/', $domstats_output, $matches)) {
                foreach ($matches[1] as $val) $rd_bytes += intval($val);
            }
            if (preg_match_all('/block\.\d+\.wr\.bytes\s+(\d+)/', $domstats_output, $matches)) {
                foreach ($matches[1] as $val) $wr_bytes += intval($val);
            }

            $cache_dir = sys_get_temp_dir();
            $cache_file = $cache_dir . '/kvm_disk_' . md5($name) . '.json';
            $now = microtime(true);
            $prev = null;

            if (file_exists($cache_file)) {
                $prev_data = @file_get_contents($cache_file);
                if ($prev_data) {
                    $prev = json_decode($prev_data, true);
                }
            }

            if ($prev && isset($prev['time'])) {
                $time_diff = $now - floatval($prev['time']);
                if ($time_diff > 0 && $time_diff < 300) {
                    $rd_diff = max(0, $rd_bytes - intval($prev['rd_bytes'] ?? 0));
                    $wr_diff = max(0, $wr_bytes - intval($prev['wr_bytes'] ?? 0));
                    $stats['disk_read_mb'] = round($rd_diff / $time_diff / 1024 / 1024, 2);
                    $stats['disk_write_mb'] = round($wr_diff / $time_diff / 1024 / 1024, 2);
                }
            }

            @file_put_contents($cache_file, json_encode([
                'time' => $now,
                'rd_bytes' => $rd_bytes,
                'wr_bytes' => $wr_bytes,
            ]));
        }

        return $stats;
    }

    /**
     * 检查虚拟机是否存在于 libvirt
     */
    public function vmExists($name) {
        $r = $this->exec('virsh dominfo ' . escapeshellarg($name) . ' 2>&1');
        if ($r === false) return false;
        // 如果包含 "Id:" 或 "Name:" 说明存在
        if (strpos($r, 'Id:') !== false || strpos($r, 'Name:') !== false) {
            return true;
        }
        // 如果包含 "未找到域" 或 "not found" 说明不存在
        if (strpos($r, '未找到') !== false || strpos($r, 'not found') !== false || strpos($r, 'error:') !== false) {
            return false;
        }
        return false;
    }

    /**
     * 获取VM IP
     */
    public function getVMIP($name) {
        $r = $this->exec('virsh domifaddr ' . escapeshellarg($name));
        if (preg_match('/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/', $r, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * 获取虚拟机VNC显示端口
     */
    public function getVNCDisplay($name) {
        $r = $this->exec("virsh vncdisplay " . escapeshellarg($name) . " 2>/dev/null");
        return trim($r);
    }

    /**
     * 获取虚拟机XML配置
     */
    public function getDomainXML($name) {
        $r = $this->exec("virsh dumpxml " . escapeshellarg($name) . " 2>/dev/null");
        return trim($r);
    }

    /**
     * 调整虚拟机CPU数量
     * @param string $name 虚拟机名称
     * @param int $vcpu 新的CPU核心数
     * @return bool
     */
    public function setVCPU($name, $vcpu) {
        $vcpu = max(1, intval($vcpu));
        $state = $this->getVMPowerState($name);

        if ($state === 'running') {
            $r = $this->exec("virsh setvcpus " . escapeshellarg($name) . " " . $vcpu . " --live --config 2>&1");
            if (strpos($r, 'error') !== false && strpos($r, 'error') === 0) {
                $r = $this->exec("virsh setvcpus " . escapeshellarg($name) . " " . $vcpu . " --config 2>&1");
                if (strpos($r, 'error') !== false && strpos($r, 'error') === 0) {
                    $this->lastError = trim($r);
                    return false;
                }
            }
        } else {
            $r = $this->exec("virsh setvcpus " . escapeshellarg($name) . " " . $vcpu . " --config 2>&1");
            if (strpos($r, 'error') !== false && strpos($r, 'error') === 0) {
                $this->lastError = trim($r);
                return false;
            }
        }
        return true;
    }

    /**
     * 调整虚拟机内存
     * @param string $name 虚拟机名称
     * @param int $memory_mb 新的内存大小（MB）
     * @return bool
     */
    public function setMemory($name, $memory_mb) {
        $memory_mb = max(128, intval($memory_mb));
        $memory_kb = $memory_mb * 1024;
        $state = $this->getVMPowerState($name);

        if ($state === 'running') {
            $r = $this->exec("virsh setmem " . escapeshellarg($name) . " " . $memory_kb . " --live --config 2>&1");
            if (strpos($r, 'error') !== false && strpos($r, 'error') === 0) {
                $r = $this->exec("virsh setmem " . escapeshellarg($name) . " " . $memory_kb . " --config 2>&1");
                if (strpos($r, 'error') !== false && strpos($r, 'error') === 0) {
                    $this->lastError = trim($r);
                    return false;
                }
            }
            $r2 = $this->exec("virsh setmaxmem " . escapeshellarg($name) . " " . $memory_kb . " --config 2>&1");
        } else {
            $r = $this->exec("virsh setmem " . escapeshellarg($name) . " " . $memory_kb . " --config 2>&1");
            if (strpos($r, 'error') !== false && strpos($r, 'error') === 0) {
                $this->lastError = trim($r);
                return false;
            }
            $r2 = $this->exec("virsh setmaxmem " . escapeshellarg($name) . " " . $memory_kb . " --config 2>&1");
        }
        return true;
    }

    /**
     * 调整虚拟机磁盘大小（只能增大）
     * @param string $name 虚拟机名称
     * @param int $disk_gb 新的磁盘大小（GB）
     * @return bool
     */
    public function resizeDisk($name, $disk_gb) {
        $disk_gb = max(1, intval($disk_gb));
        $disk_file = rtrim($this->storagePool, '/') . '/' . $name . '.qcow2';

        $info = $this->exec("qemu-img info " . escapeshellarg($disk_file) . " 2>&1");
        if (preg_match('/virtual size:\s+(\d+(?:\.\d+)?)\s*G/i', $info, $m)) {
            $current_gb = floatval($m[1]);
            if ($disk_gb <= $current_gb) {
                $this->lastError = '磁盘大小只能增大，不能缩小（当前: ' . $current_gb . 'GB）';
                return false;
            }
        }

        $state = $this->getVMPowerState($name);
        if ($state === 'running') {
            $this->exec("virsh blockresize " . escapeshellarg($name) . " vda " . $disk_gb . "G 2>&1");
        }

        $r = $this->exec("qemu-img resize " . escapeshellarg($disk_file) . " " . $disk_gb . "G 2>&1");
        if (strpos($r, 'error') !== false || strpos($r, 'Error') !== false) {
            $this->lastError = trim($r);
            return false;
        }
        return true;
    }

    /**
     * 调整虚拟机规格（CPU、内存、磁盘）
     * @param string $name 虚拟机名称
     * @param int $vcpu CPU核心数
     * @param int $memory_mb 内存（MB）
     * @param int $disk_gb 磁盘（GB）
     * @return array [success, message, need_reboot]
     */
    public function resizeVM($name, $vcpu, $memory_mb, $disk_gb) {
        $result = ['success' => true, 'message' => '', 'need_reboot' => false];
        $errors = [];
        $state = $this->getVMPowerState($name);

        if ($vcpu > 0) {
            if (!$this->setVCPU($name, $vcpu)) {
                $errors[] = 'CPU调整失败: ' . $this->lastError;
            }
        }

        if ($memory_mb > 0) {
            if (!$this->setMemory($name, $memory_mb)) {
                $errors[] = '内存调整失败: ' . $this->lastError;
            }
        }

        if ($disk_gb > 0) {
            if (!$this->resizeDisk($name, $disk_gb)) {
                $errors[] = '磁盘调整失败: ' . $this->lastError;
            }
        }

        if (!empty($errors)) {
            $result['success'] = false;
            $result['message'] = implode('; ', $errors);
        } else {
            $result['message'] = '规格调整成功';
            if ($state !== 'running') {
                $result['need_reboot'] = false;
            } else {
                $result['need_reboot'] = true;
                $result['message'] .= '，部分更改需重启虚拟机后生效';
            }
        }
        return $result;
    }

    /**
     * 重装系统（保留VM，但重新从ISO引导）
     * @param string $name
     * @param string $new_image_path 新ISO路径
     * @param int $disk_gb 新磁盘大小
     */
    public function reinstallVM($name, $new_image_path, $disk_gb = 40, $disk_type = 'qcow2', $preinstalled_image = '', $root_password = '') {
        // 确保磁盘格式有效
        $disk_type = in_array($disk_type, ['qcow2', 'raw', 'img', 'vmdk', 'vdi']) ? $disk_type : 'qcow2';
        $root_pwd = !empty($root_password) ? $root_password : $this->generateRootPassword();
        
        // 1. 关闭虚拟机
        $this->exec('virsh destroy ' . escapeshellarg($name) . ' 2>/dev/null');
        sleep(3);

        // 2. 获取当前VM的XML配置（用于解析参数）
        $xml_content = $this->exec('virsh dumpxml ' . escapeshellarg($name));
        if ($xml_content === false || empty($xml_content)) {
            $this->lastError = '无法获取虚拟机XML配置';
            return false;
        }

        // 3. 从XML中提取配置参数
        $vcpu = 2;
        $memory_mb = 2048;
        $vnc_port = 5900;
        $mac = '';
        $os_type = 'linux';

        if (preg_match('/<vcpu[^>]*>(\d+)<\/vcpu>/', $xml_content, $m)) {
            $vcpu = intval($m[1]);
        }
        if (preg_match('/<memory[^>]*unit=\'KiB\'[^>]*>(\d+)<\/memory>/', $xml_content, $m)) {
            $memory_mb = intval($m[1]) / 1024;
        } elseif (preg_match('/<currentMemory[^>]*unit=\'KiB\'[^>]*>(\d+)<\/currentMemory>/', $xml_content, $m)) {
            $memory_mb = intval($m[1]) / 1024;
        }
        if (preg_match('/<graphics[^>]*type=\'vnc\'[^>]*port=\'(\d+)\'/', $xml_content, $m) ||
            preg_match('/<graphics[^>]*port=\'(\d+)\'[^>]*type=\'vnc\'/', $xml_content, $m)) {
            $vnc_port = intval($m[1]);
        }
        if (preg_match('/<mac[^>]*address=\'([^\']+)\'/', $xml_content, $m)) {
            $mac = $m[1];
        }
        if (preg_match('/<type[^>]*arch=\'x86_64\'[^>]*>hvm<\/type>/', $xml_content)) {
            $os_type = 'linux';
        }
        if (stripos($xml_content, 'win') !== false) {
            $os_type = 'windows';
        }

        // 4. 删除旧磁盘文件并创建新磁盘
        $disk_file = rtrim($this->storagePool, '/') . '/' . $name . '.' . $disk_type;
        $this->exec('rm -f ' . escapeshellarg($disk_file));
        
        // 验证预装镜像是否可用
        $has_preinstalled = false;
        if (!empty($preinstalled_image)) {
            $img_check = $this->exec('qemu-img info ' . escapeshellarg($preinstalled_image) . ' 2>&1');
            if ($img_check !== false && strpos($img_check, 'file format:') !== false && strpos($img_check, 'error') === false) {
                $has_preinstalled = true;
            } else {
                $this->lastError = "预装系统镜像不可用: {$preinstalled_image}。请检查镜像文件路径是否正确，以及文件是否存在且有读取权限。";
                return false;
            }
        }
        
        // 如果有预装镜像，直接克隆
        if ($has_preinstalled) {
            $clone_cmd = sprintf('qemu-img convert -O %s %s %s 2>&1', $disk_type, escapeshellarg($preinstalled_image), escapeshellarg($disk_file));
            $clone_result = $this->exec($clone_cmd);
            if ($clone_result === false || strpos($clone_result, 'error') !== false) {
                $this->lastError = '镜像克隆失败: ' . $clone_result;
                return false;
            }
            $this->exec(sprintf('qemu-img resize %s %dG 2>/dev/null', escapeshellarg($disk_file), $disk_gb));

            // 使用 4 级 fallback 方案设置 root 密码（预装镜像专用）
            if ($os_type === 'linux') {
                if (!$this->checkVirtCustomize()) {
                    @$this->installLibguestfsTools();
                }
                $password_set = false;
                $reinstall_cloudinit = false;

                // 第1级：virt-customize
                if ($this->checkVirtCustomize()) {
                    $customize_cmd = sprintf(
                        'virt-customize -a %s --root-password password:%s 2>&1',
                        escapeshellarg($disk_file),
                        escapeshellarg($root_pwd)
                    );
                    $customize_result = $this->exec($customize_cmd);
                    if ($customize_result !== false && stripos($customize_result, 'error') === false && stripos($customize_result, 'failed') === false) {
                        $password_set = true;
                    } else {
                        error_log("virt-customize密码设置失败: " . substr($customize_result, -200));
                    }
                }

                // 第2级：virt-sysprep
                if (!$password_set && $this->checkVirtSysprep()) {
                    $sysprep_cmd = sprintf(
                        'virt-sysprep -a %s --root-password password:%s --enable customize 2>&1',
                        escapeshellarg($disk_file),
                        escapeshellarg($root_pwd)
                    );
                    $sysprep_result = $this->exec($sysprep_cmd);
                    if ($sysprep_result !== false && stripos($sysprep_result, 'error') === false && stripos($sysprep_result, 'failed') === false) {
                        $password_set = true;
                    } else {
                        error_log("virt-sysprep密码设置失败: " . substr($sysprep_result, -200));
                    }
                }

                // 第3级：guestfish
                if (!$password_set) {
                    $guestfish_ok = $this->setPasswordViaGuestfish($disk_file, $root_pwd);
                    if ($guestfish_ok === true) {
                        $password_set = true;
                    } else {
                        error_log("guestfish密码设置失败: " . (is_string($guestfish_ok) ? $guestfish_ok : '未知错误'));
                    }
                }

                // 第4级：cloud-init ISO 兜底
                if (!$password_set) {
                    error_log("所有libguestfs方式均失败，使用cloud-init ISO兜底");
                    $reinstall_cloudinit = true;
                    $password_set = true; // 标记为成功（VM启动后生效）
                }
            }
        } else {
            // 创建空白磁盘（ISO安装模式）
            $this->exec(sprintf('qemu-img create -f %s %s %dG', $disk_type, escapeshellarg($disk_file), $disk_gb));
        }

        // 5. 如果没有MAC地址则生成一个
        if (empty($mac)) {
            $mac = $this->generateMac();
        }

        // 6. 重新生成VM的XML（使用新的ISO路径和磁盘格式）
        $memory_kb = $memory_mb * 1024;
        $emulator = $this->findQemuEmulator();

        $boot_order = $has_preinstalled ? "    <boot dev='hd'/>\n    <boot dev='cdrom'/>" : "    <boot dev='cdrom'/>\n    <boot dev='hd'/>";

        // 如果使用 cloud-init 兜底（重装时密码设置都失败了），生成 cloud-init ISO 并挂载为第二个光驱
        $cloudinit_disk_xml = '';
        if (!empty($reinstall_cloudinit) && $has_preinstalled && $os_type === 'linux') {
            $ci_iso = $this->createCloudInitISO($name, $root_pwd);
            if (!empty($ci_iso)) {
                $cloudinit_disk_xml = <<<CIXML
    <disk type='file' device='cdrom'>
      <driver name='qemu' type='raw'/>
      <source file='{$ci_iso}'/>
      <target dev='hdb' bus='ide'/>
      <readonly/>
    </disk>
CIXML;
            }
        }

        $new_xml = <<<XML
<domain type='kvm' xmlns:qemu='http://libvirt.org/schemas/domain/qemu/1.0'>
  <name>{$name}</name>
  <memory unit='KiB'>{$memory_kb}</memory>
  <currentMemory unit='KiB'>{$memory_kb}</currentMemory>
  <vcpu placement='static'>{$vcpu}</vcpu>
  <os>
    <type arch='x86_64' machine='pc'>hvm</type>
{$boot_order}
  </os>
  <features>
    <acpi/>
    <apic/>
    <pae/>
  </features>
  <cpu mode='host-passthrough'/>
  <clock offset='utc'/>
  <on_poweroff>destroy</on_poweroff>
  <on_reboot>restart</on_reboot>
  <on_crash>restart</on_crash>
  <devices>
    <emulator>{$emulator}</emulator>
    <disk type='file' device='disk'>
      <driver name='qemu' type='{$disk_type}' cache='none'/>
      <source file='{$disk_file}'/>
      <target dev='vda' bus='virtio'/>
    </disk>
    <disk type='file' device='cdrom'>
      <driver name='qemu' type='raw'/>
      <source file='{$new_image_path}'/>
      <target dev='hda' bus='ide'/>
      <readonly/>
    </disk>
{$cloudinit_disk_xml}
    <interface type='network'>
      <mac address='{$mac}'/>
      <source network='default'/>
      <model type='virtio'/>
    </interface>
    <input type='tablet' bus='usb'/>
    <input type='mouse' bus='ps2'/>
    <graphics type='vnc' port='{$vnc_port}' autoport='no' listen='0.0.0.0' keymap='en-us'>
      <listen type='address' address='0.0.0.0'/>
    </graphics>
    <video>
      <model type='cirrus' vram='16384' heads='1'/>
    </video>
  </devices>
</domain>
XML;

        // 7. 取消定义旧VM，重新定义新VM
        $this->exec('virsh undefine ' . escapeshellarg($name) . ' 2>/dev/null');

        $tmp_xml = '/tmp/vm_' . $name . '_reinstall_' . time() . '.xml';
        $escaped_xml = str_replace("'", "'\"'\"'", $new_xml);
        $this->exec("echo '" . $escaped_xml . "' > " . $tmp_xml);

        $result = $this->exec('virsh define ' . $tmp_xml . ' 2>&1');
        if ($result === false || strpos($result, 'error') !== false || strpos($result, '错误') !== false) {
            $this->lastError = '虚拟机重新定义失败: ' . $result;
            return false;
        }

        // 8. 启动虚拟机
        $start_result = $this->exec('virsh start ' . escapeshellarg($name) . ' 2>&1');
        if ($start_result === false || strpos($start_result, 'error') !== false || strpos($start_result, '错误') !== false) {
            $this->lastError = '虚拟机启动失败: ' . $start_result;
            return false;
        }

        return true;
    }

    /**
     * 获取VNC连接地址（noVNC）
     */
    public function getConsoleURL($name, $vnc_port) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $ws_port = 6080 + ($vnc_port % 1000);
        return $protocol . '://' . $this->publicDomain . ':' . $ws_port . '/vnc_lite.html?host=' . $this->publicDomain . '&port=' . $ws_port;
    }

    /**
     * 测试连接并列出所有VM（用于管理后台）
     */
    public function ping() {
        $r = $this->exec('virsh list --all | wc -l');
        return $r !== false;
    }

    public function listVMs() {
        $r = $this->exec('virsh list --all');
        return $r ?: '';
    }

    /**
     * 完整的KVM环境自检
     * @return array [success, items => [[name, status, detail, ...]]]
     */
    public function runDiagnostics() {
        $items = [];
        $all_ok = true;

        // 0. PHP 系统命令检测
        $blocked = $this->checkPhpExecEnabled();
        if (empty($blocked)) {
            $items[] = [
                'name' => 'PHP 系统命令权限',
                'status' => 'ok',
                'detail' => 'exec、shell_exec 等函数可用',
            ];
        } else {
            $items[] = [
                'name' => 'PHP 系统命令权限',
                'status' => 'fail',
                'detail' => '已禁用: ' . implode(', ', $blocked) . '。请在宝塔面板 → 软件商店 → PHP → 设置 → 禁用函数 中移除 exec、shell_exec、passthru、system',
            ];
            $all_ok = false;
        }

        // 1. 当前配置信息（本地/远程模式）
        $is_local = $this->isLocalHost();
        $items[] = [
            'name' => '连接模式',
            'status' => 'ok',
            'detail' => $is_local ? ("本地执行 (host=" . $this->host . ")") : ("SSH远程 (" . $this->user . "@" . $this->host . ":" . $this->port . ")"),
        ];

        // 基本配置检查
        if (empty($this->password) && !$is_local) {
            $items[] = [
                'name' => '连接配置',
                'status' => 'fail',
                'detail' => 'SSH密码为空且非本地连接，请在 config/app.php 中配置 kvm.password',
            ];
            return ['success' => false, 'items' => $items];
        }

        // 2. 连接测试
        $r = $this->exec('echo "pong"');
        $ok = ($r !== false && trim($r) === 'pong');
        $items[] = [
            'name' => '宿主机连接',
            'status' => $ok ? 'ok' : 'fail',
            'detail' => $ok ? ($is_local ? "本地执行成功" : "SSH已连接 " . $this->user . "@" . $this->host . ":" . $this->port) : ($this->lastError ?: '连接失败，请检查SSH配置'),
        ];
        if (!$ok) {
            $items[] = [
                'name' => '排查提示',
                'status' => 'warn',
                'detail' => '如为本地连接请确保 host=127.0.0.1；如为远程连接请确保密码正确且SSH服务运行中',
            ];
            $all_ok = false;
            return ['success' => $all_ok, 'items' => $items];
        }

        // 2. virsh 可用
        $r = $this->exec('which virsh');
        $ok = ($r !== false && !empty(trim($r)));
        $items[] = [
            'name' => 'virsh 工具',
            'status' => $ok ? 'ok' : 'fail',
            'detail' => $ok ? '路径: ' . trim($r) : '未安装 libvirt-client',
        ];
        if (!$ok) $all_ok = false;

        // 3. qemu-img 可用
        $r = $this->exec('which qemu-img');
        $ok = ($r !== false && !empty(trim($r)));
        $items[] = [
            'name' => 'qemu-img 工具',
            'status' => $ok ? 'ok' : 'fail',
            'detail' => $ok ? '路径: ' . trim($r) : '未安装 qemu-utils',
        ];
        if (!$ok) $all_ok = false;

        // 4. qemu-system-x86_64 可用
        $r = $this->exec('which qemu-system-x86_64');
        $ok = ($r !== false && !empty(trim($r)));
        $items[] = [
            'name' => 'qemu-system-x86_64',
            'status' => $ok ? 'ok' : 'warn',
            'detail' => $ok ? '路径: ' . trim($r) : '未找到 (部分系统路径不同)',
        ];

        // 5. libvirtd 服务状态
        $r = $this->exec('systemctl is-active libvirtd 2>/dev/null || service libvirtd status 2>/dev/null | head -1 || echo "n/a"');
        $active = ($r !== false && trim($r) === 'active');
        $items[] = [
            'name' => 'libvirtd 服务',
            'status' => $active ? 'ok' : 'fail',
            'detail' => trim($r) ?: '无法检测',
        ];
        if (!$active) $all_ok = false;

        // 6. CPU 虚拟化支持 (vmx/svm)
        $r = $this->exec("grep -Eo '(vmx|svm)' /proc/cpuinfo | head -1");
        $has_virt = ($r !== false && !empty(trim($r)));
        $items[] = [
            'name' => 'CPU 硬件虚拟化',
            'status' => $has_virt ? 'ok' : 'fail',
            'detail' => $has_virt ? '支持 ' . strtoupper(trim($r)) : '未启用 (请在BIOS开启 VT-x/AMD-V)',
        ];
        if (!$has_virt) $all_ok = false;

        // 7. KVM 模块（多重检测：lsmod / /proc/modules / /dev/kvm）
        $kvm_found = false;
        $kvm_detail = '';

        // 方式 1：lsmod 直接执行（不通过 sudo）
        $r = $this->exec('lsmod');
        if ($r !== false) {
            // 在 PHP 层过滤，避免 shell 管道被 needs_root 判断干扰
            $lines = explode("\n", $r);
            $matched = [];
            foreach ($lines as $line) {
                if (preg_match('/^kvm(_[a-z]+)?\s+/i', $line)) {
                    $matched[] = trim($line);
                }
            }
            if (!empty($matched)) {
                $kvm_found = true;
                $kvm_detail = implode("\n", $matched);
            }
        }

        // 方式 2：检查 /proc/modules
        if (!$kvm_found) {
            $r2 = $this->exec('cat /proc/modules');
            if ($r2 !== false && preg_match('/^kvm[_]?[a-z]*\s+/mi', $r2)) {
                $kvm_found = true;
                $kvm_detail = 'kvm 模块已加载 (/proc/modules 检测)';
            }
        }

        // 方式 3：检查 /dev/kvm 设备是否存在
        if (!$kvm_found) {
            $r3 = $this->exec('test -c /dev/kvm && echo "DEVOK"');
            if ($r3 !== false && strpos($r3, 'DEVOK') !== false) {
                $kvm_found = true;
                $kvm_detail = '/dev/kvm 设备存在';
            }
        }

        $items[] = [
            'name' => 'KVM 内核模块',
            'status' => $kvm_found ? 'ok' : 'fail',
            'detail' => $kvm_found ? trim($kvm_detail) : 'kvm 模块未加载。请在服务器执行：modprobe kvm; modprobe kvm_intel（Intel）或 modprobe kvm_amd（AMD）',
        ];
        if (!$kvm_found) $all_ok = false;

        // 8. 存储池目录（自动创建+详细诊断）
        $php_user = $this->getCurrentUser();
        $r = $this->exec(sprintf('test -d %s && echo "EXIST" || echo "MISSING"', escapeshellarg($this->storagePool)));
        $ok = ($r !== false && trim($r) === 'EXIST');
        
        if (!$ok) {
            // 尝试自动创建目录
            $mkdir_result = $this->exec(sprintf('mkdir -p %s 2>&1; echo "EXIT:$?"', escapeshellarg($this->storagePool)));
            if (strpos($mkdir_result, 'EXIT:0') !== false) {
                $ok = true;
                $detail = "已自动创建: " . $this->storagePool;
            } else {
                $detail = "不存在且无法创建: " . $this->storagePool . "（错误: " . trim(str_replace("EXIT:", "", $mkdir_result)) . "）";
                $detail .= "；PHP用户: $php_user；请手动执行: mkdir -p " . escapeshellarg($this->storagePool);
            }
        } else {
            $detail = "存在: " . $this->storagePool;
            // 添加调试信息：显示实际的目录内容
            $ls_out = $this->exec(sprintf('ls -la %s 2>/dev/null | head -3', escapeshellarg($this->storagePool)));
            if ($ls_out && trim($ls_out)) {
                $detail .= "；内容: " . trim($ls_out);
            }
        }
        
        $items[] = [
            'name' => '存储池目录',
            'status' => $ok ? 'ok' : 'fail',
            'detail' => $detail,
        ];
        if (!$ok) $all_ok = false;

        // 9. 存储池可写（优先用 libvirt 池操作检测，回退到 shell touch）
        if ($ok) {
            $ok_w = false;
            $detail = '';
            $php_user = $this->getCurrentUser();

            // 方法A：通过 virsh pool-refresh 检测（真正的 libvirt 可用性）
            $pool_cmd = "virsh pool-list --all 2>/dev/null | awk 'NR>2 {print \$1}' | head -1";
            $pool_name = trim($this->exec($pool_cmd) ?? '');
            if ($pool_name) {
                $refresh = $this->exec("virsh pool-refresh " . escapeshellarg($pool_name) . " 2>&1; echo \"EXITCODE:\$?\"");
                if (strpos($refresh, 'EXITCODE:0') !== false) {
                    $ok_w = true;
                    $detail = "✓ libvirt池 '$pool_name' 可正常刷新";
                }
            }

            // 方法B：用 touch 检测写权限（PHP用户层面）
            if (!$ok_w) {
                $testfile = rtrim($this->storagePool, '/') . '/_kvm_test_' . time() . '.tmp';
                $touch_out = $this->exec(sprintf('touch %s 2>&1; echo "EXIT:$?"', escapeshellarg($testfile)));
                if (strpos($touch_out, 'EXIT:0') !== false) {
                    $this->exec(sprintf('rm -f %s', escapeshellarg($testfile)));
                    $ok_w = true;
                    $detail = "✓ PHP用户($php_user) 可写入 " . $this->storagePool;
                } else {
                    $ls_out = $this->exec(sprintf('ls -ld %s; stat -c "%%U %%G %%a %%n" %s 2>/dev/null', escapeshellarg($this->storagePool), escapeshellarg($this->storagePool)));
                    $detail = "权限不足：目录属主/权限 → " . trim($ls_out) . "；PHP用户：$php_user";
                }
            }

            $items[] = [
                'name' => '存储池可写',
                'status' => $ok_w ? 'ok' : 'fail',
                'detail' => $detail,
            ];
            if (!$ok_w) $all_ok = false;
        }

        // 10. 网桥/网络
        $r = $this->exec("virsh net-list --all 2>/dev/null | tail -n +3");
        $items[] = [
            'name' => 'libvirt 网络',
            'status' => ($r !== false && !empty(trim($r))) ? 'ok' : 'warn',
            'detail' => trim($r) ?: '无可用网络',
        ];

        // 11. virsh version
        $r = $this->exec('virsh --version 2>/dev/null');
        $items[] = [
            'name' => 'libvirt 版本',
            'status' => 'ok',
            'detail' => trim($r) ?: '未知',
        ];

        // 12. 宿主机资源信息
        $cpu = trim($this->exec("nproc") ?: '?');
        $mem = trim($this->exec("awk '/MemTotal/ {printf \"%.1f\", \$2/1024/1024}' /proc/meminfo") ?: '?');
        $disk = trim($this->exec(sprintf('df -BG %s 2>/dev/null | tail -1 | awk "{print \$4}"', escapeshellarg($this->storagePool))) ?: '?');
        $items[] = [
            'name' => '宿主机资源',
            'status' => 'ok',
            'detail' => "CPU: {$cpu} 核 / 内存: {$mem} GB / 存储池剩余: {$disk}",
        ];

        // 13. 现有VM数量
        $r = $this->exec('virsh list --all --name 2>/dev/null | grep -c . || echo "0"');
        $items[] = [
            'name' => '现有虚拟机',
            'status' => 'ok',
            'detail' => '共 ' . trim($r) . ' 台',
        ];

        return [
            'success' => $all_ok,
            'items' => $items,
        ];
    }

    /**
     * 测试指定镜像文件是否存在于宿主机
     */
    public function checkImageFile($path) {
        $r = $this->exec(sprintf('test -f %s && echo "EXIST:%s" || echo "MISSING"', escapeshellarg($path), '%s'));
        $size = '';
        if ($r !== false && strpos($r, 'EXIST') === 0) {
            $size = trim($this->exec(sprintf('ls -lh %s 2>/dev/null | awk "{print \$5}"', escapeshellarg($path))) ?: '');
        }
        return [
            'exists' => ($r !== false && strpos($r, 'EXIST') === 0),
            'size' => $size,
            'raw' => trim($r),
        ];
    }

    /**
     * 从ISO创建可启动的qcow2预装镜像
     * 通过QEMU引导ISO安装系统到qcow2磁盘，安装完成后得到可启动的预装镜像
     * @param string $iso_path 源ISO文件路径
     * @param string $output_path 输出qcow2文件路径（可选）
     * @param int $disk_size_gb 磁盘大小（GB，默认40）
     * @param int $memory_mb 安装时内存（MB，默认2048）
     * @return array [success, message, output_path, vnc_port, ws_url, pid]
     */
    public function convertIsoToQcow2($iso_path, $output_path = '', $disk_size_gb = 40, $memory_mb = 2048) {
        $iso_path = trim($iso_path);
        if (empty($iso_path)) {
            $this->lastError = 'ISO路径不能为空';
            return ['success' => false, 'message' => 'ISO路径不能为空'];
        }

        $iso_check = $this->checkImageFile($iso_path);
        if (!$iso_check['exists']) {
            $this->lastError = '源ISO文件不存在: ' . $iso_path;
            return ['success' => false, 'message' => '源ISO文件不存在: ' . $iso_path];
        }

        $qemu_img_bin = $this->findBin('qemu-img');
        if (empty($qemu_img_bin)) {
            $this->lastError = '未找到 qemu-img，请安装 qemu-utils';
            return ['success' => false, 'message' => '未找到 qemu-img，请安装 qemu-utils'];
        }

        // 查找 QEMU 系统模拟器
        $qemu_system_bin = $this->findBin('qemu-system-x86_64');
        if (empty($qemu_system_bin)) {
            $qemu_system_bin = $this->findBin('qemu-kvm');
        }
        if (empty($qemu_system_bin)) {
            // 尝试 libexec 路径
            if (file_exists('/usr/libexec/qemu-kvm')) {
                $qemu_system_bin = '/usr/libexec/qemu-kvm';
            }
        }
        if (empty($qemu_system_bin)) {
            $this->lastError = '未找到 QEMU 系统模拟器，请安装 qemu-kvm';
            return ['success' => false, 'message' => '未找到 QEMU 系统模拟器，请安装 qemu-kvm'];
        }

        // 生成输出路径
        if (empty($output_path)) {
            $output_path = preg_replace('/\.(iso|ISO)$/i', '.qcow2', $iso_path);
            if ($output_path === $iso_path) {
                $output_path = $iso_path . '.qcow2';
            }
        }

        $dir = dirname($output_path);
        $this->exec(sprintf('mkdir -p %s 2>&1', escapeshellarg($dir)));

        // 如果已存在同名文件，先备份
        if (file_exists($output_path)) {
            $backup = $output_path . '.bak.' . time();
            rename($output_path, $backup);
        }

        // 1. 创建空白 qcow2 磁盘
        $create_cmd = sprintf(
            '%s create -f qcow2 %s %dG 2>&1',
            escapeshellarg($qemu_img_bin),
            escapeshellarg($output_path),
            $disk_size_gb
        );
        $create_result = $this->exec($create_cmd);
        if ($create_result === false || (strpos($create_result, 'error') !== false && strpos($create_result, 'Formatting') === false)) {
            $this->lastError = '创建qcow2磁盘失败: ' . ($create_result ?: '未知错误');
            return ['success' => false, 'message' => '创建qcow2磁盘失败: ' . ($create_result ?: '未知错误')];
        }

        // 2. 寻找可用VNC端口
        $vnc_port = 0;
        for ($p = 5900; $p <= 5999; $p++) {
            $check = $this->exec(sprintf('ss -tlnp 2>/dev/null | grep ":%d " | wc -l', $p));
            if (intval(trim($check ?: '0')) === 0) {
                $vnc_port = $p;
                break;
            }
        }
        if ($vnc_port === 0) {
            $this->exec(sprintf('rm -f %s 2>/dev/null', escapeshellarg($output_path)));
            $this->lastError = '无可用VNC端口（5900-5999）';
            return ['success' => false, 'message' => '无可用VNC端口（5900-5999）'];
        }

        $vnc_display = $vnc_port - 5900;

        // 3. 启动QEMU引导ISO安装
        $enable_kvm = file_exists('/dev/kvm') ? '-enable-kvm -cpu host' : '';
        $pid_file = dirname($output_path) . '/.' . basename($output_path) . '.pid';
        $log_file = dirname($output_path) . '/.' . basename($output_path) . '.log';

        $qemu_cmd = sprintf(
            '%s %s -m %d -smp 2 ' .
            '-drive file=%s,format=qcow2,if=virtio ' .
            '-cdrom %s ' .
            '-boot d ' .
            '-vnc 127.0.0.1:%d ' .
            '-pidfile %s ' .
            '-daemonize ' .
            '-display none ' .
            '> %s 2>&1',
            escapeshellarg($qemu_system_bin),
            $enable_kvm,
            $memory_mb,
            escapeshellarg($output_path),
            escapeshellarg($iso_path),
            $vnc_display,
            escapeshellarg($pid_file),
            escapeshellarg($log_file)
        );

        $this->exec($qemu_cmd);

        // 等待进程启动
        sleep(2);

        // 验证QEMU是否启动成功
        $pid = 0;
        if (file_exists($pid_file)) {
            $pid = intval(trim(file_get_contents($pid_file) ?: '0'));
        }
        if ($pid <= 0) {
            // 检查是否有 qemu 进程在运行
            $ps_check = $this->exec(sprintf('pgrep -f "%s" 2>/dev/null', escapeshellarg($output_path)));
            $pid = intval(trim($ps_check ?: '0'));
        }

        if ($pid <= 0) {
            $err_log = file_exists($log_file) ? file_get_contents($log_file) : '';
            // 清理
            $this->exec(sprintf('rm -f %s 2>/dev/null', escapeshellarg($output_path)));
            $this->lastError = 'QEMU启动失败' . ($err_log ? ': ' . $err_log : '');
            return ['success' => false, 'message' => 'QEMU启动失败' . ($err_log ? ': ' . $err_log : '')];
        }

        // 4. 启动 websockify 代理
        $ws_port = $vnc_port + 100; // 6000-6099
        $ws_pid_file = dirname($output_path) . '/.' . basename($output_path) . '.ws.pid';
        $novnc_dir = '';

        // 查找 noVNC 目录
        $novnc_candidates = [
            dirname(dirname(dirname(__DIR__))) . '/novnc',
            dirname(dirname(__DIR__)) . '/novnc',
            '/www/wwwroot/default/novnc',
            '/var/www/html/novnc',
        ];
        foreach ($novnc_candidates as $d) {
            if (file_exists($d . '/vnc_lite.html')) {
                $novnc_dir = $d;
                break;
            }
        }

        $websockify_bin = $this->findBin('websockify');
        if (empty($websockify_bin)) {
            $python3 = $this->findBin('python3');
            if ($python3) {
                $this->exec(sprintf('%s -c "import websockify" 2>/dev/null', escapeshellarg($python3)));
                $websockify_bin = $python3 . ' -m websockify';
            }
        }

        if (!empty($websockify_bin) && !empty($novnc_dir)) {
            $ws_cmd = sprintf(
                '%s --web %s 0.0.0.0:%d 127.0.0.1:%d --daemon --pid-file=%s 2>/dev/null',
                $websockify_bin,
                escapeshellarg($novnc_dir),
                $ws_port,
                $vnc_port,
                escapeshellarg($ws_pid_file)
            );
            $this->exec($ws_cmd);
            sleep(1);
        }

        // 5. 获取文件信息
        $size_info = $this->exec(sprintf('ls -lh %s 2>/dev/null', escapeshellarg($iso_path)));
        $img_info = $this->exec(sprintf('%s info %s 2>&1', escapeshellarg($qemu_img_bin), escapeshellarg($output_path)));
        $virtual_size = '';
        if ($img_info && preg_match('/virtual size:\s*([^\n]+)/i', $img_info, $m)) {
            $virtual_size = trim($m[1]);
        }

        // 获取服务器IP
        $server_ip = $this->exec('hostname -I 2>/dev/null | awk "{print \$1}"');
        $server_ip = trim($server_ip ?: '127.0.0.1');

        // 构建 noVNC 访问地址
        $ws_url = '';
        if ($ws_port > 0) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $http_host = $_SERVER['HTTP_HOST'] ?? ($server_ip . ':80');
            // 提取端口
            $http_port = '';
            if (preg_match('/:(\d+)$/', $http_host, $hm)) {
                $http_port = $hm[1];
            }
            $ws_url = $protocol . '://' . $server_ip . ':' . $ws_port . '/vnc_lite.html?host=' . $server_ip . '&port=' . $ws_port;
        }

        return [
            'success' => true,
            'message' => '安装环境已启动，请通过VNC连接完成系统安装',
            'output_path' => $output_path,
            'disk_size_gb' => $disk_size_gb,
            'virtual_size' => $virtual_size,
            'vnc_port' => $vnc_port,
            'ws_port' => $ws_port,
            'ws_url' => $ws_url,
            'pid' => $pid,
            'pid_file' => $pid_file,
            'log_file' => $log_file,
            'log' => 'QEMU已启动，ISO引导中',
        ];
    }

    /**
     * 检查ISO转qcow2安装进程状态
     * @param string $output_path qcow2文件路径
     * @return array [running, pid, file_size, message]
     */
    public function checkIsoConvertStatus($output_path) {
        $pid_file = dirname($output_path) . '/.' . basename($output_path) . '.pid';
        $pid = 0;
        if (file_exists($pid_file)) {
            $pid = intval(trim(file_get_contents($pid_file) ?: '0'));
        }

        // 检查进程是否还在运行
        $running = false;
        if ($pid > 0) {
            $check = $this->exec(sprintf('kill -0 %d 2>/dev/null && echo "alive"', $pid));
            if (strpos($check ?: '', 'alive') !== false) {
                $running = true;
            }
        }

        // 如果pid文件不存在，尝试通过pgrep查找
        if (!$running) {
            $ps_check = $this->exec(sprintf('pgrep -f "%s" 2>/dev/null', escapeshellarg($output_path)));
            $pid = intval(trim($ps_check ?: '0'));
            if ($pid > 0) {
                $running = true;
            }
        }

        $file_size = '';
        if (file_exists($output_path)) {
            $size = filesize($output_path);
            if ($size !== false) {
                if ($size >= 1073741824) {
                    $file_size = round($size / 1073741824, 2) . ' GB';
                } elseif ($size >= 1048576) {
                    $file_size = round($size / 1048576, 2) . ' MB';
                } else {
                    $file_size = round($size / 1024, 2) . ' KB';
                }
            }
        }

        $qemu_img_bin = $this->findBin('qemu-img');
        $img_info = '';
        if ($qemu_img_bin && file_exists($output_path)) {
            $info = $this->exec(sprintf('%s info %s 2>&1', escapeshellarg($qemu_img_bin), escapeshellarg($output_path)));
            if ($info) {
                if (preg_match('/disk size:\s*([^\n]+)/i', $info, $m)) {
                    $img_info = trim($m[1]);
                }
            }
        }

        return [
            'running' => $running,
            'pid' => $pid,
            'file_size' => $file_size,
            'disk_size' => $img_info,
            'message' => $running ? '安装进行中，请通过VNC完成系统安装' : '安装已完成或进程已退出',
        ];
    }

    /**
     * 停止ISO转qcow2安装进程
     * @param string $output_path qcow2文件路径
     * @return bool
     */
    public function stopIsoConvert($output_path) {
        $pid_file = dirname($output_path) . '/.' . basename($output_path) . '.pid';
        $ws_pid_file = dirname($output_path) . '/.' . basename($output_path) . '.ws.pid';

        // 停止QEMU
        $pid = 0;
        if (file_exists($pid_file)) {
            $pid = intval(trim(file_get_contents($pid_file) ?: '0'));
        }
        if ($pid > 0) {
            $this->exec(sprintf('kill %d 2>/dev/null', $pid));
            sleep(1);
            $this->exec(sprintf('kill -9 %d 2>/dev/null', $pid));
        }

        // 停止websockify
        $ws_pid = 0;
        if (file_exists($ws_pid_file)) {
            $ws_pid = intval(trim(file_get_contents($ws_pid_file) ?: '0'));
        }
        if ($ws_pid > 0) {
            $this->exec(sprintf('kill %d 2>/dev/null', $ws_pid));
            sleep(1);
            $this->exec(sprintf('kill -9 %d 2>/dev/null', $ws_pid));
        }

        // 清理pid文件
        $this->exec(sprintf('rm -f %s %s 2>/dev/null', escapeshellarg($pid_file), escapeshellarg($ws_pid_file)));

        return true;
    }

    /**
     * 获取宿主机上的ISO文件列表
     * @param string $directory 搜索目录（默认存储池的iso子目录）
     * @return array ISO文件列表
     */
    public function listIsoFiles($directory = '') {
        if (empty($directory)) {
            $directory = rtrim($this->storagePool, '/') . '/iso';
        }

        $cmd = sprintf('find %s -maxdepth 2 -type f -name "*.iso" -o -name "*.ISO" 2>/dev/null | head -50', escapeshellarg($directory));
        $result = $this->exec($cmd);

        if ($result === false || empty(trim($result))) {
            $cmd2 = sprintf('ls -1 %s/*.iso 2>/dev/null || ls -1 %s/*.ISO 2>/dev/null', escapeshellarg($directory), escapeshellarg($directory));
            $result = $this->exec($cmd2);
            if ($result === false || empty(trim($result))) {
                return [];
            }
        }

        $files = [];
        foreach (explode("\n", trim($result)) as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $size = trim($this->exec(sprintf('ls -lh %s 2>/dev/null | awk "{print \$5}"', escapeshellarg($line))) ?: '');
            $files[] = [
                'path' => $line,
                'name' => basename($line),
                'size' => $size,
            ];
        }

        return $files;
    }
}
