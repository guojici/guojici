<?php
/**
 * WebSSH WebSocket 代理
 * 说明：此文件为WebSocket连接入口，需要WebSocket服务支持
 * 如果使用PHP内置服务器或Apache，需要额外启动WebSocket服务
 * 
 * 推荐方案：
 * 1. 使用 wssh (Python WebSSH) - pip install wssh
 * 2. 使用 shellinabox 
 * 3. 使用 ttyd
 * 4. 使用 WebSSH2 (Node.js)
 */

require_once __DIR__ . '/../config/helper.php';

session_start();

// 验证token
$token = $_GET['token'] ?? '';
$session_key = 'ssh_token_' . $token;

if (empty($token) || !isset($_SESSION[$session_key])) {
    header('HTTP/1.1 403 Forbidden');
    echo '无效的连接凭证';
    exit;
}

$ssh_info = $_SESSION[$session_key];
if (time() > ($ssh_info['expire'] ?? 0)) {
    header('HTTP/1.1 403 Forbidden');
    echo '连接凭证已过期';
    exit;
}

// 验证用户
if (!auth_check() || auth_id() != $ssh_info['uid']) {
    header('HTTP/1.1 403 Forbidden');
    echo '用户验证失败';
    exit;
}

// 检查是否为WebSocket请求
if (isset($_SERVER['HTTP_UPGRADE']) && strtolower($_SERVER['HTTP_UPGRADE']) === 'websocket') {
    // WebSocket连接 - 需要WebSocket服务器支持
    // 这里可以转发到真正的WebSocket SSH服务
    $ws_host = config('webssh.host') ?: '127.0.0.1';
    $ws_port = intval(config('webssh.port') ?: 8888);
    
    // 检查WebSSH服务是否可用
    $fp = @fsockopen($ws_host, $ws_port, $errno, $errstr, 2);
    if ($fp) {
        fclose($fp);
        // 如果有WebSSH服务，重定向到那里
        header('Location: ws://' . $ws_host . ':' . $ws_port . '/?host=' . $ssh_info['ip'] . '&port=' . $ssh_info['port'] . '&user=' . $ssh_info['user']);
        exit;
    }
    
    // 没有WebSocket服务，返回错误
    header('HTTP/1.1 503 Service Unavailable');
    echo 'WebSSH服务未启动';
    exit;
}

// 普通HTTP请求，返回说明
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WebSSH</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body style="background: var(--bg-page); min-height: 100vh; display: flex; align-items: center; justify-content: center;">
    <div style="background: #fff; padding: 32px; border-radius: var(--radius-lg); max-width: 500px; text-align: center; box-shadow: 0 4px 24px rgba(0,0,0,0.08);">
        <div style="font-size: 48px; margin-bottom: 16px;">🖥️</div>
        <h2 style="margin: 0 0 12px; color: var(--text-primary); font-size: 20px;">WebSSH 连接</h2>
        <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 24px; line-height: 1.6;">
            SSH连接信息：<br>
            <code style="background: var(--bg-light); padding: 2px 6px; border-radius: 4px; font-family: monospace;">
                <?php echo e($ssh_info['user'] . '@' . $ssh_info['ip'] . ':' . $ssh_info['port']); ?>
            </code>
        </p>
        <div style="background: #fffbeb; padding: 16px; border-radius: var(--radius-md); border: 1px solid #fde68a; margin-bottom: 24px; text-align: left;">
            <div style="font-size: 13px; color: #92400e; font-weight: 500; margin-bottom: 8px;">💡 使用说明</div>
            <div style="font-size: 12px; color: #b45309; line-height: 1.8;">
                方式一：使用SSH客户端连接<br>
                <code style="background: #fef3c7; padding: 2px 4px; border-radius: 3px;">
                    ssh -p <?php echo intval($ssh_info['port']); ?> <?php echo e($ssh_info['user']); ?>@<?php echo e($ssh_info['ip']); ?>
                </code>
                <br><br>
                方式二：使用在线VNC控制台<br>
                <a href="/novnc/vnc.html" style="color: var(--primary);">点击打开VNC控制台</a>
            </div>
        </div>
        <button onclick="history.back()" class="btn btn-secondary" style="margin-right: 8px;">返回</button>
        <button onclick="copySSH()" class="btn btn-primary">复制SSH命令</button>
    </div>
    <script>
    function copySSH() {
        var cmd = 'ssh -p <?php echo intval($ssh_info['port']); ?> <?php echo e($ssh_info['user']); ?>@<?php echo e($ssh_info['ip']); ?>';
        navigator.clipboard.writeText(cmd).then(function() {
            alert('SSH命令已复制到剪贴板');
        });
    }
    </script>
</body>
</html>
