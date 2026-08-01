<?php
/**
 * 管理员密码重置页面（一键重置为 admin123）
 * 仅在无管理员或忘记密码时使用
 */

require_once __DIR__ . '/config/helper.php';

$error = '';
$success = '';

if (is_post()) {
    $action = post('action', '');

    if ($action === 'reset') {
        // 检查是否有.installed文件（防止已安装后随意重置）
        $installed_file = __DIR__ . '/.installed';
        if (file_exists($installed_file)) {
            $error = '系统已安装，为安全起见，无法通过此页面重置密码。请联系超级管理员。';
        } else {
            // 重置管理员密码为 admin123
            $default_password = 'admin123';
            $hashed_password = password_hash($default_password, PASSWORD_DEFAULT);

            // 检查管理员是否存在
            $admin = Database::fetch("SELECT * FROM admins WHERE username = ?", ['admin']);
            if ($admin) {
                Database::update('admins', ['password' => $hashed_password], 'id = ?', [$admin['id']]);
            } else {
                Database::insert('admins', [
                    'username' => 'admin',
                    'password' => $hashed_password,
                    'email' => 'admin@example.com',
                    'role' => 'super',
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            $success = '管理员密码已重置为：admin123';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>重置管理员密码 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .reset-box {
            background: #fff;
            border-radius: 12px;
            padding: 40px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        .reset-box h2 {
            text-align: center;
            margin-bottom: 8px;
            color: #1d2129;
        }
        .reset-box .subtitle {
            text-align: center;
            color: #86909c;
            font-size: 14px;
            margin-bottom: 24px;
        }
        .alert {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .alert-error {
            background: #fff1f0;
            color: #ff4d4f;
            border: 1px solid #ffccc7;
        }
        .alert-success {
            background: #f6ffed;
            color: #52c41a;
            border: 1px solid #b7eb8f;
        }
        .btn {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #1677ff, #4096ff);
            color: #fff;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(22, 119, 255, 0.4);
        }
        .back-link {
            text-align: center;
            margin-top: 20px;
            font-size: 13px;
        }
        .back-link a {
            color: #1677ff;
            text-decoration: none;
        }
        .warning-box {
            background: #fffbe6;
            border: 1px solid #ffe58f;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #d48806;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="reset-box">
        <h2>重置管理员密码</h2>
        <p class="subtitle">一键重置管理员账户密码</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo e($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo e($success); ?></div>
        <?php endif; ?>

        <?php if (!$success): ?>
            <div class="warning-box">
                <strong>⚠️ 注意：</strong><br>
                此操作会将管理员密码重置为默认密码 <strong>admin123</strong>。<br>
                仅在系统未安装或您是唯一管理员时使用。
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="reset">
                <button type="submit" class="btn btn-primary">重置为 admin123</button>
            </form>
        <?php endif; ?>

        <div class="back-link">
            <a href="/admin/login.php">← 返回登录</a>
        </div>
    </div>
</body>
</html>
