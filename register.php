<?php
$is_installed = file_exists(__DIR__ . '/config/.installed');
if (!$is_installed) {
    header('Location: /install.php');
    exit;
}

require_once __DIR__ . '/config/helper.php';

migrate_new_tables();

$error = flash('error');
$ref_code = trim(get('ref', ''));

// 检查邮件验证码是否启用
$email_code_config = db_get_settings('captcha');
$email_code_enabled = !empty($email_code_config['enable_email_code']);

// 检查 SMTP 是否启用
$smtp_enabled = false;
$db_smtp_enabled = db_get_setting('smtp_enabled');
if ($db_smtp_enabled !== null) {
    $smtp_enabled = ($db_smtp_enabled == '1');
} else {
    $smtp_enabled = config('smtp.enabled', false);
}

if (is_post()) {
    $action = post('action', '');

    // 发送验证码
    if ($action === 'send_code') {
        $email = trim(post('email', ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(['code' => 1, 'msg' => '请输入有效的邮箱地址']);
        }

        // 检查邮箱是否已注册
        $existing = Database::fetch("SELECT id FROM users WHERE email = ?", [$email]);
        if ($existing) {
            json_response(['code' => 1, 'msg' => '该邮箱已被注册，请直接登录']);
        }

        // 检查 SMTP 是否启用
        if (!$smtp_enabled) {
            json_response(['code' => 1, 'msg' => '邮件服务暂未启用，请联系管理员']);
        }

        try {
            $email_php = __DIR__ . '/email.php';
            if (!file_exists($email_php)) {
                json_response(['code' => 1, 'msg' => '邮件模块文件缺失，请联系管理员']);
            }
            require_once $email_php;
            if (!function_exists('send_verify_email')) {
                json_response(['code' => 1, 'msg' => '邮件发送模块初始化失败，请联系管理员']);
            }
            $result = send_verify_email($email, 'register');
            if ($result['success']) {
                json_response(['code' => 0, 'msg' => '验证码已发送，请注意查收']);
            } else {
                json_response(['code' => 1, 'msg' => $result['message']]);
            }
        } catch (Exception $e) {
            json_response(['code' => 1, 'msg' => '邮件发送异常: ' . $e->getMessage()]);
        } catch (Error $e) {
            json_response(['code' => 1, 'msg' => '系统错误: ' . $e->getMessage()]);
        }
    }

    $captcha_token = post('captcha_token', '');

    if (empty($captcha_token) || !isset($_SESSION['tac_token_' . $captcha_token])) {
        flash_set_old([
            'username' => post('username', ''),
            'email' => post('email', ''),
            'ref_code' => post('ref_code', ''),
        ]);
        flash('error', '请完成安全验证');
        header('Location: /register.php');
        exit;
    }

    $token_data = $_SESSION['tac_token_' . $captcha_token];
    if (empty($token_data['verified']) || time() > intval($token_data['expires'])) {
        unset($_SESSION['tac_token_' . $captcha_token]);
        flash_set_old([
            'username' => post('username', ''),
            'email' => post('email', ''),
            'ref_code' => post('ref_code', ''),
        ]);
        flash('error', '验证已过期，请重新验证');
        header('Location: /register.php');
        exit;
    }
    unset($_SESSION['tac_token_' . $captcha_token]);

    $username = trim(post('username', ''));
    $email = trim(post('email', ''));
    $password = post('password', '');
    $password_confirm = post('password_confirm', '');
    $ref_code_post = trim(post('ref_code', ''));
    $agree = post('agree', '');

    // 检查隐私协议
    if (!$agree) {
        flash_set_old([
            'username' => $username,
            'email' => $email,
            'ref_code' => $ref_code_post,
        ]);
        flash('error', '请阅读并同意隐私协议和服务政策');
        header('Location: /register.php');
        exit;
    }

    if (!$username || !$email || !$password) {
        flash('error', '请填写所有必填项');
        header('Location: /register.php');
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', '请输入有效的邮箱地址');
        header('Location: /register.php');
        exit;
    }
    if ($password !== $password_confirm) {
        flash('error', '两次密码输入不一致');
        header('Location: /register.php');
        exit;
    }
    if (strlen($password) < 6) {
        flash('error', '密码长度至少6位');
        header('Location: /register.php');
        exit;
    }

    // 邮件验证码校验（如果启用）
    if ($email_code_enabled && $smtp_enabled) {
        $email_code = trim(post('email_code', ''));
        if (!$email_code) {
            flash('error', '请输入邮箱验证码');
            header('Location: /register.php');
            exit;
        }
        $verify_result = verify_code($email_code);
        if (!$verify_result['success']) {
            flash('error', $verify_result['message']);
            header('Location: /register.php');
            exit;
        }
        clear_verify_code();
    }

    // 检查邮箱是否已注册
    $existing = Database::fetch("SELECT id FROM users WHERE email = ?", [$email]);
    if ($existing) {
        flash('error', '该邮箱已被注册，请直接登录');
        header('Location: /register.php');
        exit;
    }

    $id = Database::insert('users', [
        'username' => $username,
        'email' => $email,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'balance' => 0.00,
        'status' => 'active',
    ]);

    ensure_user_points($id);

    $reg_rule = Database::fetch("SELECT * FROM point_rules WHERE rule_key = 'register' AND enabled = 1");
    if ($reg_rule && intval($reg_rule['points']) > 0) {
        change_points($id, 'earn_register', intval($reg_rule['points']), '注册赠送');
    }

    $used_ref = !empty($ref_code_post) ? $ref_code_post : $ref_code;
    if (!empty($used_ref)) {
        $referrer = Database::fetch("SELECT r.id, r.referrer_id, u.id as uid FROM referrals r JOIN users u ON r.referrer_id = u.id WHERE r.referral_code = ? AND r.referrer_id != r.referred_id", [strtoupper($used_ref)]);
        if ($referrer && $referrer['uid'] != $id) {
            try {
                Database::insert('referrals', [
                    'referrer_id' => $referrer['referrer_id'],
                    'referred_id' => $id,
                    'referral_code' => strtoupper($used_ref),
                    'rebate_amount' => 0,
                    'rebate_count' => 0,
                ]);
            } catch (Exception $e) {}
            $ref_rule = Database::fetch("SELECT * FROM point_rules WHERE rule_key = 'referral_signup' AND enabled = 1");
            if ($ref_rule && intval($ref_rule['points']) > 0) {
                change_points($referrer['referrer_id'], 'earn_referral', intval($ref_rule['points']), '推广用户注册: ' . $username);
            }

            // ====== 绑定到代理客户表 ======
            // 查找推广人是否是代理
            $agent = Database::fetch("SELECT id FROM agents WHERE user_id = ? AND status = 'active'", [$referrer['referrer_id']]);
            if ($agent) {
                try {
                    // 检查是否已绑定
                    $existing = Database::fetch("SELECT id FROM agent_customers WHERE agent_id = ? AND user_id = ?", [$agent['id'], $id]);
                    if (!$existing) {
                        Database::insert('agent_customers', [
                            'agent_id' => $agent['id'],
                            'user_id' => $id,
                            'status' => 'active',
                            'total_orders' => 0,
                            'total_amount' => 0,
                        ]);
                    }
                } catch (Exception $e) {}
            }
        }
    } else {
        get_user_referral_code($id);
    }

    $user = Database::fetch("SELECT * FROM users WHERE id = ?", [$id]);
    auth_login($user);
    flash('success', '注册成功');
    header('Location: /user/index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?php echo lang_html_attr(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __('auth.register_title'); ?> - <?php echo e(config('app.name')); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/templates/navbar.php'; ?>

    <div class="auth-container">
        <div class="auth-box fade-in">
            <h2><?php echo __('auth.register_title'); ?></h2>
            <p class="subtitle"><?php echo __('auth.register_now'); ?></p>

            <?php if ($error): ?>
                <div class="alert alert-error" id="formError"><?php echo e($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="/register.php" id="registerForm" novalidate>
                <input type="hidden" name="captcha_token" value="">
                <div class="form-group">
                    <label><?php echo __('auth.username'); ?></label>
                    <input type="text" class="form-control" name="username" required placeholder="<?php echo __('auth.username'); ?>" value="<?php echo e(old('username')); ?>">
                </div>
                <div class="form-group">
                    <label><?php echo __('auth.email'); ?></label>
                    <input type="email" class="form-control" name="email" id="email" required placeholder="your@email.com" value="<?php echo e(old('email')); ?>">
                </div>
                <?php if ($email_code_enabled && $smtp_enabled): ?>
                <div class="form-row">
                    <div class="form-group">
                        <label>邮箱验证码</label>
                        <input type="text" class="form-control" name="email_code" id="email_code" required placeholder="请输入验证码" maxlength="6">
                    </div>
                    <div class="form-group" style="margin-left: 8px;">
                        <label style="visibility: hidden;">发送验证码</label>
                        <button type="button" class="btn btn-secondary" id="sendCodeBtn" style="height: 40px; margin-top: 28px;">发送验证码</button>
                    </div>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label><?php echo __('auth.password'); ?></label>
                    <input type="password" class="form-control" name="password" required placeholder="<?php echo __('auth.password'); ?>">
                </div>
                <div class="form-group">
                    <label><?php echo __('auth.confirm_password'); ?></label>
                    <input type="password" class="form-control" name="password_confirm" required placeholder="<?php echo __('auth.confirm_password'); ?>">
                </div>
                <?php if (!empty($ref_code)): ?>
                <div class="form-group" style="background: rgba(34,197,94,0.06); border: 1px solid rgba(34,197,94,0.2); padding: 10px 12px; border-radius: 6px;">
                    <label style="color: #22c55e; font-size: 12px;">推广码（已自动填入）</label>
                    <input type="text" class="form-control" name="ref_code" value="<?php echo e($ref_code); ?>" readonly style="background: transparent; border: none; color: #22c55e; font-weight: 600; padding: 4px 0;">
                </div>
                <?php else: ?>
                <div class="form-group">
                    <label>推广码 <span style="color: var(--text-secondary); font-weight: normal;">（选填）</span></label>
                    <input type="text" class="form-control" name="ref_code" placeholder="如: ABC12345" value="<?php echo e(old('ref_code')); ?>">
                </div>
                <?php endif; ?>
                <div class="form-group" style="display: flex; align-items: flex-start; gap: 8px;">
                    <input type="checkbox" name="agree" id="agree" value="1" style="margin-top: 4px;">
                    <label for="agree" style="font-weight: normal; font-size: 13px; color: var(--text-secondary);">
                        我已阅读并同意
                        <a href="/page/privacy.php" target="_blank" style="color: var(--primary);">《隐私协议》</a>和
                        <a href="/page/terms.php" target="_blank" style="color: var(--primary);">《服务政策》</a>
                    </label>
                </div>
                <button type="button" class="btn btn-primary btn-lg" style="width:100%;" id="registerBtn"><?php echo __('auth.register_now'); ?></button>
            </form>

            <div class="auth-divider"><?php echo __('auth.has_account'); ?><a href="/login.php"><?php echo __('auth.login_now'); ?></a></div>
        </div>
    </div>

    <!-- Tianai-Captcha 验证码容器 -->
    <div id="captcha-box" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;z-index:9999;justify-content:center;align-items:center;"></div>
    
    <!-- Tianai-Captcha 官方SDK -->
    <link rel="stylesheet" href="/hym_license/tac/css/tac.css">
    <script src="/hym_license/tac/load.min.js"></script>
    <script>
    (function () {
        // ====== 发送验证码 ======
        var sendBtn = document.getElementById('sendCodeBtn');
        var emailInput = document.getElementById('email');
        if (sendBtn && emailInput) {
            sendBtn.addEventListener('click', function () {
                var email = emailInput.value.trim();
                if (!email || !email.includes('@')) {
                    alert('请先输入有效的邮箱地址');
                    return;
                }

                // 弹出滑块验证
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

                        // 滑块验证通过，发送验证码
                        sendBtn.disabled = true;
                        sendBtn.textContent = '发送中...';

                        var xhr = new XMLHttpRequest();
                        xhr.open('POST', '/register.php');
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhr.onload = function () {
                            try {
                                var res = JSON.parse(xhr.responseText);
                                if (res.code == 0) {
                                    alert(res.msg);
                                    var count = 60;
                                    sendBtn.textContent = count + '秒后重发';
                                    var timer = setInterval(function () {
                                        count--;
                                        if (count <= 0) {
                                            clearInterval(timer);
                                            sendBtn.disabled = false;
                                            sendBtn.textContent = '发送验证码';
                                        } else {
                                            sendBtn.textContent = count + '秒后重发';
                                        }
                                    }, 1000);
                                } else {
                                    alert(res.msg);
                                    sendBtn.disabled = false;
                                    sendBtn.textContent = '发送验证码';
                                }
                            } catch (e) {
                                alert('请求失败，请重试');
                                sendBtn.disabled = false;
                                sendBtn.textContent = '发送验证码';
                            }
                        };
                        xhr.send('action=send_code&email=' + encodeURIComponent(email));
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
        }

        // ====== 注册按钮 ======
        var btn = document.getElementById('registerBtn');
        if (!btn) return;

        btn.addEventListener('click', function () {
            var form = document.getElementById('registerForm');
            var username = form.querySelector('[name=username]').value.trim();
            var email = form.querySelector('[name=email]').value.trim();
            var password = form.querySelector('[name=password]').value;
            var password_confirm = form.querySelector('[name=password_confirm]').value;
            var agree = form.querySelector('[name=agree]');
            var email_code = form.querySelector('[name=email_code]');

            if (!username || !email || !password) {
                var errEl = document.getElementById('formError');
                if (errEl) { errEl.textContent = '请填写所有必填项'; errEl.style.display = 'block'; }
                else alert('请填写所有必填项');
                return;
            }
            if (!email.includes('@')) {
                var errEl = document.getElementById('formError');
                if (errEl) { errEl.textContent = '请输入有效的邮箱地址'; errEl.style.display = 'block'; }
                else alert('请输入有效的邮箱地址');
                return;
            }
            if (password !== password_confirm) {
                var errEl = document.getElementById('formError');
                if (errEl) { errEl.textContent = '两次密码输入不一致'; errEl.style.display = 'block'; }
                else alert('两次密码输入不一致');
                return;
            }
            if (password.length < 6) {
                var errEl = document.getElementById('formError');
                if (errEl) { errEl.textContent = '密码长度至少6位'; errEl.style.display = 'block'; }
                else alert('密码长度至少6位');
                return;
            }
            // 检查隐私协议
            if (agree && !agree.checked) {
                var errEl = document.getElementById('formError');
                if (errEl) { errEl.textContent = '请阅读并同意隐私协议和服务政策'; errEl.style.display = 'block'; }
                else alert('请阅读并同意隐私协议和服务政策');
                return;
            }
            // 检查邮箱验证码（如果启用）
            if (email_code && !email_code.value.trim()) {
                var errEl = document.getElementById('formError');
                if (errEl) { errEl.textContent = '请输入邮箱验证码'; errEl.style.display = 'block'; }
                else alert('请输入邮箱验证码');
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
