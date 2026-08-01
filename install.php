<?php
session_start();
$step = intval($_GET['step'] ?? 1);
$message = '';
$error = '';

// 检查是否已安装
$is_installed = file_exists(__DIR__ . '/config/.installed');
if ($is_installed) {
    header('Location: /');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['db_host'])) {
        $db_host = $_POST['db_host'] ?? 'localhost';
        $db_port = intval($_POST['db_port'] ?? 3306);
        $db_name = $_POST['db_name'] ?? 'guojici';
        $db_user = $_POST['db_user'] ?? 'root';
        $db_pass = $_POST['db_pass'] ?? '';
        $admin_user = $_POST['admin_user'] ?? 'admin';
        $admin_pass = $_POST['admin_pass'] ?? 'admin123';
        $mnbt_url = rtrim($_POST['mnbt_url'] ?? '', '/');
        $mnbt_bh = $_POST['mnbt_bh'] ?? '';
        $mnbt_key = $_POST['mnbt_key'] ?? '';
        $mnbt_keye = $_POST['mnbt_keye'] ?? '';
        $mnbt_vs = $_POST['mnbt_vs'] ?? '17';

        try {
            $pdo = new PDO("mysql:host=$db_host;port=$db_port;charset=utf8mb4", $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            $error = '数据库连接失败: ' . $e->getMessage();
        }

        if (!$error) {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
            $pdo->exec("USE `$db_name`");

            $sql_file = __DIR__ . '/data/init.sql';
            if (file_exists($sql_file)) {
                $sql = file_get_contents($sql_file);
                // 先逐行移除注释（以 -- 开头的行和 /* */ 块注释），避免注释与 SQL 混在一起被误判
                $sql = preg_replace('/--[^\n]*/', '', $sql);
                $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
                $statements = array_filter(array_map('trim', explode(';', $sql)));
                foreach ($statements as $stmt) {
                    if (empty($stmt)) continue;
                    try {
                        $pdo->exec($stmt);
                    } catch (PDOException $e) {
                        if (strpos($e->getMessage(), 'already exist') === false) {
                            $error .= "\n" . $e->getMessage();
                        }
                    }
                }

                $hashed = password_hash($admin_pass, PASSWORD_DEFAULT);
                try {
                    $pdo->exec("UPDATE admin_users SET username='$admin_user', password='$hashed' WHERE id=1");
                } catch (Exception $e) {}
            }

            $config_code = "<?php\n\$config = [\n";
            $config_code .= "    'db' => [\n";
            $config_code .= "        'host' => '$db_host',\n";
            $config_code .= "        'port' => $db_port,\n";
            $config_code .= "        'name' => '$db_name',\n";
            $config_code .= "        'user' => '$db_user',\n";
            $config_code .= "        'pass' => '$db_pass',\n";
            $config_code .= "        'charset' => 'utf8mb4',\n";
            $config_code .= "    ],\n";
            $config_code .= "    'mnbt' => [\n";
            $config_code .= "        'base_url' => '$mnbt_url',\n";
            $config_code .= "        'mn_bh' => '$mnbt_bh',\n";
            $config_code .= "        'mn_key' => '$mnbt_key',\n";
            $config_code .= "        'mn_keye' => '$mnbt_keye',\n";
            $config_code .= "        'mn_vs' => '$mnbt_vs',\n";
            $config_code .= "    ],\n";
            $config_code .= "    'app' => [\n";
            $config_code .= "        'name' => 'guojici云',\n";
            $config_code .= "        'version' => '1.0.0',\n";
            $config_code .= "        'debug' => false,\n";
            $config_code .= "        'site_url' => '',\n";
            $config_code .= "    ],\n";
            $config_code .= "    'payment' => [\n";
            $config_code .= "        'enabled' => false,\n";
            $config_code .= "    ],\n";
            $config_code .= "];\n\n";
            $config_code .= "date_default_timezone_set('Asia/Shanghai');\n";
            $config_code .= "ini_set('display_errors', \$config['app']['debug'] ? 1 : 0);\n\n";
            $config_code .= "define('ROOT_PATH', dirname(__DIR__));\n";
            $config_code .= "define('ASSETS_URL', '/assets');\n";
            $config_code .= "define('ADMIN_PREFIX', '/admin');\n";
            $config_code .= "define('USER_PREFIX', '/user');\n\n";
            $config_code .= "function config(\$key = null) {\n";
            $config_code .= "    global \$config;\n";
            $config_code .= "    if (\$key === null) return \$config;\n";
            $config_code .= "    \$parts = explode('.', \$key);\n";
            $config_code .= "    \$current = \$config;\n";
            $config_code .= "    foreach (\$parts as \$part) {\n";
            $config_code .= "        if (!isset(\$current[\$part])) return null;\n";
            $config_code .= "        \$current = \$current[\$part];\n";
            $config_code .= "    }\n";
            $config_code .= "    return \$current;\n";
            $config_code .= "}\n";

            @file_put_contents(__DIR__ . '/config/app.php', $config_code);
            @file_put_contents(__DIR__ . '/config/.installed', '1');

            // 最小依赖加载（不依赖 helper.php）
            if (!function_exists('config')) {
                require_once __DIR__ . '/config/app.php';
            }
            if (!class_exists('Database')) {
                require_once __DIR__ . '/config/db.php';
            }

            $message = "安装成功！请手动删除 install.php 文件，或重命名为 install.php.bak 以防止重复安装。";
            $step = 3;
        } else {
            $step = 2;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>安装 - guojici云</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        body {
            background: linear-gradient(135deg, #0a0e1a 0%, #1a2332 100%);
            min-height: 100vh;
        }
        .install-box {
            max-width: 720px;
            margin: 60px auto;
            background: #1a2332;
            border-radius: 20px;
            border: 1px solid #2a3b5c;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.4);
        }
        .install-header {
            padding: 40px;
            text-align: center;
            background: linear-gradient(135deg, #00d4ff 0%, #0066cc 100%);
            color: white;
        }
        .install-header h1 { font-size: 28px; margin-bottom: 10px; }
        .install-body { padding: 40px; }
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 40px;
            gap: 40px;
        }
        .step-item {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #64748b;
            font-weight: 600;
        }
        .step-item.active {
            color: #00d4ff;
        }
        .step-item.done {
            color: #10b981;
        }
        .step-num {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #2a3b5c;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }
        .step-item.active .step-num { background: #00d4ff; color: #0a0e1a; }
        .step-item.done .step-num { background: #10b981; color: white; }
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }
    </style>
</head>
<body>
    <div class="install-box">
        <div class="install-header">
            <h1>guojici云</h1>
            <p>安装向导</p>
        </div>
        <div class="install-body">
            <?php if ($step > 0): ?>
            <div class="step-indicator">
                <div class="step-item <?php echo $step == 1 ? 'active' : ($step > 1 ? 'done' : ''); ?>">
                    <div class="step-num">1</div>
                    <span>环境检测</span>
                </div>
                <div class="step-item <?php echo $step == 2 ? 'active' : ($step > 2 ? 'done' : ''); ?>">
                    <div class="step-num">2</div>
                    <span>配置信息</span>
                </div>
                <div class="step-item <?php echo $step == 3 ? 'active' : ''; ?>">
                    <div class="step-num">3</div>
                    <span>完成</span>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error" style="margin-bottom: 20px;"><?php echo nl2br(htmlspecialchars($error)); ?></div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="alert alert-success" style="margin-bottom: 20px;"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <?php if ($step == 1): ?>
                <h2 style="margin-bottom: 20px;">环境检测</h2>
                <?php
                $checks = [
                    'PHP 版本 >= 7.4' => version_compare(phpversion(), '7.4', '>='),
                    'PDO 扩展' => extension_loaded('pdo_mysql'),
                    'cURL 扩展' => extension_loaded('curl'),
                    'JSON 扩展' => extension_loaded('json'),
                    'config 目录可写' => is_writable(__DIR__ . '/config'),
                    'data 目录可读' => is_readable(__DIR__ . '/data/init.sql'),
                ];
                ?>
                <div style="background: #111827; padding: 20px; border-radius: 10px; margin-bottom: 24px;">
                    <?php foreach ($checks as $name => $ok): ?>
                        <div style="padding: 12px 0; border-bottom: 1px solid #2a3b5c; display: flex; justify-content: space-between;">
                            <span><?php echo $name; ?></span>
                            <span style="color: <?php echo $ok ? '#10b981' : '#ef4444'; ?>; font-weight: 600;">
                                <?php echo $ok ? '✓ 通过' : '✗ 未通过'; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($is_installed): ?>
                    <div class="alert alert-warning" style="margin-bottom: 20px;">
                        检测到系统可能已安装，如果重新安装将覆盖现有数据！
                    </div>
                <?php endif; ?>
                <a href="?step=2" class="btn btn-primary btn-lg" style="width: 100%;">下一步：配置信息</a>

            <?php elseif ($step == 2): ?>
                <h2 style="margin-bottom: 20px;">配置信息</h2>
                <form method="POST" action="?step=2">
                    <h3 style="margin: 24px 0 16px; color: #00d4ff; font-size: 16px;">数据库配置</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>主机</label>
                            <input type="text" class="form-control" name="db_host" value="localhost" required>
                        </div>
                        <div class="form-group">
                            <label>端口</label>
                            <input type="number" class="form-control" name="db_port" value="3306" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>数据库名</label>
                            <input type="text" class="form-control" name="db_name" value="guojici" required>
                        </div>
                        <div class="form-group"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>用户名</label>
                            <input type="text" class="form-control" name="db_user" value="root" required>
                        </div>
                        <div class="form-group">
                            <label>密码</label>
                            <input type="password" class="form-control" name="db_pass">
                        </div>
                    </div>

                    <h3 style="margin: 32px 0 16px; color: #00d4ff; font-size: 16px;">管理员账号</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>用户名</label>
                            <input type="text" class="form-control" name="admin_user" value="admin" required>
                        </div>
                        <div class="form-group">
                            <label>密码</label>
                            <input type="text" class="form-control" name="admin_pass" value="admin123" required>
                        </div>
                    </div>

                    <h3 style="margin: 32px 0 16px; color: #00d4ff; font-size: 16px;">面板 API 配置 <span style="font-size: 12px; color: #94a3b8;">(可稍后在系统设置中修改)</span></h3>
                    <div class="form-group">
                        <label>面板系统地址</label>
                        <input type="text" class="form-control" name="mnbt_url" value="http://192.168.3.2:7894" placeholder="如 http://192.168.3.2:7894">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>宝塔编号 (mn_bh)</label>
                            <input type="text" class="form-control" name="mn_bh">
                        </div>
                        <div class="form-group">
                            <label>API密钥 (mn_key)</label>
                            <input type="text" class="form-control" name="mn_key">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>宝塔调用密钥 (mn_keye)</label>
                            <input type="text" class="form-control" name="mn_keye">
                        </div>
                        <div class="form-group">
                            <label>版本 (mn_vs)</label>
                            <input type="text" class="form-control" name="mn_vs" value="17">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg" style="width: 100%; margin-top: 24px;">开始安装</button>
                </form>

            <?php elseif ($step == 3): ?>
                <div style="text-align: center; padding: 40px 0;">
                    <div style="font-size: 80px; margin-bottom: 20px;">🎉</div>
                    <h2 style="margin-bottom: 16px; color: #10b981;">安装成功！</h2>
                    <p style="color: #94a3b8; margin-bottom: 32px;">guojici云已成功安装</p>
                    <div style="background: #111827; padding: 24px; border-radius: 10px; margin-bottom: 32px; text-align: left;">
                        <div style="margin-bottom: 16px; font-weight: 600;">系统信息：</div>
                        <div style="margin-bottom: 8px;">📱 前台首页: <a href="/" style="color: #00d4ff;">/</a></div>
                        <div style="margin-bottom: 8px;">👤 用户中心: <a href="/user/index.php" style="color: #00d4ff;">/user/index.php</a></div>
                        <div style="margin-bottom: 8px;">🔐 管理后台: <a href="/admin/login.php" style="color: #00d4ff;">/admin/login.php</a></div>
                    </div>
                    <div style="display: flex; gap: 16px; justify-content: center; flex-wrap: wrap;">
                        <a href="/" class="btn btn-primary btn-lg">进入首页</a>
                        <a href="/admin/login.php" class="btn btn-secondary btn-lg">进入管理后台</a>
                    </div>
                    <div style="margin-top: 32px; padding: 16px; background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 8px; color: #f59e0b; font-size: 13px;">
                        ⚠️ 安全提示：安装完成后请手动删除或重命名 <code>install.php</code> 文件
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
