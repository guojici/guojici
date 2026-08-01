<?php
/**
 * Tianai Captcha API 端点
 * 生成:  POST /captcha/gen   → { code: 200, data: { id, type, backgroundImage, templateImage, ... } }
 * 验证:  POST /captcha/check → { code: 200, data: { token, captcha_id } }
 *                       失败 → { code: 4001, message: "..." }
 *
 * 路由（通过 script 参数 action）：
 *   /api/captcha_tianai.php?action=gen
 *   /api/captcha_tianai.php?action=check
 */

require_once __DIR__ . '/../config/helper.php';

// 统一JSON响应格式
header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', 0);
@error_reporting(0);

// 错误处理：任何PHP错误都返回JSON
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    echo json_encode([
        'code' => 500,
        'message' => '服务错误: ' . $errstr . ' (' . basename($errfile) . ':' . $errline . ')',
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

set_exception_handler(function ($e) {
    echo json_encode([
        'code' => 500,
        'message' => '异常: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// 检查GD库
if (!function_exists('imagecreatetruecolor')) {
    echo json_encode([
        'code' => 500,
        'message' => 'GD库未安装，无法生成验证码图片'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// ============ 生成验证码 ============
if ($action === 'gen') {
    require_once __DIR__ . '/../config/TianaiCaptcha.php';
    $captcha = new TianaiCaptcha();
    $result = $captcha->generate();

    // 扩展：将yOffset也返回给前端，用于垂直对齐
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============ 验证滑块位置 ============
if ($action === 'check') {
    // 读取JSON body（前端以application/json提交）
    $raw = file_get_contents('php://input');
    $body = $raw ? json_decode($raw, true) : null;

    // 兼容表单提交
    if ($body === null) {
        $body = [
            'id' => post('id', ''),
            'data' => json_decode(post('data', '{}'), true)
        ];
    }

    $id = isset($body['id']) ? $body['id'] : '';
    $data = isset($body['data']) ? $body['data'] : [];

    if (empty($id)) {
        echo json_encode([
            'code' => 4001,
            'message' => '缺少验证码ID'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    require_once __DIR__ . '/../config/TianaiCaptcha.php';
    $captcha = new TianaiCaptcha();
    $result = $captcha->verify($id, $data);

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'code' => 4001,
    'message' => '未知操作: ' . htmlspecialchars($action)
], JSON_UNESCAPED_UNICODE);
