<?php
/**
 * 验证码 API - 严格输出控制，确保返回纯净 JSON
 */

@ini_set('display_errors', '0');
error_reporting(0);

if (function_exists('ini_set')) {
    @ini_set('zlib.output_compression', 'Off');
    @ini_set('max_execution_time', 8);
    @ini_set('memory_limit', '64M');
}

// 关闭所有现有输出缓冲，重新开启一层干净的缓冲
while (ob_get_level() > 0) {
    @ob_end_clean();
}
ob_start();

$is_installed = file_exists(__DIR__ . '/config/.installed');
if (!$is_installed) {
    header('Location: /install.php');
    exit;
}

require_once __DIR__ . '/config/helper.php';
require_once __DIR__ . '/config/TianaiCaptcha.php';

@ini_set('display_errors', '0');
error_reporting(0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');
header('X-Frame-Options: DENY');

set_error_handler(function ($errno, $errstr) {
    while (ob_get_level()) @ob_clean();
    $json = json_encode(['code' => 500, 'message' => '服务器错误'], JSON_UNESCAPED_UNICODE);
    header('Content-Length: ' . strlen($json));
    echo $json;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    exit;
});
set_exception_handler(function ($e) {
    while (ob_get_level()) @ob_clean();
    $json = json_encode(['code' => 500, 'message' => '服务器异常'], JSON_UNESCAPED_UNICODE);
    header('Content-Length: ' . strlen($json));
    echo $json;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    exit;
});

function send_json($result) {
    while (ob_get_level()) {
        @ob_end_clean();
    }
    ob_start();
    $json = json_encode($result, JSON_UNESCAPED_UNICODE);
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Length: ' . strlen($json));
    echo $json;
    // 立即将响应发送给客户端，阻止后续任何输出
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
    }
    exit;
}

function getJsonInput() {
    $input = file_get_contents('php://input');
    return $input ? json_decode($input, true) : [];
}

function send_disabled() {
    send_json([
        'code' => 200,
        'data' => [
            'type' => 'DISABLED',
            'captchaType' => 'DISABLED',
            'id' => 'disabled_' . time(),
            'backgroundImage' => '',
            'templateImage' => '',
            'backgroundImageWidth' => 300,
            'backgroundImageHeight' => 180,
            'templateImageWidth' => 55,
            'templateImageHeight' => 180,
            'data' => new stdClass(),
        ],
    ]);
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

$captcha_type = 'SLIDER';
$valid_types = ['SLIDER', 'ROTATE', 'CONCAT', 'WORD_IMAGE_CLICK', 'DISABLED'];
if (function_exists('db_get_settings')) {
    $captcha_config = db_get_settings('captcha');
    if (is_array($captcha_config) && isset($captcha_config['captcha_type'])) {
        $t = strtoupper($captcha_config['captcha_type']);
        if (in_array($t, $valid_types, true)) {
            $captcha_type = $t;
        }
    }
}

if ($action === 'gen') {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
        send_disabled();
    }

    try {
        $start_time = microtime(true);
        $captcha = new TianaiCaptcha();
        $result = $captcha->generate();
        
        $elapsed = microtime(true) - $start_time;
        if ($elapsed > 3) {
            send_disabled();
        }
        
        if (!is_array($result)) {
            $result = [];
        }
        if (!isset($result['code']) || !is_numeric($result['code'])) {
            $result['code'] = 200;
        }
        if (!isset($result['data']) || !is_array($result['data'])) {
            $result['data'] = [];
        }
        
        $result['data']['type'] = $captcha_type;
        $result['data']['captchaType'] = $captcha_type;
        
        if (!isset($result['data']['id'])) {
            $result['data']['id'] = 'tac_' . substr(md5(uniqid('', true)), 0, 24);
        }
        if (!isset($result['data']['backgroundImage'])) {
            $result['data']['backgroundImage'] = '';
        }
        if (!isset($result['data']['templateImage'])) {
            $result['data']['templateImage'] = '';
        }
        if (!isset($result['data']['backgroundImageWidth'])) {
            $result['data']['backgroundImageWidth'] = 300;
        }
        if (!isset($result['data']['backgroundImageHeight'])) {
            $result['data']['backgroundImageHeight'] = 180;
        }
        if (!isset($result['data']['templateImageWidth'])) {
            $result['data']['templateImageWidth'] = 55;
        }
        if (!isset($result['data']['templateImageHeight'])) {
            $result['data']['templateImageHeight'] = 180;
        }
        if (!isset($result['data']['data']) || !is_array($result['data']['data'])) {
            $result['data']['data'] = new stdClass();
        }
        
        send_json($result);
    } catch (Throwable $e) {
        send_disabled();
    }
}

function verify_slider_captcha($id, $data) {
    $key = 'tianai_cap_' . $id;
    
    if (!isset($_SESSION[$key])) {
        return ['code' => 4001, 'message' => '验证码已失效'];
    }
    
    $saved = $_SESSION[$key];
    
    if (time() > intval($saved['expires'])) {
        unset($_SESSION[$key]);
        return ['code' => 4001, 'message' => '验证码已过期'];
    }
    
    $trackList = isset($data['trackList']) ? $data['trackList'] : [];
    if (!is_array($trackList) || count($trackList) < 3) {
        unset($_SESSION[$key]);
        return ['code' => 4001, 'message' => '轨迹数据不完整'];
    }
    
    $first = reset($trackList);
    $last = end($trackList);
    
    $getX = function($point) {
        if (isset($point['x'])) return floatval($point['x']);
        if (isset($point['pageX'])) return floatval($point['pageX']);
        if (isset($point['clientX'])) return floatval($point['clientX']);
        return 0;
    };
    
    $firstX = $getX($first);
    $lastX = $getX($last);
    $rawMoveX = $lastX - $firstX;
    
    $frontendWidth = isset($data['bgImageWidth']) ? floatval($data['bgImageWidth']) : 300;
    $backendWidth = 300;
    $scaleRatio = $frontendWidth > 0 ? $backendWidth / $frontendWidth : 1.0;
    
    $moveX = $rawMoveX * $scaleRatio;
    if ($moveX < 0) $moveX = 0;
    
    $diff = abs($moveX - intval($saved['targetX']));
    $tolerance = 8;
    
    if ($diff > $tolerance) {
        unset($_SESSION[$key]);
        return ['code' => 4001, 'message' => '滑块位置未对齐'];
    }
    
    $hasMove = false;
    $hasUp = false;
    foreach ($trackList as $t) {
        $type = isset($t['type']) ? $t['type'] : '';
        if ($type === 'move') $hasMove = true;
        if ($type === 'up') $hasUp = true;
    }
    if (!$hasMove || !$hasUp) {
        unset($_SESSION[$key]);
        return ['code' => 4001, 'message' => '轨迹数据异常'];
    }
    
    unset($_SESSION[$key]);
    
    return ['code' => 200, 'data' => []];
}

if ($action === 'check') {
    $input = getJsonInput();
    $id = isset($input['id']) ? $input['id'] : '';
    $data = isset($input['data']) ? $input['data'] : [];

    if (empty($id)) {
        send_json(['code' => 4001, 'message' => '缺少验证码ID']);
    }

    try {
        $result = verify_slider_captcha($id, $data);

        if (!is_array($result)) {
            $result = ['code' => 4001, 'message' => '验证失败'];
        }

        if ($result['code'] === 200) {
            $token = bin2hex(random_bytes(24));
            $_SESSION['tac_token_' . $token] = [
                'captchaId' => $id,
                'verified' => true,
                'expires' => time() + 120,
            ];
            if (!isset($result['data']) || !is_array($result['data'])) {
                $result['data'] = [];
            }
            $result['data']['token'] = $token;
        }

        send_json($result);
    } catch (Throwable $e) {
        send_json(['code' => 4001, 'message' => '验证失败']);
    }
}

if ($action === 'verify') {
    $input = getJsonInput();
    $token = isset($input['token']) ? $input['token'] : '';

    if (empty($token)) {
        send_json(['code' => 4001, 'message' => '缺少验证token']);
    }

    $key = 'tac_token_' . $token;
    if (!isset($_SESSION[$key])) {
        send_json(['code' => 4001, 'message' => '验证已过期']);
    }

    $d = $_SESSION[$key];
    if (empty($d['verified']) || time() > intval($d['expires'])) {
        unset($_SESSION[$key]);
        send_json(['code' => 4001, 'message' => '验证已过期']);
    }

    unset($_SESSION[$key]);
    send_json(['code' => 200, 'data' => ['valid' => true]]);
}

send_json(['code' => 4001, 'message' => '无效的操作']);
