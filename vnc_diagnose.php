<?php
/**
 * VNC连接诊断工具
 * 用于检查websockify、token映射和VNC端口配置
 */
require_once __DIR__ . '/config/helper.php';

// 仅允许管理员访问
if (!admin_check()) {
    header('Location: /admin/login.php');
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>VNC 连接诊断</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1a1a2e; color: #fff; }
        .section { margin-bottom: 20px; padding: 15px; background: rgba(255,255,255,0.05); border-radius: 8px; }
        .ok { color: #52c41a; }
        .error { color: #f53f3f; }
        .warn { color: #faad14; }
        pre { background: rgba(0,0,0,0.3); padding: 10px; border-radius: 4px; overflow-x: auto; }
        code { background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>🔍 VNC 连接诊断工具</h1>
    
    <?php
    // 1. 检查websockify进程
    echo '<div class="section"><h2>1. Websockify 进程检查</h2>';
    $output = [];
    exec('ps aux | grep websockify | grep -v grep', $output);
    if (!empty($output)) {
        echo '<p class="ok">✓ Websockify 正在运行</p>';
        echo '<pre>' . htmlspecialchars(implode("\n", $output)) . '</pre>';
        
        // 解析端口
        foreach ($output as $line) {
            if (preg_match('/0\.0\.0\.0:(\d+)/', $line, $matches)) {
                echo '<p>监听端口: <code>' . $matches[1] . '</code></p>';
            }
        }
    } else {
        echo '<p class="error">✗ Websockify 未运行</p>';
        echo '<p>启动命令示例：</p>';
        echo '<pre>python3 /usr/local/bin/websockify --web ' . realpath(__DIR__ . '/novnc') . ' --token-plugin TokenFile --token-source ' . realpath(__DIR__ . '/novnc/tokens') . '/tokens.conf 0.0.0.0:6080</pre>';
    }
    echo '</div>';
    
    // 2. 检查6080端口
    echo '<div class="section"><h2>2. 端口监听检查</h2>';
    $output = [];
    exec('netstat -tlnp | grep 6080', $output);
    if (!empty($output)) {
        echo '<p class="ok">✓ 端口 6080 正在监听</p>';
        echo '<pre>' . htmlspecialchars(implode("\n", $output)) . '</pre>';
    } else {
        echo '<p class="error">✗ 端口 6080 未监听</p>';
    }
    echo '</div>';
    
    // 3. 检查token映射文件
    echo '<div class="section"><h2>3. Token 映射文件</h2>';
    $token_file = __DIR__ . '/novnc/tokens/tokens.conf';
    if (file_exists($token_file)) {
        echo '<p class="ok">✓ Token文件存在: ' . $token_file . '</p>';
        $content = file_get_contents($token_file);
        if (!empty(trim($content))) {
            echo '<p>当前映射条目:</p>';
            echo '<pre>' . htmlspecialchars($content) . '</pre>';
        } else {
            echo '<p class="warn">⚠ Token文件为空，访问虚拟机时会自动生成</p>';
        }
    } else {
        echo '<p class="warn">⚠ Token文件不存在，首次访问VNC时会创建</p>';
    }
    echo '</div>';
    
    // 4. 检查KVM虚拟机状态
    echo '<div class="section"><h2>4. KVM 虚拟机状态</h2>';
    $output = [];
    exec('virsh list --all', $output);
    echo '<pre>' . htmlspecialchars(implode("\n", $output)) . '</pre>';
    
    // 检查特定虚拟机
    $vm_name = 'vm_1_10752_954';
    $output = [];
    exec("virsh dominfo {$vm_name} 2>&1", $output);
    echo '<p>虚拟机 <code>' . $vm_name . '</code> 信息:</p>';
    echo '<pre>' . htmlspecialchars(implode("\n", $output)) . '</pre>';
    
    // 获取VNC显示端口
    $output = [];
    exec("virsh vncdisplay {$vm_name} 2>&1", $output);
    echo '<p>VNC显示端口:</p>';
    echo '<pre>' . htmlspecialchars(implode("\n", $output)) . '</pre>';
    if (preg_match('/: (\d+)/', implode("\n", $output), $matches)) {
        $vnc_port = 5900 + intval($matches[1]);
        echo '<p>实际VNC端口: <code>' . $vnc_port . '</code></p>';
    }
    echo '</div>';
    
    // 5. 测试WebSocket连接
    echo '<div class="section"><h2>5. WebSocket 连接测试</h2>';
    echo '<p>请在浏览器控制台执行以下JavaScript测试连接：</p>';
    echo '<pre id="test-code">
var ws = new WebSocket("ws://192.168.3.2:6080/");
ws.onopen = function() { console.log("✓ WebSocket连接成功"); };
ws.onerror = function(e) { console.error("✗ WebSocket连接失败", e); };
ws.onclose = function() { console.log("WebSocket已关闭"); };
    </pre>';
    echo '<button onclick="eval(document.getElementById(\'test-code\').textContent)">测试连接</button>';
    echo '</div>';
    
    // 6. 防火墙检查
    echo '<div class="section"><h2>6. 防火墙规则</h2>';
    $output = [];
    exec('iptables -L INPUT -n | grep 6080', $output);
    if (!empty($output)) {
        echo '<p class="ok">✓ 防火墙允许6080端口</p>';
        echo '<pre>' . htmlspecialchars(implode("\n", $output)) . '</pre>';
    } else {
        echo '<p class="warn">⚠ 未找到6080端口的防火墙规则（可能使用firewalld或未限制）</p>';
        $output2 = [];
        exec('firewall-cmd --list-ports 2>/dev/null', $output2);
        if (!empty($output2)) {
            echo '<pre>' . htmlspecialchars(implode("\n", $output2)) . '</pre>';
        }
    }
    echo '</div>';
    
    // 7. 建议的修复步骤
    echo '<div class="section"><h2>📋 修复建议</h2>';
    echo '<ol>';
    echo '<li><strong>确认websockify运行：</strong><br>';
    echo '<code>systemctl status websockify</code> 或手动启动</li>';
    echo '<li><strong>检查VNC端口：</strong><br>';
    echo '从 virsh vncdisplay 获取正确的显示号，确保token映射中的端口匹配</li>';
    echo '<li><strong>清理过期token：</strong><br>';
    echo '<code>rm -f novnc/tokens/tokens.conf</code> 然后重新访问VNC页面</li>';
    echo '<li><strong>检查防火墙：</strong><br>';
    echo '<code>firewall-cmd --add-port=6080/tcp --permanent && firewall-cmd --reload</code></li>';
    echo '</ol>';
    echo '</div>';
    ?>
    
    <div class="section">
        <h2>ℹ️ 说明</h2>
        <p>此诊断工具帮助排查VNC黑屏问题。常见问题包括：</p>
        <ul>
            <li>websockify未运行或端口不匹配</li>
            <li>token映射文件中端口错误</li>
            <li>防火墙阻止6080端口</li>
            <li>虚拟机未开机或VNC未启用</li>
        </ul>
    </div>
</body>
</html>
