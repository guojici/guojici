<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 尝试多个可能的PHPMailer路径 —— config 在项目根目录的子目录中
$project_root = dirname(__DIR__);

$phpmailer_paths = [
    // 标准路径：PHPMailer-master/PHPMailer-master/src/
    $project_root . '/PHPMailer-master/PHPMailer-master/src/',
    // 备选路径：PHPMailer-master/src/
    $project_root . '/PHPMailer-master/src/',
    // PHPMailer/src/ (composer安装)
    $project_root . '/PHPMailer/src/',
    // vendor路径 (composer)
    $project_root . '/vendor/phpmailer/phpmailer/src/',
];

$phpmailer_dir = null;
foreach ($phpmailer_paths as $path) {
    if (file_exists($path . 'PHPMailer.php')) {
        $phpmailer_dir = $path;
        break;
    }
}

if ($phpmailer_dir === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode([
        'code' => 500, 
        'msg' => '邮件组件未安装：找不到PHPMailer文件',
        'debug' => [
            'project_root' => $project_root,
            'checked_paths' => $phpmailer_paths
        ]
    ], JSON_UNESCAPED_UNICODE));
}

require_once $phpmailer_dir . 'PHPMailer.php';
require_once $phpmailer_dir . 'SMTP.php';
require_once $phpmailer_dir . 'Exception.php';

class Mailer {
    private $host;
    private $port;
    private $username;
    private $password;
    private $from_email;
    private $from_name;
    private $secure;
    private $error = '';

    public function __construct() {
        $smtp_config = config('smtp');
        $db_config = db_get_settings('smtp');

        $this->host = !empty($db_config['host']) ? $db_config['host'] : ($smtp_config['host'] ?? 'smtp.163.com');
        $this->port = !empty($db_config['port']) ? intval($db_config['port']) : ($smtp_config['port'] ?? 465);
        $this->username = !empty($db_config['username']) ? $db_config['username'] : ($smtp_config['username'] ?? '');
        $this->password = !empty($db_config['password']) ? $db_config['password'] : ($smtp_config['password'] ?? '');
        $this->from_email = !empty($db_config['from_email']) ? $db_config['from_email'] : ($smtp_config['from_email'] ?? '');
        $this->from_name = !empty($db_config['from_name']) ? $db_config['from_name'] : ($smtp_config['from_name'] ?? 'guojici云');
        $this->secure = !empty($db_config['secure']) ? $db_config['secure'] : ($smtp_config['secure'] ?? 'ssl');
    }

    public function getError() {
        return $this->error;
    }

    public function isEnabled() {
        $enabled = config('smtp.enabled');
        $db_enabled = db_get_setting('smtp_enabled');
        if ($db_enabled !== null) {
            return $db_enabled == '1';
        }
        return !empty($enabled);
    }

    public function send($to, $subject, $body, $is_html = true) {
        if (!$this->isEnabled()) {
            $this->error = 'SMTP未启用';
            return false;
        }

        if (empty($this->username) || empty($this->password) || empty($this->from_email)) {
            $this->error = 'SMTP配置不完整';
            return false;
        }

        if (empty($to)) {
            $this->error = '收件人邮箱不能为空';
            return false;
        }

        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->SMTPDebug = 0;
            $mail->Timeout = 30;
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->SMTPAuth = true;
            $mail->Username = $this->username;
            $mail->Password = $this->password;

            if ($this->secure === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($this->secure === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->Port = $this->port;
            $mail->setFrom($this->from_email, $this->from_name);
            $mail->addAddress($to);
            $mail->addReplyTo($this->from_email, $this->from_name);
            $mail->isHTML($is_html);
            $mail->Subject = $subject;
            $mail->Body = $body;

            if (!$is_html) {
                $mail->AltBody = $body;
            }

            $mail->send();
            return true;
        } catch (Exception $e) {
            $this->error = '发送失败: ' . $mail->ErrorInfo;
            return false;
        }
    }

    public function sendCode($email, $code, $type = 'register') {
        $type_text = [
            'register' => '注册',
            'forgot' => '找回密码',
            'verify' => '邮箱验证'
        ];

        $subject = '【' . $this->from_name . '】' . ($type_text[$type] ?? '验证') . '验证码';
        $body = "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>验证码邮件</title>
</head>
<body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, sans-serif;'>
    <div style='max-width: 500px; margin: 0 auto; padding: 30px; background: #f8f9fa; border-radius: 10px;'>
        <div style='text-align: center; margin-bottom: 20px;'>
            <h1 style='color: #1a73e8; margin: 0;'>" . $this->from_name . "</h1>
            <p style='color: #666; font-size: 14px;'>专业的虚拟主机服务</p>
        </div>
        <div style='background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
            <p style='color: #333; line-height: 1.6;'>您好！</p>
            <p style='color: #333; line-height: 1.6;'>您正在进行" . ($type_text[$type] ?? '验证') . "操作，您的验证码是：</p>
            <div style='text-align: center; margin: 20px 0;'>
                <span style='display: inline-block; padding: 10px 30px; background: #1a73e8; color: #fff; font-size: 28px; font-weight: bold; letter-spacing: 4px; border-radius: 6px;'>$code</span>
            </div>
            <p style='color: #666; font-size: 12px; line-height: 1.6;'>此验证码有效期为5分钟，请及时使用。</p>
            <p style='color: #666; font-size: 12px; line-height: 1.6;'>如果不是您本人操作，请忽略此邮件。</p>
        </div>
        <div style='text-align: center; margin-top: 20px; color: #999; font-size: 12px;'>
            <p>&copy; " . date('Y') . " " . $this->from_name . " 版权所有</p>
        </div>
    </div>
</body>
</html>";

        return $this->send($email, $subject, $body, true);
    }

    public function testConnection() {
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => 'SMTP未启用'];
        }

        if (empty($this->username) || empty($this->password) || empty($this->from_email)) {
            return ['success' => false, 'message' => '配置不完整：请填写用户名、密码、发件人邮箱'];
        }

        // 测试邮件发送到指定邮箱
        $test_email = 'guojici@outlook.com';
        $test_code = generate_verification_code();
        $result = $this->sendCode($test_email, $test_code, 'verify');

        if ($result) {
            return ['success' => true, 'message' => 'SMTP连接成功，测试邮件已发送到 ' . $test_email];
        } else {
            return ['success' => false, 'message' => '发送失败：' . $this->getError()];
        }
    }
}

/**
 * 生成验证码函数
 * @param int $length 验证码长度，默认6位
 * @return string 生成的验证码
 */
function generate_verification_code($length = 6) {
    $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[mt_rand(0, $max)];
    }
    return $code;
}

/**
 * 生成纯数字验证码
 * @param int $length 验证码长度，默认6位
 * @return string 生成的验证码
 */
function generate_numeric_code($length = 6) {
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= mt_rand(0, 9);
    }
    return $code;
}

function send_email($to, $subject, $body, $is_html = true) {
    $mailer = new Mailer();
    return $mailer->send($to, $subject, $body, $is_html);
}

function send_email_code($email, $code, $type = 'register') {
    $mailer = new Mailer();
    return $mailer->sendCode($email, $code, $type);
}

function get_mailer() {
    return new Mailer();
}
