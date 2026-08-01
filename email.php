<?php
/**
 * 邮件发送相关功能
 */

function send_verify_email($email, $type = 'register') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['verify_code'] = $code;
    $_SESSION['verify_email'] = $email;
    $_SESSION['verify_type'] = $type;
    $_SESSION['verify_time'] = time();

    $site_name = config('app.name', '云主机平台');

    if ($type === 'register') {
        $subject = "【{$site_name}】注册验证码";
        $body = "
            <div style='max-width:600px; margin:0 auto; padding:20px; font-family:Arial, sans-serif;'>
                <h2 style='color:#1677ff;'>注册验证码</h2>
                <p>尊敬的用户：</p>
                <p>您正在注册 {$site_name} 账户，验证码为：</p>
                <div style='background:#f5f7fa; padding:20px; text-align:center; border-radius:8px; margin:20px 0;'>
                    <span style='font-size:32px; font-weight:bold; color:#1677ff; letter-spacing:4px;'>{$code}</span>
                </div>
                <p>验证码有效期为5分钟，请勿泄露给他人。</p>
                <p>如非本人操作，请忽略此邮件。</p>
                <hr style='border:none; border-top:1px solid #e5e6eb; margin:20px 0;'>
                <p style='color:#86909c; font-size:12px;'>此邮件由系统自动发送，请勿直接回复。</p>
            </div>
        ";
    } else {
        $subject = "【{$site_name}】密码重置验证码";
        $body = "
            <div style='max-width:600px; margin:0 auto; padding:20px; font-family:Arial, sans-serif;'>
                <h2 style='color:#1677ff;'>密码重置验证码</h2>
                <p>尊敬的用户：</p>
                <p>您正在重置 {$site_name} 账户密码，验证码为：</p>
                <div style='background:#f5f7fa; padding:20px; text-align:center; border-radius:8px; margin:20px 0;'>
                    <span style='font-size:32px; font-weight:bold; color:#1677ff; letter-spacing:4px;'>{$code}</span>
                </div>
                <p>验证码有效期为5分钟，请勿泄露给他人。</p>
                <p>如非本人操作，请忽略此邮件。</p>
                <hr style='border:none; border-top:1px solid #e5e6eb; margin:20px 0;'>
                <p style='color:#86909c; font-size:12px;'>此邮件由系统自动发送，请勿直接回复。</p>
            </div>
        ";
    }

    try {
        require_once __DIR__ . '/config/Mailer.php';
        $mailer = new Mailer();
        $result = $mailer->send($email, $subject, $body);

        if ($result['success']) {
            return ['success' => true, 'message' => '验证码已发送'];
        } else {
            return ['success' => false, 'message' => $result['message'] ?? '邮件发送失败'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => '邮件发送异常: ' . $e->getMessage()];
    }
}
