<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

$is_installed = file_exists(__DIR__ . '/../config/.installed');
if (!$is_installed) {
    header('Location: /install.php');
    exit;
}

require_once __DIR__ . '/../config/helper.php';

if (admin_check()) {
    header('Location: /admin/index.php');
    exit;
}

$error = flash('error');

if (is_post()) {
    try {
        $captcha_token = post('captcha_token', '');

        if (empty($captcha_token) || !isset($_SESSION['tac_token_' . $captcha_token])) {
            flash('error', '请完成安全验证');
            header('Location: /admin/login.php');
            exit;
        }

        $token_data = $_SESSION['tac_token_' . $captcha_token];
        if (empty($token_data['verified']) || time() > intval($token_data['expires'])) {
            unset($_SESSION['tac_token_' . $captcha_token]);
            flash('error', '验证已过期，请重新验证');
            header('Location: /admin/login.php');
            exit;
        }
        unset($_SESSION['tac_token_' . $captcha_token]);

        $pdo = db();
        $username = trim(post('username', ''));
        $password = post('password', '');

        // 管理员登录暴力破解防护
        $brute = sec_check_login_brute_force($username);
        if (!empty($brute['blocked'])) {
            sec_log_login_attempt($username, false, 'admin_brute_force_blocked');
            flash('error', $brute['reason']);
            header('Location: /admin/login.php');
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin && password_verify($password, $admin['password'])) {
            $stmt = $pdo->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$admin['id']]);

            sec_log_login_attempt($username, true, 'admin_login');
            admin_login($admin);
            header('Location: /admin/index.php');
            exit;
        }

        sec_log_login_attempt($username, false, 'admin_invalid_credentials');
        flash('error', '用户名或密码错误');
        header('Location: /admin/login.php');
        exit;
    } catch (Exception $e) {
        flash('error', '系统错误: ' . $e->getMessage());
        header('Location: /admin/login.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员登录 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="auth-container">
        <div class="auth-box fade-in">
            <h2 style="color: var(--accent);">管理员登录</h2>
            <p class="subtitle">请使用管理员账号登录后台</p>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo e($error); ?></div>
            <?php endif; ?>
            <form method="POST" id="loginForm">
                <input type="hidden" name="captcha_token" value="">
                <div class="form-group">
                    <label>用户名</label>
                    <input type="text" class="form-control" name="username" required placeholder="admin" autofocus>
                </div>
                <div class="form-group">
                    <label>密码</label>
                    <input type="password" class="form-control" name="password" required placeholder="请输入密码">
                </div>
                <button type="button" class="btn btn-primary btn-lg" style="width: 100%;" id="loginBtn">登录</button>
            </form>
            <div class="auth-divider">
                <a href="/" style="color: var(--text-secondary);">← 返回首页</a>
            </div>
            <div style="background: var(--bg-card); border-radius: 8px; padding: 12px; font-size: 12px; color: var(--text-muted); text-align: center;">
                默认账号: admin / admin123
            </div>
            <div style="margin-top:12px; text-align:center; font-size:12px;">
                忘记密码? <a href="/reset_admin.php" style="color: var(--primary);">重置为 admin123</a>
            </div>
        </div>
    </div>

    <!-- Tianai-Captcha 验证码容器 -->
    <div id="captcha-box" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;z-index:9999;justify-content:center;align-items:center;"></div>
    
    <!-- Tianai-Captcha 官方SDK -->
    <link rel="stylesheet" href="/hym_license/tac/css/tac.css">
    <script src="/hym_license/tac/load.min.js"></script>
    <script>
    (function () {
        var btn = document.getElementById('loginBtn');
        if (!btn) return;

        btn.addEventListener('click', function () {
            var form = document.getElementById('loginForm');
            var username = form.querySelector('[name=username]').value.trim();
            var password = form.querySelector('[name=password]').value;
            if (!username || !password) {
                alert('请输入用户名和密码');
                return;
            }

            var captchaBox = document.getElementById('captcha-box');
            captchaBox.style.display = 'flex';
            captchaBox.style.background = 'rgba(0,0,0,0.5)';

            var captchaConfig = {
                requestCaptchaDataUrl: "/captcha.php?action=gen",
                validCaptchaUrl: "/captcha.php?action=check",
                bindEl: "#captcha-box",
                validSuccess: function(res, c, t) {
                    t.destroyWindow();
                    captchaBox.style.display = 'none';
                    if (res.data && res.data.token) {
                        form.querySelector('[name=captcha_token]').value = res.data.token;
                    }
                    form.submit();
                },
                validFail: function(res, c, t) {
                    console.log("验证失败", res);
                    t.reloadCaptcha();
                },
                btnRefreshFun: function(el, tac) {
                    tac.reloadCaptcha();
                },
                btnCloseFun: function(el, tac) {
                    tac.destroyWindow();
                    captchaBox.style.display = 'none';
                }
            };

            var style = {};
            window.loadTAC("/hym_license/tac/", captchaConfig, style).then(function(tac) {
                tac.init();
            }).catch(function(e) {
                captchaBox.style.display = 'none';
                console.error("加载验证码失败", e);
                alert("验证码加载失败，请刷新页面重试");
            });
        });
    })();
    </script>
</body>
</html>
