<?php
require_once __DIR__ . '/../config/helper.php';
require_auth();

$id_param = $_GET['id'] ?? '';
$uid = auth_id();

if (empty($id_param)) {
    header('Location: /user/hosts.php');
    exit;
}

$host = null;
if (is_numeric($id_param)) {
    $host_id = intval($id_param);
    $host = Database::fetch("SELECT * FROM hosts WHERE id = ? AND user_id = ?", [$host_id, $uid]);
} else {
    $host_uuid = $id_param;
    $host = Database::fetch("SELECT * FROM hosts WHERE uuid = ? AND user_id = ?", [$host_uuid, $uid]);
}

if (!$host) {
    header('Location: /user/hosts.php');
    exit;
}

$host_id = $host['id'];
$host_uuid = $host['uuid'] ?? $host_id;

$is_kvm = ($host['vm_type'] ?? 'web') === 'kvm';
if (!$is_kvm) {
    header('Location: /user/host_detail.php?id=' . $host_uuid);
    exit;
}

$ip_address = $host['ip_address'] ?: '';

if (empty($ip_address) || $ip_address === '0.0.0.0' || $ip_address === '127.0.0.1') {
    $refresh_result = kvm_refresh_status($host);
    if ($refresh_result && !empty($refresh_result['ip'])) {
        $ip_address = $refresh_result['ip'];
    } else {
        $ip_address = '';
    }
}

$is_local_kvm = false;
$ssh_port = 22;

if (!empty($ip_address)) {
    $is_local_kvm = preg_match('/^(192\.168\.122\.|10\.|172\.16\.|172\.17\.|172\.18\.|172\.19\.|172\.20\.|172\.21\.|172\.22\.|172\.23\.|172\.24\.|172\.25\.|172\.26\.|172\.27\.|172\.28\.|172\.29\.|172\.30\.|172\.31\.)/', $ip_address);
    if (!$is_local_kvm) {
        $ssh_port = intval($host['ssh_port'] ?: 22);
    }
}

$ssh_user = 'root';
$ssh_password = $host['root_password'] ?: '';
$vm_power = $host['vm_power_status'] ?? 'stopped';
$vm_name = $host['vm_name'] ?: $host['mnbt_username'];

$ip_error = '';
if (empty($ip_address)) {
    $ip_error = '无法获取虚拟机IP地址，请等待DHCP分配或返回控制台刷新状态';
}

$ssh_token = '';
if (!empty($ip_address)) {
    $ssh_token = create_ssh_token($uid, $host_id, $ip_address, $ssh_port, $ssh_user, $ssh_password, 300);
    if (!$ssh_token) {
        $ssh_token = md5($host_id . '_' . $uid . '_' . time() . '_' . rand(1000, 9999));
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WebSSH - <?php echo e($vm_name); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm@5.3.0/css/xterm.css">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 0;
            background: #1e1e1e;
            height: 100vh;
            display: flex;
            flex-direction: column;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            overflow: hidden;
        }
        .ssh-header {
            background: #252526;
            color: #fff;
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #333;
            flex-shrink: 0;
            height: 45px;
        }
        .ssh-header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .back-btn {
            padding: 5px 12px;
            background: #3a3a3a;
            color: #ccc;
            border: 1px solid #4a4a4a;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        .back-btn:hover {
            background: #4a4a4a;
            color: #fff;
        }
        .ssh-title {
            font-size: 14px;
            font-weight: 500;
            color: #e0e0e0;
        }
        .ssh-info {
            font-size: 11px;
            color: #888;
            font-family: 'Consolas', 'Courier New', monospace;
            margin-top: 2px;
        }
        .ssh-header-right {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .ssh-status {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #888;
        }
        .ssh-status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #666;
            transition: all 0.3s;
        }
        .ssh-status-dot.connected {
            background: #52c41a;
            box-shadow: 0 0 8px rgba(82, 196, 26, 0.5);
        }
        .ssh-status-dot.connecting {
            background: #faad14;
            animation: blink 1s infinite;
        }
        .ssh-status-dot.error {
            background: #ff4d4f;
        }
        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
        .ssh-btn {
            padding: 5px 12px;
            background: #3a3a3a;
            color: #ccc;
            border: 1px solid #4a4a4a;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .ssh-btn:hover {
            background: #4a4a4a;
            color: #fff;
        }
        .ssh-btn.primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }
        .ssh-btn.primary:hover {
            background: var(--primary-hover);
        }
        #terminal-container {
            flex: 1;
            padding: 0;
            overflow: hidden;
            position: relative;
            min-height: 0;
        }
        .terminal-wrapper {
            width: 100%;
            height: 100%;
            padding: 0;
        }
        #terminal {
            width: 100%;
            height: 100%;
        }
        .connecting-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(30, 30, 30, 0.95);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 100;
            flex-direction: column;
        }
        .connecting-spinner {
            width: 44px;
            height: 44px;
            border: 3px solid #3a3a3a;
            border-top-color: var(--primary);
            border-radius: 50%;
            margin-bottom: 20px;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .connecting-text {
            color: #ccc;
            font-size: 14px;
            margin-bottom: 8px;
        }
        .connecting-sub {
            color: #666;
            font-size: 12px;
        }
        .error-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(30, 30, 30, 0.95);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 100;
            flex-direction: column;
            text-align: center;
            padding: 20px;
        }
        .error-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
        .error-title {
            color: #ff4d4f;
            font-size: 16px;
            font-weight: 500;
            margin-bottom: 12px;
        }
        .error-msg {
            color: #999;
            font-size: 13px;
            margin-bottom: 20px;
            line-height: 1.6;
            max-width: 400px;
        }
        .error-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .hidden { display: none !important; }
        .quick-cmds {
            display: none;
            gap: 6px;
            flex-wrap: wrap;
            padding: 6px 20px;
            background: #2a2a2a;
            border-bottom: 1px solid #333;
            height: 32px;
            flex-shrink: 0;
        }
        .quick-cmds.show {
            display: flex;
        }
        .quick-cmd-btn {
            padding: 3px 10px;
            background: #3a3a3a;
            color: #aaa;
            border: 1px solid #444;
            border-radius: 3px;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .quick-cmd-btn:hover {
            background: #4a4a4a;
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="ssh-header">
        <div class="ssh-header-left">
            <a href="/user/host_kvm.php?id=<?php echo $host_uuid; ?>" class="back-btn">← 返回</a>
            <div>
                <div class="ssh-title">💻 WebSSH - <?php echo e($vm_name); ?></div>
                <div class="ssh-info"><?php echo e($ssh_user); ?>@<?php echo e($ip_address); ?>:<?php echo $ssh_port; ?></div>
            </div>
        </div>
        <div class="ssh-header-right">
            <div class="ssh-status">
                <span class="ssh-status-dot" id="statusDot"></span>
                <span id="statusText">未连接</span>
            </div>
            <button class="ssh-btn" onclick="reconnect()">重新连接</button>
            <button class="ssh-btn" onclick="copySSHCommand()">复制命令</button>
            <button class="ssh-btn" onclick="toggleFullscreen()">全屏</button>
        </div>
    </div>

    <div class="quick-cmds" id="quickCmds">
        <span style="font-size: 11px; color: #666; padding: 2px 4px;">快捷命令:</span>
        <button class="quick-cmd-btn" onclick="sendQuickCmd('ls -la\n')">ls -la</button>
        <button class="quick-cmd-btn" onclick="sendQuickCmd('df -h\n')">df -h</button>
        <button class="quick-cmd-btn" onclick="sendQuickCmd('free -m\n')">free -m</button>
        <button class="quick-cmd-btn" onclick="sendQuickCmd('top -bn1 | head -20\n')">top</button>
        <button class="quick-cmd-btn" onclick="sendQuickCmd('ip addr\n')">ip addr</button>
        <button class="quick-cmd-btn" onclick="sendQuickCmd('whoami\n')">whoami</button>
        <button class="quick-cmd-btn" onclick="sendQuickCmd('date\n')">date</button>
    </div>

    <div id="terminal-container">
        <div class="terminal-wrapper">
            <div id="terminal"></div>
        </div>

        <div class="connecting-overlay" id="connectingBox">
            <div class="connecting-spinner"></div>
            <div class="connecting-text">正在连接 SSH 服务器...</div>
            <div class="connecting-sub" id="connectingStep">建立 SSH 连接</div>
        </div>

        <div class="error-overlay hidden" id="errorBox">
            <div class="error-icon">⚠️</div>
            <div class="error-title">连接失败</div>
            <div class="error-msg" id="errorMsg">SSH连接失败，请稍后重试。</div>
            <div class="error-buttons">
                <button class="ssh-btn primary" onclick="reconnect()">重试连接</button>
                <button class="ssh-btn" onclick="copySSHCommand()">复制SSH命令</button>
                <a href="/user/host_kvm.php?id=<?php echo $host_uuid; ?>" class="ssh-btn">返回控制台</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/xterm@5.3.0/lib/xterm.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xterm-addon-fit@0.8.0/lib/xterm-addon-fit.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xterm-addon-web-links@0.9.0/lib/xterm-addon-web-links.js"></script>

    <script>
    var term;
    var fitAddon;
    var hostId = <?php echo $host_id; ?>;
    var vmPower = '<?php echo $vm_power; ?>';
    var ipAddress = '<?php echo e($ip_address); ?>';
    var sshPort = <?php echo $ssh_port; ?>;
    var sshUser = '<?php echo e($ssh_user); ?>';
    var sshToken = '<?php echo $ssh_token; ?>';
    var sessionId = null;
    var isConnected = false;
    var pollTimer = null;
    var sendBuffer = [];
    var isSending = false;
    var ipError = '<?php echo e($ip_error); ?>';

    function initTerminal() {
        term = new Terminal({
            cursorBlink: true,
            fontSize: 14,
            fontFamily: 'Consolas, "Courier New", "Microsoft YaHei", monospace',
            theme: {
                background: '#1e1e1e',
                foreground: '#d4d4d4',
                cursor: '#ffffff',
                cursorAccent: '#000000',
                selectionBackground: '#264f78',
                black: '#000000',
                red: '#cd3131',
                green: '#0dbc79',
                yellow: '#e5e510',
                blue: '#2472c8',
                magenta: '#bc3fbc',
                cyan: '#11a8cd',
                white: '#e5e5e5',
                brightBlack: '#666666',
                brightRed: '#f14c4c',
                brightGreen: '#23d18b',
                brightYellow: '#f5f543',
                brightBlue: '#3b8eea',
                brightMagenta: '#d670d6',
                brightCyan: '#29b8db',
                brightWhite: '#ffffff'
            },
            scrollback: 10000,
            convertEol: true,
            allowProposedApi: true,
            lineHeight: 1,
            letterSpacing: 0,
        });

        fitAddon = new FitAddon.FitAddon();
        term.loadAddon(fitAddon);
        term.loadAddon(new WebLinksAddon.WebLinksAddon());

        term.open(document.getElementById('terminal'));
        fitAddon.fit();

        // 监听浏览器粘贴事件
        term.element.addEventListener('paste', function(e) {
            e.preventDefault();
            var pastedText = '';
            if (e.clipboardData && e.clipboardData.getData) {
                pastedText = e.clipboardData.getData('text/plain');
            }
            if (pastedText && pastedText.length > 0) {
                // 直接发送粘贴内容到SSH会话
                sendData(pastedText);
                // 粘贴长文本后延迟滚动到底部并重新fit
                if (pastedText.length > 100) {
                    setTimeout(function() {
                        term.scrollToBottom();
                        fitAddon.fit();
                    }, 150);
                }
            }
        });

        // 监听终端数据输入（键盘输入）
        term.onData(function(data) {
            if (isConnected && sessionId) {
                sendData(data);
            }
        });

        term.onResize(function(size) {
            if (isConnected && sessionId) {
                sendResize(size.cols, size.rows);
            }
        });

        window.addEventListener('resize', function() {
            fitAddon.fit();
        });

        // 监听快捷命令栏显示变化
        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'class') {
                    var target = mutation.target;
                    if (target.classList.contains('show')) {
                        setTimeout(function() {
                            fitAddon.fit();
                        }, 100);
                    }
                }
            });
        });
        observer.observe(document.getElementById('quickCmds'), { attributes: true });

        term.writeln('\x1b[36m╔══════════════════════════════════════════════════════════════╗\x1b[0m');
        term.writeln('\x1b[36m║\x1b[0m          \x1b[1mWebSSH 终端\x1b[0m - ' + ipAddress + ':' + sshPort + '          \x1b[36m║\x1b[0m');
        term.writeln('\x1b[36m╠══════════════════════════════════════════════════════════════╣\x1b[0m');
        term.writeln('\x1b[36m║\x1b[0m  \x1b[33m提示：\x1b[0m 正在连接到 SSH 服务器，请稍候...            \x1b[36m║\x1b[0m');
        term.writeln('\x1b[36m╚══════════════════════════════════════════════════════════════╝\x1b[0m');
        term.writeln('');
    }

    function sendData(data) {
        sendBuffer.push(data);
        processSendQueue();
    }

    function processSendQueue() {
        if (isSending || sendBuffer.length === 0 || !sessionId) return;

        isSending = true;
        var data = sendBuffer.join('');
        sendBuffer = [];

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/webssh/session.php?action=write', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onloadend = function() {
            isSending = false;
            if (sendBuffer.length > 0) {
                processSendQueue();
            }
        };
        xhr.send('session_id=' + encodeURIComponent(sessionId) + '&data=' + encodeURIComponent(data));
    }

    function sendResize(cols, rows) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/webssh/session.php?action=resize', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.send('session_id=' + encodeURIComponent(sessionId) + '&cols=' + cols + '&rows=' + rows);
    }

    function connectSSH() {
        if (vmPower !== 'running') {
            showError('虚拟机未运行，请先开机后再使用SSH连接。');
            return;
        }

        if (ipError) {
            document.getElementById('connectingBox').classList.add('hidden');
            showError(ipError);
            startIPRefresh();
            return;
        }

        document.getElementById('connectingBox').classList.remove('hidden');
        document.getElementById('errorBox').classList.add('hidden');
        setStatus('connecting', '连接中...');
        document.getElementById('connectingStep').textContent = '建立 SSH 连接...';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/webssh/session.php?action=start', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.timeout = 15000;
        xhr.onload = function() {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.code === 0 && res.session_id) {
                    sessionId = res.session_id;
                    document.getElementById('connectingStep').textContent = '等待 SSH 响应...';
                    startPolling();
                } else {
                    showError(res.msg || 'SSH连接失败');
                }
            } catch(e) {
                showError('连接响应解析失败: ' + e.message);
            }
        };
        xhr.onerror = function() {
            showError('网络错误，无法连接到 WebSSH 服务');
        };
        xhr.ontimeout = function() {
            showError('连接超时，请稍后重试');
        };
        xhr.send('token=' + encodeURIComponent(sshToken));
    }

    function startPolling() {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(pollOutput, 100);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function pollOutput() {
        if (!sessionId) return;

        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/webssh/session.php?action=read&session_id=' + encodeURIComponent(sessionId), true);
        xhr.onload = function() {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.code === 0) {
                    if (res.output && res.output !== '') {
                        if (!isConnected) {
                            document.getElementById('connectingBox').classList.add('hidden');
                            document.getElementById('quickCmds').classList.add('show');
                            isConnected = true;
                            setStatus('connected', '已连接');
                            setTimeout(function() {
                                fitAddon.fit();
                            }, 50);
                            term.focus();
                        }
                        term.write(res.output);
                    }
                    if (res.closed) {
                        handleConnectionClose();
                    }
                } else {
                    if (res.closed) {
                        showError(res.msg || 'SSH连接失败');
                    }
                }
            } catch(e) {}
        };
        xhr.send();
    }

    function handleConnectionClose() {
        stopPolling();
        isConnected = false;
        sessionId = null;
        document.getElementById('quickCmds').classList.remove('show');
        setStatus('error', '连接已关闭');
        if (document.getElementById('errorBox').classList.contains('hidden')) {
            term.writeln('');
            term.writeln('\x1b[33m━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\x1b[0m');
            term.writeln('\x1b[33m  连接已关闭\x1b[0m');
            term.writeln('\x1b[90m  点击"重新连接"按钮重新连接\x1b[0m');
            term.writeln('\x1b[33m━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\x1b[0m');
        }
    }

    var ipRefreshTimer = null;
    var ipRefreshCount = 0;

    function startIPRefresh() {
        if (ipRefreshTimer) clearInterval(ipRefreshTimer);
        ipRefreshCount = 0;
        ipRefreshTimer = setInterval(function() {
            ipRefreshCount++;
            if (ipRefreshCount >= 60) {
                clearInterval(ipRefreshTimer);
                ipRefreshTimer = null;
                return;
            }

            var xhr = new XMLHttpRequest();
            xhr.open('GET', '/webssh/refresh_token.php?id=' + hostId, true);
            xhr.onload = function() {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.code === 0 && res.ip) {
                        ipAddress = res.ip;
                        sshToken = res.token;
                        ipError = '';
                        clearInterval(ipRefreshTimer);
                        ipRefreshTimer = null;
                        document.querySelector('.ssh-info').textContent = sshUser + '@' + ipAddress + ':' + sshPort;
                        reconnect();
                    }
                } catch(e) {}
            };
            xhr.onerror = function() {};
            xhr.send();
        }, 3000);
    }

    function reconnect() {
        stopPolling();
        if (sessionId) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/webssh/session.php?action=close', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.send('session_id=' + encodeURIComponent(sessionId));
        }
        sessionId = null;
        isConnected = false;
        sendBuffer = [];
        isSending = false;
        document.getElementById('errorBox').classList.add('hidden');
        document.getElementById('connectingBox').classList.remove('hidden');
        document.getElementById('quickCmds').classList.remove('show');
        term.reset();

        var refreshXhr = new XMLHttpRequest();
        refreshXhr.open('GET', '/webssh/refresh_token.php?id=' + hostId, true);
        refreshXhr.onload = function() {
            try {
                var res = JSON.parse(refreshXhr.responseText);
                if (res.code === 0 && res.token) {
                    sshToken = res.token;
                }
                if (res.code === 0 && res.ip) {
                    ipAddress = res.ip;
                    document.querySelector('.ssh-info').textContent = sshUser + '@' + ipAddress + ':' + sshPort;
                }
            } catch(e) {}
            connectSSH();
        };
        refreshXhr.onerror = function() {
            connectSSH();
        };
        refreshXhr.send();
    }

    function setStatus(type, text) {
        var dot = document.getElementById('statusDot');
        var txt = document.getElementById('statusText');
        dot.className = 'ssh-status-dot ' + type;
        txt.textContent = text;
    }

    function showError(msg) {
        stopPolling();
        isConnected = false;
        sessionId = null;
        document.getElementById('connectingBox').classList.add('hidden');
        document.getElementById('errorBox').classList.remove('hidden');
        document.getElementById('errorMsg').textContent = msg;
        setStatus('error', '连接失败');
    }

    function copySSHCommand() {
        var cmd = 'ssh -p ' + sshPort + ' ' + sshUser + '@' + ipAddress;
        
        // 使用传统复制方法（兼容 HTTP 环境）
        var textarea = document.createElement('textarea');
        textarea.value = cmd;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            var btns = document.querySelectorAll('.ssh-btn');
            btns.forEach(function(b) {
                if (b.textContent === '复制命令' || b.textContent === '复制SSH命令') {
                    var old = b.textContent;
                    b.textContent = '已复制!';
                    setTimeout(function() { b.textContent = old; }, 2000);
                }
            });
        } catch (e) {
            alert('复制失败，请手动复制: ' + cmd);
        }
        document.body.removeChild(textarea);
    }

    function sendQuickCmd(cmd) {
        if (term && isConnected && sessionId) {
            sendData(cmd);
            term.focus();
        }
    }

    function toggleFullscreen() {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen();
        } else {
            document.exitFullscreen();
        }
    }

    window.onload = function() {
        initTerminal();
        setTimeout(connectSSH, 800);
    };

    window.onbeforeunload = function() {
        if (sessionId) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/webssh/session.php?action=close', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.send('session_id=' + encodeURIComponent(sessionId));
        }
    };

    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.shiftKey && e.key === 'R') {
            e.preventDefault();
            reconnect();
        }
    });
    </script>
</body>
</html>
