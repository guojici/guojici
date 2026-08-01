<?php
/**
 * Tianai Captcha - PHP后端实现 (v1.5.5 协议)
 * 参照 cloud.tianai.captcha.springboot 官方文档
 *
 * 生成接口:
 *   POST /captcha/gen
 *   返回: { "code": 200, "data": { "id": "...", "type": "SLIDER",
 *           "backgroundImage": "data:image/...", "templateImage": "data:image/...",
 *           "bgImageWidth": 340, "bgImageHeight": 200,
 *           "templateImageWidth": 55, "templateImageHeight": 55, "yOffset": 42 } }
 *
 * 校验接口:
 *   POST /captcha/check
 *   请求: { "id": "...", "data": {
 *           "bgImageWidth": 340, "bgImageHeight": 200,
 *           "templateImageWidth": 55, "templateImageHeight": 55,
 *           "startTime": 1719012345678, "stopTime": 1719012345987,
 *           "trackList": [ { "x": 521, "y": 320, "t": 0, "type": "down" }, ... ] } }
 *   返回(成功): { "code": 200, "data": { "token": "..." } }
 *   返回(失败): { "code": 4001, "message": "..." }
 *
 * 业务表单二次校验: checkFinalize($id)，校验session中是否已有 verified=true 标记
 */

class TianaiCaptcha
{
    // 优化后的尺寸 —— 减小尺寸提高性能
    const BG_WIDTH = 300;
    const BG_HEIGHT = 180;
    const TPL_WIDTH = 55;
    const TPL_HEIGHT = 180;
    const TOLERANCE_PX = 8;  // 位置容差 ±8px（按比例缩小）
    const MIN_DURATION_MS = 100;
    const SESSION_PREFIX = 'tianai_cap_';

    // 风控配置
    const MAX_FAILED_ATTEMPTS = 5;      // 最大失败次数
    const BAN_DURATION_SECONDS = 1800;  // 封禁时长（30分钟）
    const FAILURE_PREFIX = 'tianai_fail_';
    const BAN_PREFIX = 'tianai_ban_';

    private $config = [];

    public function __construct() {
        // 加载配置
        $this->config = function_exists('db_get_settings') ? db_get_settings('captcha') : [];
    }

    // ======== 检查调试模式 ========
    private function isDebugMode() {
        return !empty($this->config['debug_mode']);
    }

    // ======== 检查是否禁用封禁 ========
    private function isBanDisabled() {
        return !empty($this->config['disable_ban']);
    }

    // ======== 检查是否禁用频率限制 ========
    private function isRateLimitDisabled() {
        return !empty($this->config['disable_rate_limit']);
    }

    // ======== 获取客户端IP ========
    private function getClientIP() {
        $ip = '';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        // 处理 IPv6 本地地址
        if ($ip === '::1') $ip = '127.0.0.1';
        return $ip;
    }

    // ======== 检查是否被封禁 ========
    private function isBanned() {
        // 如果禁用了封禁机制，直接返回false
        if ($this->isBanDisabled()) {
            return false;
        }

        $ip = $this->getClientIP();
        $banKey = self::BAN_PREFIX . $ip;

        if (!isset($_SESSION[$banKey])) {
            return false;
        }

        $banExpires = intval($_SESSION[$banKey]);
        if (time() > $banExpires) {
            // 封禁已过期，清除记录
            unset($_SESSION[$banKey]);
            unset($_SESSION[self::FAILURE_PREFIX . $ip]);
            return false;
        }

        return true;
    }

    // ======== 获取封禁剩余时间 ========
    private function getBanRemainingTime() {
        $ip = $this->getClientIP();
        $banKey = self::BAN_PREFIX . $ip;
        
        if (!isset($_SESSION[$banKey])) {
            return 0;
        }
        
        $remaining = intval($_SESSION[$banKey]) - time();
        return $remaining > 0 ? $remaining : 0;
    }

    // ======== 增加失败计数 ========
    private function incrementFailure() {
        // 如果禁用了频率限制，直接返回
        if ($this->isRateLimitDisabled()) {
            return ['banned' => false, 'disabled' => true];
        }

        // 如果禁用了封禁，只计数不封禁
        if ($this->isBanDisabled()) {
            $ip = $this->getClientIP();
            $failKey = self::FAILURE_PREFIX . $ip;
            $failCount = isset($_SESSION[$failKey]) ? intval($_SESSION[$failKey]) : 0;
            $failCount++;
            $_SESSION[$failKey] = $failCount;
            return ['banned' => false, 'count' => $failCount, 'max' => self::MAX_FAILED_ATTEMPTS, 'ban_disabled' => true];
        }

        $ip = $this->getClientIP();
        $failKey = self::FAILURE_PREFIX . $ip;
        $banKey = self::BAN_PREFIX . $ip;

        // 获取当前失败次数
        $failCount = isset($_SESSION[$failKey]) ? intval($_SESSION[$failKey]) : 0;
        $failCount++;

        if ($failCount >= self::MAX_FAILED_ATTEMPTS) {
            // 达到最大失败次数，封禁IP
            $_SESSION[$banKey] = time() + self::BAN_DURATION_SECONDS;
            unset($_SESSION[$failKey]);
            return ['banned' => true, 'remaining' => self::BAN_DURATION_SECONDS];
        }

        $_SESSION[$failKey] = $failCount;
        return ['banned' => false, 'count' => $failCount, 'max' => self::MAX_FAILED_ATTEMPTS];
    }

    // ======== 重置失败计数（验证成功时） ========
    private function resetFailure() {
        $ip = $this->getClientIP();
        unset($_SESSION[self::FAILURE_PREFIX . $ip]);
    }

    // ======== 生成 ========
    public function generate()
    {
        // 根据配置选择验证码类型
        $type = isset($this->config['captcha_type']) ? strtoupper($this->config['captcha_type']) : 'SLIDER';
        if ($type === 'ROTATE') {
            return $this->generateRotate();
        }
        return $this->generateSlider();
    }

    // ======== 生成滑块验证码 ========
    private function generateSlider()
    {
        $x = mt_rand(self::TPL_WIDTH + 5, self::BG_WIDTH - self::TPL_WIDTH - 5);
        $y = 0;

        $bg = imagecreatetruecolor(self::BG_WIDTH, self::BG_HEIGHT);

        $bgColors = [
            [240, 248, 255], [255, 250, 240], [245, 245, 255],
            [240, 255, 240], [250, 240, 255], [255, 248, 240]
        ];
        $c = $bgColors[mt_rand(0, count($bgColors) - 1)];
        $baseColor = imagecolorallocate($bg, $c[0], $c[1], $c[2]);
        imagefill($bg, 0, 0, $baseColor);

        for ($i = 0; $i < 4; $i++) {
            $color = imagecolorallocatealpha(
                $bg,
                mt_rand(120, 220), mt_rand(120, 220), mt_rand(120, 220),
                mt_rand(55, 95)
            );
            $sx = mt_rand(0, self::BG_WIDTH - 40);
            $sy = mt_rand(0, self::BG_HEIGHT - 20);
            $sw = mt_rand(20, 50);
            $sh = mt_rand(15, 30);
            imagefilledrectangle($bg, $sx, $sy, $sx + $sw, $sy + $sh, $color);
        }

        $tpl = imagecreatetruecolor(self::TPL_WIDTH, self::TPL_HEIGHT);
        imagesavealpha($tpl, true);
        $transparent = imagecolorallocatealpha($tpl, 0, 0, 0, 127);
        imagefill($tpl, 0, 0, $transparent);

        imagecopy($tpl, $bg, 0, 0, $x, $y, self::TPL_WIDTH, self::TPL_HEIGHT);

        $darkColor = imagecolorallocatealpha($bg, 40, 40, 40, 50);
        imagefilledrectangle($bg, $x, $y, $x + self::TPL_WIDTH - 1, $y + self::TPL_HEIGHT - 1, $darkColor);

        $borderColor = imagecolorallocate($tpl, 255, 255, 255);
        imagerectangle($tpl, 0, 0, self::TPL_WIDTH - 1, self::TPL_HEIGHT - 1, $borderColor);

        ob_start();
        imagejpeg($bg, null, 80);
        $bgBase64 = 'data:image/jpeg;base64,' . base64_encode(ob_get_clean());
        imagedestroy($bg);

        ob_start();
        imagepng($tpl);
        $tplBase64 = 'data:image/png;base64,' . base64_encode(ob_get_clean());
        imagedestroy($tpl);

        $id = 'tac_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 24);

        $_SESSION[self::SESSION_PREFIX . $id] = [
            'type' => 'SLIDER',
            'targetX' => $x,
            'targetY' => $y,
            'expires' => time() + 300,
            'verified' => false,
        ];

        return [
            'code' => 200,
            'data' => [
                'id' => $id,
                'type' => 'SLIDER',
                'captchaType' => 'SLIDER',
                'backgroundImage' => $bgBase64,
                'templateImage' => $tplBase64,
                'backgroundImageWidth' => self::BG_WIDTH,
                'backgroundImageHeight' => self::BG_HEIGHT,
                'templateImageWidth' => self::TPL_WIDTH,
                'templateImageHeight' => self::TPL_HEIGHT,
                'data' => [
                    'randomY' => $y,
                ],
            ],
        ];
    }

    // ======== 生成旋转验证码 ========
    private function generateRotate()
    {
        // 旋转验证码尺寸：正方形背景 + 圆形内圈
        $bgSize = 360;           // 背景图尺寸（正方形）
        $innerRadius = 80;       // 内圈半径
        $innerSize = $innerRadius * 2;  // 内圈直径 160

        // 随机旋转角度（30-330度）
        $targetAngle = mt_rand(30, 330);
        $center = $bgSize / 2;

        // 1) 创建背景图（带装饰图案的圆形）
        $bg = imagecreatetruecolor($bgSize, $bgSize);

        // 启用 alpha 混合
        imagealphablending($bg, true);
        imagesavealpha($bg, true);

        // 底色
        $bgColors = [
            [240, 248, 255], [255, 250, 240], [245, 245, 255],
            [240, 255, 240], [250, 240, 255], [255, 248, 240]
        ];
        $c = $bgColors[mt_rand(0, count($bgColors) - 1)];
        $baseColor = imagecolorallocate($bg, $c[0], $c[1], $c[2]);
        imagefill($bg, 0, 0, $baseColor);

        // 装饰：渐变斑块
        for ($i = 0; $i < 12; $i++) {
            $color = imagecolorallocatealpha(
                $bg,
                mt_rand(80, 200), mt_rand(80, 200), mt_rand(80, 200),
                mt_rand(40, 90)
            );
            $sx = mt_rand(0, $bgSize - 60);
            $sy = mt_rand(0, $bgSize - 60);
            $sw = mt_rand(25, 60);
            $sh = mt_rand(25, 60);
            $shape = mt_rand(0, 2);
            if ($shape === 0) {
                imagefilledrectangle($bg, $sx, $sy, $sx + $sw, $sy + $sh, $color);
            } elseif ($shape === 1) {
                imagefilledellipse($bg, $sx + (int)($sw / 2), $sy + (int)($sh / 2), $sw, $sh, $color);
            } else {
                imagefilledpolygon(
                    $bg,
                    [$sx, $sy + $sh, $sx + (int)($sw / 2), $sy, $sx + $sw, $sy + $sh],
                    3, $color
                );
            }
        }

        // 装饰：放射状线条（从中心向外）
        for ($i = 0; $i < 16; $i++) {
            $lineColor = imagecolorallocatealpha($bg, mt_rand(100, 200), mt_rand(100, 200), mt_rand(100, 200), 60);
            $angle = $i * (360 / 16) * M_PI / 180;
            $endX = $center + cos($angle) * ($bgSize / 2 - 10);
            $endY = $center + sin($angle) * ($bgSize / 2 - 10);
            imageline($bg, $center, $center, $endX, $endY, $lineColor);
        }

        // 装饰：环形图案
        for ($r = 30; $r <= 170; $r += 35) {
            $ringColor = imagecolorallocatealpha($bg, mt_rand(80, 180), mt_rand(80, 180), mt_rand(80, 180), 70);
            imagefilledellipse($bg, $center, $center, $r * 2, $r * 2, $ringColor);
        }

        // 装饰：文字点缀
        $symbols = ['A', '★', '●', '◆', '♥', '♠', '♣', 'B', 'C', '✖', '♪', '◇'];
        for ($i = 0; $i < 10; $i++) {
            $textColor = imagecolorallocate($bg, mt_rand(40, 150), mt_rand(40, 150), mt_rand(40, 150));
            $tx = mt_rand(15, $bgSize - 25);
            $ty = mt_rand(15, $bgSize - 25);
            imagestring($bg, 5, $tx, $ty, $symbols[mt_rand(0, count($symbols) - 1)], $textColor);
        }

        // 2) 提取内圈图像（从背景中心复制正方形区域，用 imagecopy 一次完成）
        $inner = imagecreatetruecolor($innerSize, $innerSize);
        imagesavealpha($inner, true);
        $transparent = imagecolorallocatealpha($inner, 0, 0, 0, 127);
        imagefill($inner, 0, 0, $transparent);
        imagecopy($inner, $bg, 0, 0, (int)($center - $innerRadius), (int)($center - $innerRadius), $innerSize, $innerSize);

        // 3) 在背景图上把内圈区域变暗（用 imagefilledellipse 一次绘制圆形暗色遮罩）
        $darkColor = imagecolorallocatealpha($bg, 20, 20, 20, 60);
        imagefilledellipse($bg, $center, $center, $innerSize, $innerSize, $darkColor);

        // 4) 给内圈画白色描边（用 imagefilledellipse 画稍大的白圈，再画暗色内圈覆盖）
        $borderColor = imagecolorallocate($bg, 255, 255, 255);
        imagefilledellipse($bg, $center, $center, $innerSize + 4, $innerSize + 4, $borderColor);
        imagefilledellipse($bg, $center, $center, $innerSize, $innerSize, $darkColor);

        // 5) 旋转内圈图像
        $rotated = imagerotate($inner, $targetAngle, $transparent);
        imagesavealpha($rotated, true);
        $rotatedWidth = imagesx($rotated);
        $rotatedHeight = imagesy($rotated);

        // 6) 输出 base64
        ob_start();
        imagejpeg($bg, null, 90);
        $bgBase64 = 'data:image/jpeg;base64,' . base64_encode(ob_get_clean());
        imagedestroy($bg);

        ob_start();
        imagepng($rotated);
        $tplBase64 = 'data:image/png;base64,' . base64_encode(ob_get_clean());
        imagedestroy($rotated);
        imagedestroy($inner);

        // 7) 生成 id 并保存到 session
        $id = 'tac_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 24);

        $_SESSION[self::SESSION_PREFIX . $id] = [
            'type' => 'ROTATE',
            'targetAngle' => $targetAngle,
            'expires' => time() + 300,
            'verified' => false,
        ];

        return [
            'code' => 200,
            'data' => [
                'id' => $id,
                'type' => 'ROTATE',
                'captchaType' => 'ROTATE',
                'backgroundImage' => $bgBase64,
                'templateImage' => $tplBase64,
                'backgroundImageWidth' => $bgSize,
                'backgroundImageHeight' => $bgSize,
                'templateImageWidth' => $rotatedWidth,
                'templateImageHeight' => $rotatedHeight,
                'data' => [
                    'randomAngle' => $targetAngle,
                ],
            ],
        ];
    }

    // ======== 校验滑块 ========
    /**
     * @param string $id
     * @param array $data
     * @return array { code, data?, message? }
     */
    public function verify($id, $data)
    {
        // ======== 风控检查：是否被封禁 ========
        if ($this->isBanned()) {
            $remaining = $this->getBanRemainingTime();
            $minutes = intval($remaining / 60);
            $seconds = $remaining % 60;
            return ['code' => 4001, 'message' => "操作过于频繁，请{$minutes}分{$seconds}秒后再试"];
        }

        $key = self::SESSION_PREFIX . $id;

        if (!isset($_SESSION[$key])) {
            return ['code' => 4001, 'message' => '验证码已失效'];
        }
        $saved = $_SESSION[$key];

        if (time() > $saved['expires']) {
            unset($_SESSION[$key]);
            return ['code' => 4001, 'message' => '验证码已过期'];
        }

        $captchaType = isset($saved['type']) ? $saved['type'] : 'SLIDER';

        // ======== 旋转验证码验证 ========
        if ($captchaType === 'ROTATE') {
            return $this->verifyRotate($id, $data, $saved);
        }

        // ======== 滑块验证码验证 ========
        // 1) 解析轨迹
        $trackList = isset($data['trackList']) ? $data['trackList'] : [];
        if (!is_array($trackList) || count($trackList) < 3) {
            unset($_SESSION[$key]);
            $result = $this->incrementFailure();
            if ($result['banned']) {
                return ['code' => 4001, 'message' => '操作过于频繁，请30分钟后再试'];
            }
            return ['code' => 4001, 'message' => '轨迹数据不完整'];
        }

        // 2) 计算 moveX = 最后一个轨迹点的x - 第一个轨迹点的x
        // 注意：tianai-captcha SDK 可能使用 x、pageX 或 clientX 字段
        $first = reset($trackList);
        $last = end($trackList);

        // 尝试多种字段名获取X坐标（优先级：x > pageX > clientX）
        $getX = function($point) {
            if (isset($point['x'])) return floatval($point['x']);
            if (isset($point['pageX'])) return floatval($point['pageX']);
            if (isset($point['clientX'])) return floatval($point['clientX']);
            return 0;
        };

        $firstX = $getX($first);
        $lastX = $getX($last);
        $rawMoveX = $lastX - $firstX;

        file_put_contents($debug_log, "firstX={$firstX}, lastX={$lastX}, rawMoveX={$rawMoveX}\n", FILE_APPEND);

        // 3) 计算缩放比例：前端可能对图片进行了缩放
        // 前端发送的 bgImageWidth 是实际显示宽度，后端生成时使用的是标准宽度（600）
        $frontendWidth = isset($data['bgImageWidth']) ? floatval($data['bgImageWidth']) : self::BG_WIDTH;
        $backendWidth = floatval(self::BG_WIDTH);
        $scaleRatio = $frontendWidth > 0 ? $backendWidth / $frontendWidth : 1.0;

        // 应用缩放比例
        $moveX = $rawMoveX * $scaleRatio;
        if ($moveX < 0) $moveX = 0;

        // 4) 位置是否在容差内
        $diff = abs($moveX - intval($saved['targetX']));

        if ($diff > self::TOLERANCE_PX) {
            unset($_SESSION[$key]);
            $result = $this->incrementFailure();
            if ($result['banned']) {
                return ['code' => 4001, 'message' => '操作过于频繁，请30分钟后再试'];
            }
            return ['code' => 4001, 'message' => '滑块位置未对齐'];
        }

        // 4) 时长校验（人机基本判断）
        $startTime = isset($data['startTime']) ? intval($data['startTime']) : 0;
        $stopTime = isset($data['stopTime']) ? intval($data['stopTime']) : 0;
        $duration = $stopTime - $startTime;
        if ($duration < self::MIN_DURATION_MS) {
            unset($_SESSION[$key]);
            $result = $this->incrementFailure();
            if ($result['banned']) {
                return ['code' => 4001, 'message' => '操作过于频繁，请30分钟后再试'];
            }
            return ['code' => 4001, 'message' => '操作过快'];
        }
        if ($duration > 20000) {
            unset($_SESSION[$key]);
            $result = $this->incrementFailure();
            if ($result['banned']) {
                return ['code' => 4001, 'message' => '操作过于频繁，请30分钟后再试'];
            }
            return ['code' => 4001, 'message' => '操作超时'];
        }

        // 5) 简单轨迹合理性：至少要有 move/up 等不同类型的轨迹点
        $hasMove = false;
        $hasUp = false;
        foreach ($trackList as $t) {
            $type = isset($t['type']) ? $t['type'] : '';
            if ($type === 'move') $hasMove = true;
            if ($type === 'up') $hasUp = true;
        }
        if (!$hasMove || !$hasUp) {
            unset($_SESSION[$key]);
            $result = $this->incrementFailure();
            if ($result['banned']) {
                return ['code' => 4001, 'message' => '操作过于频繁，请30分钟后再试'];
            }
            return ['code' => 4001, 'message' => '轨迹数据异常'];
        }

        // 6) 验证成功，重置失败计数
        $this->resetFailure();

        // 7) 标记为已验证（业务表单提交时再次调用 checkFinalize 做二次校验）
        $_SESSION[$key] = [
            'verified' => true,
            'verified_at' => time(),
            'expires' => time() + 60,
        ];

        $token = 'tk_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 32);

        return [
            'code' => 200,
            'data' => [
                'token' => $token,
                'captcha_id' => $id,
            ],
        ];
    }

    // ======== 业务表单二次校验 ========
    public function checkFinalize($id)
    {
        $key = self::SESSION_PREFIX . $id;
        if (!isset($_SESSION[$key])) return false;
        $d = $_SESSION[$key];
        if (empty($d['verified']) || empty($d['expires']) || time() > intval($d['expires'])) {
            unset($_SESSION[$key]);
            return false;
        }
        unset($_SESSION[$key]);
        return true;
    }

    // ======== 旋转验证码验证 ========
    private function verifyRotate($id, $data, $saved)
    {
        $key = self::SESSION_PREFIX . $id;

        // 风控检查
        if ($this->isBanned()) {
            $remaining = $this->getBanRemainingTime();
            $minutes = intval($remaining / 60);
            $seconds = $remaining % 60;
            return ['code' => 4001, 'message' => "操作过于频繁，请{$minutes}分{$seconds}秒后再试"];
        }

        $targetAngle = isset($saved['targetAngle']) ? floatval($saved['targetAngle']) : 0;

        // 1) 解析轨迹
        $trackList = isset($data['trackList']) ? $data['trackList'] : [];
        if (!is_array($trackList) || count($trackList) < 3) {
            unset($_SESSION[$key]);
            $result = $this->incrementFailure();
            if ($result['banned']) {
                return ['code' => 4001, 'message' => '操作过于频繁，请30分钟后再试'];
            }
            return ['code' => 4001, 'message' => '轨迹数据不完整'];
        }

        // 2) 计算 moveX
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

        // 3) 计算缩放比例
        $frontendWidth = isset($data['bgImageWidth']) ? floatval($data['bgImageWidth']) : 360;
        $backendWidth = 360; // 旋转验证码背景尺寸
        $scaleRatio = $frontendWidth > 0 ? $backendWidth / $frontendWidth : 1.0;

        // 应用缩放
        $scaledMoveX = $rawMoveX * $scaleRatio;

        // 4) 将移动距离转换为旋转角度
        // 拖动整个背景宽度 = 360度旋转
        $userAngle = ($scaledMoveX / $backendWidth) * 360;

        // 归一化角度到 0-360
        $userAngle = fmod($userAngle, 360);
        if ($userAngle < 0) $userAngle += 360;

        // 5) 计算角度差（考虑360度环绕）
        $diff = abs($userAngle - $targetAngle);
        if ($diff > 180) $diff = 360 - $diff;

        // 容差：15度
        $angleTolerance = 15;

        if ($diff > $angleTolerance) {
            unset($_SESSION[$key]);
            $result = $this->incrementFailure();
            if ($result['banned']) {
                return ['code' => 4001, 'message' => '操作过于频繁，请30分钟后再试'];
            }
            return ['code' => 4001, 'message' => '旋转角度未对齐'];
        }

        // 6) 时长校验
        $startTime = isset($data['startTime']) ? intval($data['startTime']) : 0;
        $stopTime = isset($data['stopTime']) ? intval($data['stopTime']) : 0;
        $duration = $stopTime - $startTime;
        if ($duration < self::MIN_DURATION_MS) {
            unset($_SESSION[$key]);
            $result = $this->incrementFailure();
            if ($result['banned']) {
                return ['code' => 4001, 'message' => '操作过于频繁，请30分钟后再试'];
            }
            return ['code' => 4001, 'message' => '操作过快'];
        }
        if ($duration > 20000) {
            unset($_SESSION[$key]);
            $result = $this->incrementFailure();
            if ($result['banned']) {
                return ['code' => 4001, 'message' => '操作过于频繁，请30分钟后再试'];
            }
            return ['code' => 4001, 'message' => '操作超时'];
        }

        // 7) 轨迹合理性
        $hasMove = false;
        $hasUp = false;
        foreach ($trackList as $t) {
            $type = isset($t['type']) ? $t['type'] : '';
            if ($type === 'move') $hasMove = true;
            if ($type === 'up') $hasUp = true;
        }
        if (!$hasMove || !$hasUp) {
            unset($_SESSION[$key]);
            $result = $this->incrementFailure();
            if ($result['banned']) {
                return ['code' => 4001, 'message' => '操作过于频繁，请30分钟后再试'];
            }
            return ['code' => 4001, 'message' => '轨迹数据异常'];
        }

        // 8) 验证成功
        $this->resetFailure();
        $_SESSION[$key] = [
            'verified' => true,
            'verified_at' => time(),
            'expires' => time() + 60,
        ];

        $token = 'tk_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 32);

        return [
            'code' => 200,
            'data' => [
                'token' => $token,
                'captcha_id' => $id,
            ],
        ];
    }
}
