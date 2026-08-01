<?php
/**
 * 验证码 API
 *
 * 图片选择验证码：
 * GET  /api/captcha.php?action=generate_img  → 生成图片验证码
 * POST /api/captcha.php?action=verify_img    → 验证图片选择
 *
 * 滑块验证码（保留兼容）：
 * GET  /api/captcha.php?action=generate      → 生成滑块验证码
 * POST /api/captcha.php?action=verify       → 验证滑块位置
 *
 * Tianai-Captcha 兼容接口：
 * GET  /api/captcha.php?action=get&captchaType=SLIDER     → 生成滑块验证码(tianai格式)
 * POST /api/captcha.php?action=check                      → 校验滑块位置(tianai格式)
 * POST /api/captcha.php?action=verify                     → 二次验证(tianai格式)
 */

require_once __DIR__ . '/../config/helper.php';
@session_write_close();

// 只输出JSON，不输出任何其他内容
header('Content-Type: application/json; charset=utf-8');
header('X-Frame-Options: DENY');
// 禁用错误输出，防止PHP报错破坏JSON
@ini_set('display_errors', 0);
error_reporting(0);

// 设置错误处理器，如果发生错误则返回JSON错误
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo json_encode([
        'success' => false,
        'message' => '服务器错误: ' . $errstr,
        'code' => $errno,
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

set_exception_handler(function($e) {
    echo json_encode([
        'success' => false,
        'message' => '异常: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

$action = get('action', '');

// ===================== 图片验证码：生成 =====================
if ($action === 'generate_img') {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
        echo json_encode([
            'success' => false,
            'message' => 'GD库未安装，请联系管理员',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    require_once __DIR__ . '/../config/ImageCaptcha.php';
    $captcha = new ImageCaptcha();
    $result = $captcha->generate();

    echo json_encode([
        'success' => true,
        'token' => $result['token'],
        'images' => $result['images'],
        'question' => $result['question'],
        'target' => $result['target'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===================== 图片验证码：验证 =====================
if ($action === 'verify_img') {
    $token = post('token', '');
    $selected_json = post('selected', '[]');

    if (empty($token)) {
        echo json_encode(['success' => false, 'message' => '缺少验证令牌']);
        exit;
    }

    require_once __DIR__ . '/../config/ImageCaptcha.php';
    $captcha = new ImageCaptcha();
    $selected = json_decode($selected_json, true) ?: [];
    $ok = $captcha->verify($token, $selected);

    echo json_encode([
        'success' => true,
        'verified' => $ok,
        'message' => $ok ? '验证成功' : '验证失败，请重新选择',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===================== Tianai-Captcha 兼容: 生成 =====================
if ($action === 'get') {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
        echo json_encode([
            'repCode' => '500',
            'repData' => null,
            'repMsg' => 'GD库未安装，请联系管理员',
            'success' => false,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    require_once __DIR__ . '/../config/SliderCaptcha.php';
    $captcha = new SliderCaptcha([
        'width' => 340,
        'height' => 200,
        'block_width' => 50,
        'block_height' => 50,
    ]);

    $result = $captcha->generate();

    // Tianai格式返回
    echo json_encode([
        'repCode' => '0000',
        'repData' => [
            'captchaType' => 'SLIDER',
            'originalImageBase64' => $result['bg_base64'],
            'jigsawImageBase64' => $result['slider_base64'],
            'secretKey' => $result['secret_key'],
            'token' => $result['token'],
            'result' => false,
        ],
        'repMsg' => null,
        'success' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===================== Tianai-Captcha 兼容: 校验 =====================
if ($action === 'check') {
    $token = post('token', '');
    $pointJson = post('pointJson', '');
    $x = post('x', '');
    $y = post('y', '0');
    $captchaType = post('captchaType', 'SLIDER');

    if (empty($token)) {
        echo json_encode([
            'repCode' => '500',
            'repData' => null,
            'repMsg' => '缺少验证参数',
            'success' => false,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    require_once __DIR__ . '/../config/SliderCaptcha.php';
    $captcha = new SliderCaptcha();

    // 支持两种方式：pointJson（tianai标准）或明文x,y（兼容现有前端）
    if (!empty($pointJson)) {
        // 解密pointJson获取坐标
        $pointData = $captcha->decryptPointJson($token, $pointJson);
        if (!$pointData) {
            echo json_encode([
                'repCode' => '500',
                'repData' => null,
                'repMsg' => '验证数据解密失败',
                'success' => false,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $x = intval($pointData['x'] ?? 0);
        $y = intval($pointData['y'] ?? 0);
    } elseif ($x !== '') {
        $x = intval($x);
        $y = intval($y);
    } else {
        echo json_encode([
            'repCode' => '500',
            'repData' => null,
            'repMsg' => '缺少验证参数',
            'success' => false,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 验证位置（不需要轨迹，tianai格式）
    $ok = $captcha->verifyPosition($token, $x, $y);

    if ($ok) {
        // 生成二次验证token
        $verifyToken = $captcha->generateVerifyToken($token);
        echo json_encode([
            'repCode' => '0000',
            'repData' => [
                'result' => true,
                'token' => $verifyToken,
            ],
            'repMsg' => null,
            'success' => true,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'repCode' => '611',
            'repData' => null,
            'repMsg' => '验证失败',
            'success' => false,
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===================== Tianai-Captcha 兼容: 二次验证 =====================
if ($action === 'verify') {
    $token = post('token', '');

    if (empty($token)) {
        echo json_encode([
            'repCode' => '500',
            'repData' => null,
            'repMsg' => '缺少验证令牌',
            'success' => false,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    require_once __DIR__ . '/../config/SliderCaptcha.php';
    $captcha = new SliderCaptcha();
    $ok = $captcha->checkVerifyToken($token);

    if ($ok) {
        echo json_encode([
            'repCode' => '0000',
            'repData' => true,
            'repMsg' => null,
            'success' => true,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'repCode' => '611',
            'repData' => false,
            'repMsg' => '二次验证失败',
            'success' => false,
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===================== 滑块验证码：生成（兼容旧版） =====================
if ($action === 'generate') {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
        echo json_encode([
            'success' => false,
            'message' => 'GD库未安装，请联系管理员',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    require_once __DIR__ . '/../config/SliderCaptcha.php';
    $captcha = new SliderCaptcha([
        'width' => 340,
        'height' => 200,
        'block_width' => 50,
        'block_height' => 50,
    ]);

    $result = $captcha->generate();

    echo json_encode([
        'success' => true,
        'token' => $result['token'],
        'bg' => $result['bg_base64'],
        'slider' => $result['slider_base64'],
        'x' => $result['x'],
        'y' => $result['y'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===================== 滑块验证码：验证（兼容旧版） =====================
if ($action === 'verify') {
    $token = post('token', '');
    $x = intval(post('x', 0));
    $y = intval(post('y', 0));
    $trajectory = post('trajectory', '[]');

    if (empty($token)) {
        echo json_encode(['success' => false, 'message' => '缺少验证令牌']);
        exit;
    }

    require_once __DIR__ . '/../config/SliderCaptcha.php';
    $captcha = new SliderCaptcha();
    $ok = $captcha->verify($token, $x, $y, $trajectory);

    echo json_encode([
        'success' => true,
        'verified' => $ok,
        'message' => $ok ? '验证成功' : '验证失败，请重新拖动',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'message' => '未知操作']);
