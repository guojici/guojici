<?php
require_once __DIR__ . '/../config/helper.php';
require_auth();

$user = auth_user();
$uid = auth_id();

$success = flash('success');
$error = flash('error');

if (is_post()) {
    $action = post('action');
    if ($action === 'update_profile') {
        $username = trim(post('username', ''));
        $phone = trim(post('phone', ''));
        Database::update('users', ['username' => $username, 'phone' => $phone], 'id = ?', [$uid]);
        flash('success', '个人资料已更新');
        header('Location: /user/profile.php');
        exit;
    }
    if ($action === 'change_password') {
        $old_pwd = post('old_password');
        $new_pwd = post('new_password');
        $confirm_pwd = post('confirm_password');
        $user_db = Database::fetch("SELECT * FROM users WHERE id = ?", [$uid]);
        if (!password_verify($old_pwd, $user_db['password'])) {
            flash('error', '原密码错误');
        } elseif ($new_pwd !== $confirm_pwd) {
            flash('error', '两次密码输入不一致');
        } elseif (strlen($new_pwd) < 6) {
            flash('error', '新密码长度至少6位');
        } else {
            Database::update('users', ['password' => password_hash($new_pwd, PASSWORD_DEFAULT)], 'id = ?', [$uid]);
            flash('success', '密码修改成功');
        }
        header('Location: /user/profile.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>个人资料 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>

        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">个人资料</h1>
                    <p class="page-subtitle">管理您的账户信息</p>
                </div>
            </div>

            <?php if ($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

            <div class="card">
                <div class="card-title">基本信息</div>
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="form-row">
                        <div class="form-group">
                            <label>用户名</label>
                            <input type="text" class="form-control" name="username" value="<?php echo e($user['username']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>邮箱</label>
                            <input type="email" class="form-control" value="<?php echo e($user['email']); ?>" disabled style="opacity: 0.6;">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>手机号</label>
                        <input type="text" class="form-control" name="phone" value="<?php echo e($user['phone']); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">保存修改</button>
                </form>
            </div>

            <div class="card">
                <div class="card-title">修改密码</div>
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-group">
                        <label>原密码</label>
                        <input type="password" class="form-control" name="old_password" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>新密码</label>
                            <input type="password" class="form-control" name="new_password" required>
                        </div>
                        <div class="form-group">
                            <label>确认新密码</label>
                            <input type="password" class="form-control" name="confirm_password" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">修改密码</button>
                </form>
            </div>

            <div class="card">
                <div class="card-title">账户信息</div>
                <div class="credentials-box">
                    <div class="credentials-row">
                        <span class="label">用户ID</span>
                        <span class="value"><?php echo $user['id']; ?></span>
                    </div>
                    <div class="credentials-row">
                        <span class="label">账户余额</span>
                        <span class="value">¥<?php echo number_format($user['balance'], 2); ?></span>
                    </div>
                    <div class="credentials-row">
                        <span class="label">账户状态</span>
                        <span class="value"><?php echo get_status_label($user['status'], 'user'); ?></span>
                    </div>
                    <div class="credentials-row">
                        <span class="label">注册时间</span>
                        <span class="value"><?php echo format_date($user['created_at']); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
