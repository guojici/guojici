<?php
$is_installed = file_exists(__DIR__ . '/config/.installed');
if (!$is_installed) {
    header('Location: /install.php');
    exit;
}

require_once __DIR__ . '/config/helper.php';

$error = flash('error');
$success = flash('success');

if (is_post()) {
    $captcha_token = post('captcha_token', '');
    
    if (empty($captcha_token) || !isset($_SESSION['tac_token_' . $captcha_token])) {
        flash_set_old(['email' => post('email', '')]);
        flash('error', '请完成安全验证');
        header('Location: /login.php');
        exit;
    }
    
    $token_data = $_SESSION['tac_token_' . $captcha_token];
    if (empty($token_data['verified']) || time() > intval($token_data['expires'])) {
        unset($_SESSION['tac_token_' . $captcha_token]);
        flash_set_old(['email' => post('email', '')]);
        flash('error', '验证已过期，请重新验证');
        header('Location: /login.php');
        exit;
    }
    unset($_SESSION['tac_token_' . $captcha_token]);

    $email = trim(post('email', ''));
    $password = post('password', '');

    if (!$email || !$password) {
        flash_set_old(['email' => $email]);
        flash('error', '请输入邮箱和密码');
        header('Location: /login.php');
        exit;
    }

    // 登录暴力破解防护
    $brute = sec_check_login_brute_force($email);
    if (!empty($brute['blocked'])) {
        sec_log_login_attempt($email, false, 'brute_force_blocked');
        flash('error', $brute['reason']);
        header('Location: /login.php');
        exit;
    }

    $user = Database::fetch("SELECT * FROM users WHERE email = ? AND status = 'active'", [$email]);
    if (!$user || !password_verify($password, $user['password'])) {
        sec_log_login_attempt($email, false, 'invalid_credentials');
        flash_set_old(['email' => $email]);
        flash('error', '邮箱或密码错误');
        header('Location: /login.php');
        exit;
    }
    sec_log_login_attempt($email, true);

    migrate_new_tables();
    ensure_user_points($user['id']);
    $login_rule = Database::fetch("SELECT * FROM point_rules WHERE rule_key = 'daily_login' AND enabled = 1");
    if ($login_rule) {
        $last = Database::fetch("SELECT created_at FROM point_logs WHERE user_id = ? AND change_type = 'earn_daily' ORDER BY created_at DESC LIMIT 1", [$user['id']]);
        $can_award = true;
        if ($last && date('Y-m-d', strtotime($last['created_at'])) === date('Y-m-d')) {
            $can_award = false;
        }
        if ($can_award && intval($login_rule['points']) > 0) {
            change_points($user['id'], 'earn_daily', intval($login_rule['points']), '每日登录赠送');
        }
    }

    auth_login($user);
    flash('success', '登录成功');
    header('Location: /user/index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?php echo lang_html_attr(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __('auth.login_title'); ?> - <?php echo e(config('app.name')); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/templates/navbar.php'; ?>

    <div class="auth-container">
        <div class="auth-box fade-in">
            <h2><?php echo __('auth.login_title'); ?></h2>
            <p class="subtitle"><?php echo __('auth.login_title'); ?></p>

            <?php if ($error): ?>
                <div class="alert alert-error" id="formError"><?php echo e($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo e($success); ?></div>
            <?php endif; ?>

            <form method="POST" action="/login.php" id="loginForm" novalidate>
                <input type="hidden" name="captcha_token" value="">
                <div class="form-group">
                    <label><?php echo __('auth.email'); ?></label>
                    <input type="email" class="form-control" name="email" required placeholder="your@email.com" value="<?php echo e(old('email')); ?>">
                </div>
                <div class="form-group">
                    <label><?php echo __('auth.password'); ?></label>
                    <input type="password" class="form-control" name="password" required placeholder="<?php echo __('auth.password'); ?>">
                </div>
                <div class="auth-links">
                    <a href="/forgot.php" style="color: var(--text-secondary);"><?php echo __('auth.forgot_password'); ?></a>
                </div>
                <button type="button" class="btn btn-primary btn-lg" style="width:100%;" id="loginBtn"><?php echo __('auth.login_title'); ?></button>
            </form>

            <div class="auth-divider"><?php echo __('auth.no_account'); ?><a href="/register.php"><?php echo __('auth.register_now'); ?></a></div>
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
            var email = form.querySelector('[name=email]').value.trim();
            var password = form.querySelector('[name=password]').value;
            if (!email || !password) {
                var errEl = document.getElementById('formError');
                if (errEl) { errEl.textContent = '请输入邮箱和密码'; errEl.style.display = 'block'; }
                else alert('请输入邮箱和密码');
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
