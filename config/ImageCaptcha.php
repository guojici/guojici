<?php
/**
 * 图片选择验证码类
 * 生成带有多张图形的图片，用户需要点击包含特定图形的图片
 */

class ImageCaptcha {
    
    private $session_prefix = 'imgcap_';
    private $cell_size = 100;
    
    // 图形列表：key => [中文名称, 英文标签(作为图形显示)]
    private $shapes = [
        'star' => ['name' => '星星', 'label' => '★'],
        'heart' => ['name' => '爱心', 'label' => '♥'],
        'circle' => ['name' => '圆形', 'label' => '●'],
        'triangle' => ['name' => '三角', 'label' => '▲'],
        'square' => ['name' => '方块', 'label' => '■'],
        'diamond' => ['name' => '菱形', 'label' => '◆'],
        'flower' => ['name' => '花朵', 'label' => '✿'],
        'arrow' => ['name' => '箭头', 'label' => '➤'],
        'check' => ['name' => '对勾', 'label' => '✓'],
        'cross' => ['name' => '叉号', 'label' => '✗'],
        'sun' => ['name' => '太阳', 'label' => '☼'],
        'moon' => ['name' => '月亮', 'label' => '☾'],
        'cloud' => ['name' => '云朵', 'label' => '☁'],
        'phone' => ['name' => '电话', 'label' => '☎'],
        'home' => ['name' => '房屋', 'label' => '⌂'],
    ];
    
    public function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * 生成验证码
     */
    public function generate() {
        $token = $this->generateToken();
        
        // 随机选择目标图形
        $shape_keys = array_keys($this->shapes);
        $target_key = $shape_keys[mt_rand(0, count($shape_keys) - 1)];
        $target = $this->shapes[$target_key];
        
        // 生成9张图片，其中2-4张包含目标图形
        $images = [];
        $target_count = mt_rand(2, 4);
        $positions = range(0, 8);
        shuffle($positions);
        $target_positions = array_slice($positions, 0, $target_count);
        
        for ($i = 0; $i < 9; $i++) {
            $is_target = in_array($i, $target_positions);
            $images[] = [
                'id' => $i,
                'image' => $this->generateCellImage($target_key, $is_target),
            ];
        }
        
        // 保存到session
        $_SESSION[$this->session_prefix . $token] = [
            'target_positions' => $target_positions,
            'expires' => time() + 300,
        ];
        
        return [
            'token' => $token,
            'images' => $images,
            'question' => '请点击所有包含「' . $target['name'] . ' ' . $target['label'] . '」的图片',
        ];
    }
    
    /**
     * 验证用户选择
     */
    public function verify($token, $selected = []) {
        $key = $this->session_prefix . $token;
        if (!isset($_SESSION[$key])) return false;
        
        $data = $_SESSION[$key];
        if (time() > $data['expires']) {
            unset($_SESSION[$key]);
            return false;
        }
        
        $correct = $data['target_positions'];
        $selected_arr = is_array($selected) ? $selected : (json_decode($selected, true) ?: []);
        $selected_ids = array_map('intval', $selected_arr);
        
        $diff_correct = array_diff($correct, $selected_ids);
        $diff_selected = array_diff($selected_ids, $correct);
        
        // 允许少量错误
        if (count($diff_selected) <= 1 && count($diff_correct) <= 1) {
            $_SESSION[$this->session_prefix . $token] = [
                'verified' => 1,
                'verified_at' => time(),
                'expires' => time() + 60,
            ];
            return true;
        }
        
        return false;
    }
    
    /**
     * 最终确认
     */
    public function checkFinalize($token) {
        $key = $this->session_prefix . $token;
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
     * 生成单个格子的图片
     */
    private function generateCellImage($target_key, $is_target) {
        $size = $this->cell_size;
        $img = imagecreatetruecolor($size, $size);
        
        // 背景色
        $bg_colors = [
            [255, 255, 255],
            [240, 248, 255],
            [255, 250, 240],
            [245, 245, 245],
            [240, 255, 240],
            [255, 245, 245],
        ];
        $bg = $bg_colors[mt_rand(0, count($bg_colors) - 1)];
        $bg_color = imagecolorallocate($img, $bg[0], $bg[1], $bg[2]);
        imagefill($img, 0, 0, $bg_color);
        
        // 边框
        $border = imagecolorallocate($img, 200, 200, 200);
        imagerectangle($img, 0, 0, $size - 1, $size - 1, $border);
        
        // 主图形颜色
        $colors = [
            [220, 38, 38],    // 红
            [37, 99, 235],    // 蓝
            [22, 163, 74],    // 绿
            [234, 88, 12],    // 橙
            [168, 85, 247],   // 紫
            [234, 179, 8],    // 黄
            [14, 165, 233],   // 天蓝
            [236, 72, 153],   // 粉红
        ];
        $main_color = $colors[mt_rand(0, count($colors) - 1)];
        $text_color = imagecolorallocate($img, $main_color[0], $main_color[1], $main_color[2]);
        
        // 绘制主图形（目标或随机非目标）
        if ($is_target) {
            $label = $this->shapes[$target_key]['label'];
        } else {
            // 非目标图片：随机选一个其他图形
            $other_keys = [];
            foreach (array_keys($this->shapes) as $k) {
                if ($k !== $target_key) $other_keys[] = $k;
            }
            $random_key = $other_keys[mt_rand(0, count($other_keys) - 1)];
            $label = $this->shapes[$random_key]['label'];
        }
        
        // 主图形：大字号居中
        $center_x = 40;
        $center_y = 35;
        imagestring($img, 5, $center_x, $center_y, $label, $text_color);
        
        // 小干扰元素（其他符号）
        $干扰_labels = ['+', '×', '·', '○', '◇'];
        $干扰_color = imagecolorallocate($img, 180, 180, 180);
        $干扰_count = mt_rand(2, 4);
        for ($i = 0; $i < $干扰_count; $i++) {
            $dx = mt_rand(5, 85);
            $dy = mt_rand(5, 85);
            // 避免与主图形重叠
            if (abs($dx - $center_x - 10) < 25 && abs($dy - $center_y - 10) < 25) continue;
            imagestring($img, 2, $dx, $dy, $干扰_labels[mt_rand(0, count($干扰_labels) - 1)], $干扰_color);
        }
        
        // 装饰线条
        $line_color = imagecolorallocate($img, mt_rand(200, 230), mt_rand(200, 230), mt_rand(200, 230));
        for ($i = 0; $i < 2; $i++) {
            imageline($img, mt_rand(0, 100), mt_rand(0, 20), mt_rand(0, 100), mt_rand(80, 100), $line_color);
        }
        
        // 输出为base64
        ob_start();
        imagepng($img);
        $image_data = ob_get_clean();
        imagedestroy($img);
        
        return 'data:image/png;base64,' . base64_encode($image_data);
    }
    
    /**
     * 生成随机token
     */
    private function generateToken() {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(24));
        }
        // 兼容：用 mt_rand 生成随机token
        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $token = '';
        for ($i = 0; $i < 48; $i++) {
            $token .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $token;
    }
}
