<?php
/**
 * 数据库初始化脚本
 * 用法：浏览器打开 http://你的域名/init_db.php
 * 成功后请删除本文件
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';

$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = db();
        $sql_file = __DIR__ . '/data/init.sql';
        if (!file_exists($sql_file)) {
            $error = "找不到 data/init.sql 文件";
        } else {
            $sql = file_get_contents($sql_file);
            // 先逐行移除注释（以 -- 开头的行和 /* */ 块注释），避免注释与 SQL 混在一起被误判
            $sql = preg_replace('/--[^\n]*/', '', $sql);
            $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            $count = 0;
            foreach ($statements as $stmt) {
                if (empty($stmt)) continue;
                try {
                    $pdo->exec($stmt);
                    $count++;
                } catch (PDOException $e) {
                    // 忽略 "already exists" 类错误
                    if (strpos($e->getMessage(), 'already exist') === false) {
                        $error .= "\n" . $e->getMessage();
                    }
                }
            }
            $msg = "数据库初始化成功！执行了 $count 条 SQL 语句。";
            @file_put_contents(__DIR__ . '/config/.installed', '1');
        }
    } catch (PDOException $e) {
        $error = "数据库连接失败: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>数据库初始化 - guojici云</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="auth-container">
        <div class="auth-box fade-in">
            <h2>数据库初始化</h2>
            <p class="subtitle">点击下方按钮创建数据表（数据表已存在会自动跳过）</p>

            <?php if ($msg): ?>
                <div class="alert alert-success" style="margin-bottom:20px;"><?php echo htmlspecialchars($msg); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error" style="margin-bottom:20px; white-space:pre-line;"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div style="background:#f8fafc; padding:16px; border-radius:8px; margin-bottom:20px; font-size:13px; color:#475569; line-height:1.8;">
                <div><strong>当前配置：</strong></div>
                <div>数据库主机：<?php echo config('db.host'); ?>:<?php echo config('db.port'); ?></div>
                <div>数据库名：<?php echo config('db.name'); ?></div>
                <div>用户名：<?php echo config('db.user'); ?></div>
            </div>

            <form method="POST">
                <button type="submit" class="btn btn-primary btn-lg" style="width: 100%;">开始初始化</button>
            </form>

            <div class="auth-divider" style="margin-top:24px;">
                <div style="color:#86909c; font-size:13px; line-height:1.8;">
                    <p>完成后默认账号：</p>
                    <p>管理员：admin / admin123</p>
                    <p>测试用户：test@example.com / 123456</p>
                </div>
            </div>

            <?php if ($msg): ?>
                <div style="margin-top:20px; display:flex; gap:12px; justify-content:center;">
                    <a href="/" class="btn btn-primary">进入首页</a>
                    <a href="/admin/login.php" class="btn btn-secondary">管理后台</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
