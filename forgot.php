<?php
require_once __DIR__ . '/config/helper.php';

migrate_new_tables();

$error = flash('error');
$success = flash('success');

if (is_post()) {
    $action = post('action', '');

    if ($action == 'send_code') {
        $email = trim(post('email', ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(['code' => 1, 'msg' => '请输入有效的邮箱地址']);
        }

        $user = Database::fetch("SELECT id, email FROM users WHERE email = ?", [$email]);
        if (!$user) {
            json_response(['code' => 1, 'msg' => '该邮箱未注册']);
        }

        // 检查SMTP是否启用
        $smtp_enabled = config('smtp.enabled');
        $db_enabled = db_get_setting('smtp_enabled');
        if ($db_enabled !== null) {
            $smtp_enabled = ($db_enabled == '1');
        }
        if (!$smtp_enabled) {
            json_response(['code' => 1, 'msg' => '邮件服务暂未启用，请联系管理员']);
        }

        // 使用邮件发送逻辑（带异常捕获，确保始终返回合法 JSON）
        try {
            $email_php = __DIR__ . '/email.php';
            if (!file_exists($email_php)) {
                json_response(['code' => 1, 'msg' => '邮件模块文件缺失，请联系管理员']);
            }
            require_once $email_php;
            if (!function_exists('send_verify_email')) {
                json_response(['code' => 1, 'msg' => '邮件发送模块初始化失败，请联系管理员']);
            }
            $result = send_verify_email($email, 'forgot');
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
    
    // 自适应验证校验（优先）+ 兼容旧 captcha_id
    $verify_token_post = post('verify_token', '');
    $captcha_id = post('captcha_id', '');
    $adaptive_ok = false;

    if (!empty($verify_token_post) && !empty($_SESSION['adaptive_verify_token'])
        && $verify_token_post === $_SESSION['adaptive_verify_token']
        && (time() - ($_SESSION['adaptive_verify_time'] ?? 0)) < 300) {
        $adaptive_ok = true;
        unset($_SESSION['adaptive_verify_token']);
    } elseif (!empty($captcha_id)) {
        require_once __DIR__ . '/config/TianaiCaptcha.php';
        $captcha = new TianaiCaptcha();
        if ($captcha->checkFinalize($captcha_id)) {
            $adaptive_ok = true;
        }
    }

    if (!$adaptive_ok) {
        flash_set_old(['email' => post('email', '')]);
        flash('error', '请完成安全验证');
        header('Location: /forgot.php');
        exit;
    }

    $email = trim(post('email', ''));
    $email_code = trim(post('email_code', ''));
    $password = post('password', '');
    $password_confirm = post('password_confirm', '');

    if (!$email || !$email_code || !$password) {
        flash('error', '请填写所有必填项');
        header('Location: /forgot.php');
        exit;
    }
    if ($password !== $password_confirm) {
        flash('error', '两次密码输入不一致');
        header('Location: /forgot.php');
        exit;
    }
    if (strlen($password) < 6) {
        flash('error', '密码长度至少6位');
        header('Location: /forgot.php');
        exit;
    }

    // 使用SESSION验证邮箱验证码
    $verify_result = verify_code($email_code);
    if (!$verify_result['success']) {
        flash('error', $verify_result['message']);
        header('Location: /forgot.php');
        exit;
    }

    $user = Database::fetch("SELECT id FROM users WHERE email = ?", [$email]);
    if (!$user) {
        flash('error', '该邮箱未注册');
        clear_verify_code();
        header('Location: /forgot.php');
        exit;
    }

    Database::update('users', ['password' => password_hash($password, PASSWORD_DEFAULT)], ['id' => $user['id']]);
    clear_verify_code();

    flash('success', '密码重置成功，请使用新密码登录');
    header('Location: /login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>忘记密码 - <?php echo e(config('app.name')); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/templates/navbar.php'; ?>

    <div class="auth-container">
        <div class="auth-box fade-in">
            <h2>忘记密码</h2>
            <p class="subtitle">输入您的注册邮箱，我们将发送验证码到您的邮箱</p>

            <?php if ($error): ?>
                <div class="alert alert-error" id="formError"><?php echo e($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo e($success); ?></div>
            <?php endif; ?>

            <form method="POST" action="/forgot.php" id="forgotForm" novalidate>
                <input type="hidden" name="verify_token" value="">
                <input type="hidden" name="captcha_id" value="">
                <div class="form-group">
                    <label>邮箱地址</label>
                    <input type="email" class="form-control" name="email" required placeholder="your@email.com" value="<?php echo e(old('email')); ?>">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>邮箱验证码</label>
                        <input type="text" class="form-control" name="email_code" required placeholder="请输入验证码" maxlength="6">
                    </div>
                    <div class="form-group" style="margin-left: 8px;">
                        <label style="visibility: hidden;">发送验证码</label>
                        <button type="button" class="btn btn-secondary" id="sendCodeBtn" style="height: 40px; margin-top: 28px;">发送验证码</button>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>新密码</label>
                        <input type="password" class="form-control" name="password" required placeholder="至少6位">
                    </div>
                    <div class="form-group">
                        <label>确认密码</label>
                        <input type="password" class="form-control" name="password_confirm" required placeholder="再次输入密码">
                    </div>
                </div>
                <button type="button" class="btn btn-primary btn-lg" style="width:100%;" id="submitBtn">重置密码</button>
            </form>

            <div class="auth-divider">记起密码了？<a href="/login.php">立即登录</a></div>
        </div>
    </div>

    <script src="/assets/device-fingerprint.js"></script>
    <script src="/assets/tianai-captcha.js"></script>
    <script src="/assets/adaptive-captcha.js"></script>
    <script>
    (function () {
        var sendBtn = document.getElementById('sendCodeBtn');
        var emailInput = document.querySelector('[name=email]');
        
        sendBtn.addEventListener('click', function () {
            var email = emailInput.value.trim();
            if (!email || !email.includes('@')) {
                alert('请先输入有效的邮箱地址');
                return;
            }
            
            sendBtn.disabled = true;
            sendBtn.textContent = '发送中...';
            
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/forgot.php');
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
        });

        var btn = document.getElementById('submitBtn');
        if (!btn) return;

        btn.addEventListener('click', function () {
            var form = document.getElementById('forgotForm');
            var email = form.querySelector('[name=email]').value.trim();
            var email_code = form.querySelector('[name=email_code]').value.trim();
            var password = form.querySelector('[name=password]').value;
            var password_confirm = form.querySelector('[name=password_confirm]').value;

            if (!email || !email_code || !password) {
                var errEl = document.getElementById('formError');
                if (errEl) { errEl.textContent = '请填写所有必填项'; errEl.style.display = 'block'; }
                else alert('请填写所有必填项');
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
            if (email_code.length != 6) {
                var errEl = document.getElementById('formError');
                if (errEl) { errEl.textContent = '请输入6位验证码'; errEl.style.display = 'block'; }
                else alert('请输入6位验证码');
                return;
            }

            window._acInstance = AdaptiveCaptcha.verify({
                action: 'forgot',
                username: email,
                onSuccess: function (result) {
                    if (result.verify_token) {
                        form.querySelector('[name=verify_token]').value = result.verify_token;
                    }
                    if (result.captcha_data && result.captcha_data.captcha_id) {
                        form.querySelector('[name=captcha_id]').value = result.captcha_data.captcha_id;
                    }
                    form.submit();
                },
                onFail: function (err) {
                    console.log('验证失败', err);
                }
            });
        });
    })();
    </script>
</body>
</html>