<?php
/**
 * 自研滑块验证码类
 * 基于GD库生成带缺口的背景图+滑块图，验证时比对滑块位置
 */

class SliderCaptcha {

    private $width = 340;       // 画布宽度
    private $height = 200;      // 画布高度
    private $block_width = 50;  // 滑块宽度
    private $block_height = 50; // 滑块高度
    private $font_size = 16;    // 干扰字体大小
    private $session_prefix = 'slider_';

    public function __construct($options = []) {
        // 确保会话已启动 - 所有验证逻辑必须在后端通过SESSION完成
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        // 检测GD扩展
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
            throw new Exception('PHP GD扩展未安装，请联系管理员安装php-gd');
        }
        foreach (['width', 'height', 'block_width', 'block_height', 'font_size'] as $k) {
            if (isset($options[$k])) $this->$k = intval($options[$k]);
        }
    }

    /**
     * 生成验证码，返回验证所需的 token 和图片数据
     * @return array ['token' => string, 'bg_base64' => string, 'slider_base64' => string, 'x' => int, 'y' => int, 'secret_key' => string]
     */
    public function generate() {
        $x = rand(60, $this->width - $this->block_width - 30);
        $y = rand(20, $this->height - $this->block_height - 20);
        $token = $this->generateToken();
        $secretKey = $this->generateSecretKey();

        // 1. 创建"源图" - 完整的背景图（无缺口），一次随机图形/文字/点线/线条
        $source = $this->createSourceImage();

        // 2. 从源图复制出"背景图" - 在背景图上画出缺口（覆盖缺口区域）
        $bg = $this->createBgFromSource($source, $x, $y);

        // 3. 从源图截取缺口区域的内容，作为"滑块图" - 像素完全匹配
        $slider = $this->createSliderFromSource($source, $x, $y);

        imagedestroy($source);

        $this->saveTokenData($token, $x, $y, $secretKey);

        return [
            'token' => $token,
            'bg_base64' => $this->imageToBase64($bg, 'png'),
            'slider_base64' => $this->imageToBase64($slider, 'png'),
            'x' => $x,
            'y' => $y,
            'secret_key' => $secretKey,
        ];
    }

    /**
     * 验证滑块位置
     * @param string $token
     * @param int $input_x 用户提交的滑块X坐标
     * @param int $input_y 用户提交的滑块Y坐标（可选，用于多维度验证）
     * @return bool
     */
    /**
     * 验证滑块位置 + 拖动轨迹分析
     * @param string $token
     * @param int $input_x  最终X坐标
     * @param int $input_y  最终Y坐标（可选）
     * @param string|array $trajectory  拖动轨迹JSON字符串或数组：形如 "[{t:毫秒,x:像素,y:像素},...]"
     * @return bool
     */
    public function verify($token, $input_x, $input_y = null, $trajectory = null) {
        $data = $this->getTokenData($token);
        if (!$data) {
            return false;
        }

        // ---- 1. 位置校验 ----
        $correct_x = intval($data['x']);
        $correct_y = intval($data['y']);
        $input_x_int = intval($input_x);
        $diff_x = abs($input_x_int - $correct_x);
        $tolerance_x = 15;
        $tolerance_y = 15;

        $x_match = $diff_x <= $tolerance_x;
        $y_match = is_null($input_y) || abs(intval($input_y) - $correct_y) <= $tolerance_y;
        if (!$x_match || !$y_match) {
            $this->clearTokenData($token);
            return false;
        }

        // ---- 2. 轨迹校验（有抖动=真人，直线/零样本=机器人）----
        $traj_ok = $this->analyzeTrajectory($trajectory, $input_x_int, $diff_x);
        if (!$traj_ok) {
            $this->clearTokenData($token);
            return false;
        }

        // ---- 3. 验证通过：写入“已校验”标记（保留token 60秒，供表单提交时checkFinalize使用） ----
        $_SESSION[$this->session_prefix . $token] = [
            'x' => $correct_x,
            'y' => $correct_y,
            'verified' => 1,
            'verified_at' => time(),
            'expires' => time() + 60,
        ];
        return true;
    }

    /**
     * 最终确认（表单提交时调用，验证token是否已通过verify且未过期）
     */
    public function checkFinalize($token) {
        $key = $this->session_prefix . $token;
        if (!isset($_SESSION[$key])) return false;
        $d = $_SESSION[$key];
        if (empty($d['verified']) || empty($d['expires']) || time() > intval($d['expires'])) {
            unset($_SESSION[$key]);
            return false;
        }
        // 使用完成后清除，防止重放
        unset($_SESSION[$key]);
        return true;
    }

    /**
     * Tianai-Captcha 兼容: 解密 pointJson
     * @param string $token
     * @param string $pointJson  AES加密的坐标数据
     * @return array|false  ['x' => int, 'y' => int] 或 false
     */
    public function decryptPointJson($token, $pointJson) {
        $data = $this->getTokenData($token);
        if (!$data || empty($data['secret_key'])) {
            return false;
        }
        $secretKey = $data['secret_key'];

        // pointJson 是 AES-128-ECB-PKCS5Padding 加密后base64编码的字符串
        $encrypted = base64_decode($pointJson);
        if (!$encrypted) {
            return false;
        }

        $decrypted = openssl_decrypt($encrypted, 'AES-128-ECB', $secretKey, OPENSSL_RAW_DATA);
        if (!$decrypted) {
            return false;
        }

        // 解密后的格式: "x=123&y=45" 或 JSON
        parse_str($decrypted, $params);
        if (isset($params['x'])) {
            return ['x' => intval($params['x']), 'y' => intval($params['y'] ?? 0)];
        }

        // 尝试JSON格式
        $json = json_decode($decrypted, true);
        if (is_array($json) && isset($json['x'])) {
            return ['x' => intval($json['x']), 'y' => intval($json['y'] ?? 0)];
        }

        return false;
    }

    /**
     * Tianai-Captcha 兼容: 仅验证位置（不验证轨迹）
     * @param string $token
     * @param int $input_x
     * @param int $input_y
     * @return bool
     */
    public function verifyPosition($token, $input_x, $input_y = 0) {
        $data = $this->getTokenData($token);
        if (!$data) {
            return false;
        }

        $correct_x = intval($data['x']);
        $correct_y = intval($data['y']);
        $diff_x = abs(intval($input_x) - $correct_x);
        $diff_y = abs(intval($input_y) - $correct_y);
        $tolerance = 15;

        if ($diff_x > $tolerance || $diff_y > $tolerance) {
            $this->clearTokenData($token);
            return false;
        }

        // 验证通过：写入已校验标记，同时保存secret_key供后续使用
        $_SESSION[$this->session_prefix . $token] = [
            'x' => $correct_x,
            'y' => $correct_y,
            'secret_key' => $data['secret_key'] ?? '',
            'verified' => 1,
            'verified_at' => time(),
            'expires' => time() + 60,
        ];
        return true;
    }

    /**
     * Tianai-Captcha 兼容: 生成二次验证token
     * @param string $token  原始token
     * @return string  二次验证token
     */
    public function generateVerifyToken($token) {
        $verifyToken = 'verify_' . bin2hex(random_bytes(16));
        $data = $this->getTokenData($token);
        $_SESSION[$this->session_prefix . 'verify_' . $verifyToken] = [
            'original_token' => $token,
            'verified' => 1,
            'expires' => time() + 60,
        ];
        // 清除原始token数据（已使用）
        $this->clearTokenData($token);
        return $verifyToken;
    }

    /**
     * Tianai-Captcha 兼容: 检查二次验证token
     * @param string $verifyToken
     * @return bool
     */
    public function checkVerifyToken($verifyToken) {
        $key = $this->session_prefix . 'verify_' . $verifyToken;
        if (!isset($_SESSION[$key])) return false;
        $d = $_SESSION[$key];
        if (empty($d['verified']) || empty($d['expires']) || time() > intval($d['expires'])) {
            unset($_SESSION[$key]);
            return false;
        }
        unset($_SESSION[$key]);
        return true;
    }

    /**
     * 分析拖动轨迹 - 评分制（宽松模式，主要拦截明显机器行为）
     *   - 基本通过条件：时长>=300ms + 起点从0附近开始 + 有轨迹数据
     *   - 加分项：有抖动、有速度变化、有反向拖动、有非零位置偏差
     *   - 扣分项：完全无抖动、完全匀速
     *   - 一票否决：无轨迹/太快/匀速且无y抖动
     * @param string|array $trajectory
     * @param int $final_x 用户松开时的X
     * @param int $diff_x  |final_x - correct_x|
     * @return bool
     */
    private function analyzeTrajectory($trajectory, $final_x, $diff_x = 0) {
        // 兼容字符串/数组
        $arr = null;
        if (is_string($trajectory) && !empty($trajectory)) {
            $dec = json_decode($trajectory, true);
            if (is_array($dec)) $arr = $dec;
        } elseif (is_array($trajectory)) {
            $arr = $trajectory;
        }

        // === 基础校验 ===
        if (!is_array($arr) || count($arr) < 3) {
            return false; // 至少需要3个轨迹点
        }

        $n = count($arr);
        $first_t = intval($arr[0]['t'] ?? 0);
        $last_t = intval($arr[$n - 1]['t'] ?? 0);
        $total_ms = $last_t - $first_t;

        // 时长校验：人类通常需要50ms~20s（非常宽松）
        if ($total_ms < 50 || $total_ms > 20000) {
            return false;
        }

        // 起点校验：必须从左侧开始（0~50像素）
        $first_x = intval($arr[0]['x'] ?? 0);
        if ($first_x > 50) {
            return false;
        }

        // 终点校验：必须到达目标附近
        $last_x = intval($arr[$n - 1]['x'] ?? 0);
        if (abs($last_x - intval($final_x)) > 50) {
            return false;
        }

        // === 真人特征检测 ===
        $xs = [];
        $ys = [];
        foreach ($arr as $p) {
            $xs[] = intval($p['x'] ?? 0);
            $ys[] = intval($p['y'] ?? 0);
        }

        // 1. 位置偏差（人类很难精准对齐）
        $has_position_error = $diff_x >= 1 && $diff_x <= 30;

        // 2. Y轴抖动检测（人类手不稳）
        $y_range = count($ys) > 0 ? (max($ys) - min($ys)) : 0;
        $has_y_jitter = $y_range >= 0.5;

        // 3. 反向拖动（人类可能拖过头再回拉）
        $has_reverse = false;
        for ($i = 1; $i < $n; $i++) {
            if ($xs[$i] < $xs[$i-1]) {
                $has_reverse = true;
                break;
            }
        }

        // 4. 速度变化检测（人类不会匀速）
        $has_speed_change = false;
        if ($n >= 4) {
            $prev_dx = abs($xs[1] - $xs[0]);
            for ($i = 2; $i < $n; $i++) {
                $dx = abs($xs[$i] - $xs[$i-1]);
                if (abs($dx - $prev_dx) > 1) { // 步长有变化
                    $has_speed_change = true;
                    break;
                }
                $prev_dx = $dx;
            }
        }

        // === 判定结果 ===
        // 统计真人特征
        $human_features = 0;
        if ($has_position_error) $human_features++;    // 位置偏差
        if ($has_y_jitter) $human_features++;          // Y抖动
        if ($has_reverse) $human_features++;           // 反向拖动
        if ($has_speed_change) $human_features++;       // 速度变化
        if ($n >= 15) $human_features++;              // 轨迹点充足

        // 宽松策略：只要有1项真人特征就通过
        if ($human_features >= 1) {
            return true;
        }

        // 完美对齐但轨迹合理（可能是鼠标非常稳的用户）
        if ($diff_x === 0 && $total_ms >= 100 && $n >= 10) {
            return true;
        }

        return false;
    }

    // ===================== 私有方法 =====================

    private function generateToken() {
        return bin2hex(random_bytes(24));
    }

    private function generateSecretKey() {
        // 生成16字节(128位)的AES密钥
        return bin2hex(random_bytes(8));
    }

    private function saveTokenData($token, $x, $y, $secretKey = null) {
        $_SESSION[$this->session_prefix . $token] = [
            'x' => $x,
            'y' => $y,
            'secret_key' => $secretKey,
            'expires' => time() + 300, // 5分钟有效期
        ];
    }

    private function getTokenData($token) {
        $key = $this->session_prefix . $token;
        if (!isset($_SESSION[$key])) return null;
        $data = $_SESSION[$key];
        if (isset($data['expires']) && time() > $data['expires']) {
            unset($_SESSION[$key]);
            return null;
        }
        return $data;
    }

    private function clearTokenData($token) {
        unset($_SESSION[$this->session_prefix . $token]);
    }

    // 创建源图（无缺口） - 一次随机生成，像素共享给 bg 和 slider
    private function createSourceImage() {
        $img = imagecreatetruecolor($this->width, $this->height);
        $bg_color = imagecolorallocate($img, 235, 242, 255);
        imagefill($img, 0, 0, $bg_color);

        $this->addNoiseShapes($img);
        $this->addTextNoise($img);
        $this->addDotNoise($img, 120);
        $this->addLineNoise($img, 6);
        $img = $this->applyGaussianBlur($img);

        return $img;
    }

    // 从源图复制出背景图（画出缺口覆盖掉缺口区域）
    private function createBgFromSource($source, $hole_x, $hole_y) {
        $bg = imagecreatetruecolor($this->width, $this->height);
        imagecopy($bg, $source, 0, 0, 0, 0, $this->width, $this->height);

        // 用浅色填充缺口（覆盖源图内容）
        $border_color = imagecolorallocate($bg, 200, 210, 230);
        $inner_color = imagecolorallocate($bg, 220, 228, 245);
        imagefilledrectangle($bg, $hole_x, $hole_y, $hole_x + $this->block_width, $hole_y + $this->block_height, $inner_color);
        imagerectangle($bg, $hole_x, $hole_y, $hole_x + $this->block_width, $hole_y + $this->block_height, $border_color);

        return $bg;
    }

    // 从源图截取缺口区域内容作为滑块图（像素100%匹配缺口位置）
    private function createSliderFromSource($source, $hole_x, $hole_y) {
        // 滑块是 50x50 小图，内容就是源图中缺口位置的像素
        $slider = imagecreatetruecolor($this->block_width, $this->block_height);
        imagesavealpha($slider, true);

        // 像素级拷贝：从源图 (hole_x, hole_y) 位置截取 50x50 内容到滑块 (0, 0)
        imagecopy($slider, $source, 0, 0, $hole_x, $hole_y, $this->block_width, $this->block_height);

        // 加蓝色描边
        $border = imagecolorallocate($slider, 22, 119, 255);
        imagesetthickness($slider, 2);
        imagerectangle($slider, 0, 0, $this->block_width - 1, $this->block_height - 1, $border);

        return $slider;
    }

    private function drawJigsawHole($img, $x, $y, $border_color, $inner_color) {
        // 带凹凸块的异形缺口
        $bw = $this->block_width;
        $bh = $this->block_height;

        // 凹槽的拼图形状：上凸、右凸、下凹、左凹
        $cx = $x + $bw / 2;
        $cy = $y + $bh / 2;
        $r = min($bw, $bh) / 5;

        // 外边框
        $points = [];
        // 四个角
        $points[] = $x; $points[] = $y;
        $points[] = $x + $bw; $points[] = $y;
        // 右侧加凸起
        $pts_right = $this->jigsawTab($x + $bw, $y, $x + $bw, $y + $bh, $r, 'out', 'right');
        foreach ($pts_right as $p) $points[] = $p;
        $points[] = $x + $bw; $points[] = $y + $bh;
        $points[] = $x; $points[] = $y + $bh;
        // 左侧加凹槽
        $pts_left = $this->jigsawTab($x, $y + $bh, $x, $y, $r, 'in', 'left');
        foreach ($pts_left as $p) $points[] = $p;
        $points[] = $x; $points[] = $y;

        if (count($points) >= 6) {
            imagefilledpolygon($img, $points, count($points) / 2, $inner_color);
            imagesetthickness($img, 2);
            imagepolygon($img, $points, count($points) / 2, $border_color);
        } else {
            // fallback: 简单矩形
            imagefilledrectangle($img, $x + 2, $y + 2, $x + $bw - 2, $y + $bh - 2, $inner_color);
            imagerectangle($img, $x + 2, $y + 2, $x + $bw - 2, $y + $bh - 2, $border_color);
        }
    }

    private function drawJigsawBlock($img, $x, $y, $border_color, $inner_color) {
        $bw = $this->block_width;
        $bh = $this->block_height;
        $r = min($bw, $bh) / 5;

        $points = [];
        $points[] = $x; $points[] = $y;
        $points[] = $x + $bw; $points[] = $y;
        $pts_right = $this->jigsawTab($x + $bw, $y, $x + $bw, $y + $bh, $r, 'out', 'right');
        foreach ($pts_right as $p) $points[] = $p;
        $points[] = $x + $bw; $points[] = $y + $bh;
        $points[] = $x; $points[] = $y + $bh;
        $pts_left = $this->jigsawTab($x, $y + $bh, $x, $y, $r, 'in', 'left');
        foreach ($pts_left as $p) $points[] = $p;
        $points[] = $x; $points[] = $y;

        if (count($points) >= 6) {
            imagefilledpolygon($img, $points, count($points) / 2, $inner_color);
            imagesetthickness($img, 2);
            imagepolygon($img, $points, count($points) / 2, $border_color);
        } else {
            imagefilledrectangle($img, $x + 1, $y + 1, $x + $bw - 1, $y + $bh - 1, $inner_color);
            imagerectangle($img, $x + 1, $y + 1, $x + $bw - 1, $y + $bh - 1, $border_color);
        }
    }

    /**
     * 生成拼图凸凹形状的顶点坐标
     * @param float $x1, $y1 起点
     * @param float $x2, $y2 终点
     * @param float $r 弧度半径
     * @param string $type 'out'=凸 'in'=凹
     * @param string $side 'left'/'right'/'top'/'bottom'
     */
    private function jigsawTab($x1, $y1, $x2, $y2, $r, $type, $side) {
        $points = [];
        $len = sqrt(pow($x2 - $x1, 2) + pow($y2 - $y1, 2));
        if ($len < $r * 2) return [];

        $mid = $len / 2;
        $cp_x = $x1 + ($x2 - $x1) * ($mid / $len);
        $cp_y = $y1 + ($y2 - $y1) * ($mid / $len);

        // 法线方向
        $nx = -($y2 - $y1) / $len;
        $ny = ($x2 - $x1) / $len;
        $dir = ($type === 'out') ? 1 : -1;

        // 凸/凹的圆心
        $cx = $cp_x + $nx * $r * $dir;
        $cy = $cp_y + $ny * $r * $dir;

        // 圆弧的起始和结束角度
        $angle_start = atan2($y1 - $cy, $x1 - $cx);
        $angle_end = atan2($y2 - $cy, $x2 - $cx);

        // 采样弧上的点
        $steps = 8;
        if ($type === 'in') {
            // 凹：从起点到终点，弧度方向不变
            for ($i = 1; $i <= $steps; $i++) {
                $a = $angle_start + ($angle_end - $angle_start) * ($i / $steps);
                $points[] = $cx + $r * cos($a);
                $points[] = $cy + $r * sin($a);
            }
        } else {
            // 凸：从起点到终点，中间经过弧顶
            $a_mid = atan2(($y1 + $y2) / 2 - $cy, ($x1 + $x2) / 2 - $cx);
            // 先到弧顶，再从弧顶到终点
            $half_steps = intval($steps / 2);
            for ($i = 1; $i <= $half_steps; $i++) {
                $a = $angle_start + ($a_mid - $angle_start) * ($i / $half_steps);
                $points[] = $cx + $r * cos($a);
                $points[] = $cy + $r * sin($a);
            }
            for ($i = 1; $i <= $steps - $half_steps; $i++) {
                $a = $a_mid + ($angle_end - $a_mid) * ($i / ($steps - $half_steps));
                $points[] = $cx + $r * cos($a);
                $points[] = $cy + $r * sin($a);
            }
        }

        return $points;
    }

    private function addNoiseShapes($img) {
        $colors = [
            imagecolorallocate($img, 180, 200, 240),
            imagecolorallocate($img, 160, 190, 230),
            imagecolorallocate($img, 200, 210, 245),
            imagecolorallocate($img, 170, 195, 235),
        ];
        for ($i = 0; $i < 8; $i++) {
            $c = $colors[array_rand($colors)];
            $x = rand(0, $this->width);
            $y = rand(0, $this->height);
            $w = rand(20, 60);
            $h = rand(10, 40);
            imagefilledellipse($img, $x, $y, $w, $h, $c);
        }
    }

    private function addTextNoise($img) {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz2345678';
        for ($i = 0; $i < 4; $i++) {
            $x = rand(10, $this->width - 40);
            $y = rand(intval($this->height * 0.3), intval($this->height * 0.8));
            $char = $chars[rand(0, strlen($chars) - 1)];
            $color = imagecolorallocate($img, rand(150, 200), rand(160, 210), rand(180, 230));
            // 使用内置字体（无需TTF文件）
            imagestring($img, 5, $x, $y, $char, $color);
        }
    }

    private function addDotNoise($img, $count) {
        for ($i = 0; $i < $count; $i++) {
            $x = rand(0, $this->width);
            $y = rand(0, $this->height);
            $color = imagecolorallocate($img, rand(160, 220), rand(170, 225), rand(190, 240));
            imagesetpixel($img, $x, $y, $color);
        }
    }

    private function addLineNoise($img, $count) {
        for ($i = 0; $i < $count; $i++) {
            $x1 = rand(0, $this->width);
            $y1 = rand(0, $this->height);
            $x2 = rand(0, $this->width);
            $y2 = rand(0, $this->height);
            $color = imagecolorallocate($img, rand(170, 210), rand(180, 220), rand(200, 240));
            imagesetthickness($img, rand(1, 2));
            imageline($img, $x1, $y1, $x2, $y2, $color);
        }
    }

    private function applyGaussianBlur($img) {
        // 简化的模糊处理：多次采样平均
        $w = imagesx($img);
        $h = imagesy($img);
        $new = imagecreatetruecolor($w, $h);
        imagecopy($new, $img, 0, 0, 0, 0, $w, $h);

        for ($x = 2; $x < $w - 2; $x++) {
            for ($y = 2; $y < $h - 2; $y++) {
                $r = $g = $b = 0;
                $cnt = 0;
                for ($dx = -2; $dx <= 2; $dx++) {
                    for ($dy = -2; $dy <= 2; $dy++) {
                        $nx = $x + $dx;
                        $ny = $y + $dy;
                        if ($nx < 0 || $nx >= $w || $ny < 0 || $ny >= $h) continue;
                        $rgb = imagecolorat($img, $nx, $ny);
                        $c = imagecolorsforindex($img, $rgb);
                        $r += $c['red'];
                        $g += $c['green'];
                        $b += $c['blue'];
                        $cnt++;
                    }
                }
                $r = intval($r / $cnt);
                $g = intval($g / $cnt);
                $b = intval($b / $cnt);
                $color = imagecolorallocate($new, $r, $g, $b);
                imagesetpixel($new, $x, $y, $color);
            }
        }
        return $new;
    }

    private function imageToBase64($img, $format = 'png') {
        ob_start();
        if ($format === 'png') {
            imagepng($img);
        } elseif ($format === 'jpeg') {
            imagejpeg($img, null, 85);
        }
        $data = ob_get_clean();
        imagedestroy($img);
        return 'data:image/' . $format . ';base64,' . base64_encode($data);
    }
}
