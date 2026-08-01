<?php
/**
 * 验证码类型扩展模块
 * 支持 4 种验证码类型（名称需与前端 tac.min.js SDK 一致）：
 *   - SLIDER           滑块验证码（拖动滑块拼合图片）
 *   - ROTATE           旋转验证码（拖动滑块旋转图片至正确角度）
 *   - WORD_IMAGE_CLICK 文字点选验证码（按顺序点击图中文字）
 *   - CONCAT           滑动还原验证码（滑动拼图回原位）
 *
 * 文档: https://doc.captcha.tianai.cloud/
 */

if (!defined('CAPTCHA_W')) define('CAPTCHA_W', 600);
if (!defined('CAPTCHA_H')) define('CAPTCHA_H', 360);
if (!defined('BLOCK_W')) define('BLOCK_W', 110);
if (!defined('BLOCK_H')) define('BLOCK_H', 360);

// 支持的验证码类型（名称需与前端 tac.min.js SDK 一致）
if (!defined('CAPTCHA_TYPE_SLIDER')) define('CAPTCHA_TYPE_SLIDER', 'SLIDER');
if (!defined('CAPTCHA_TYPE_ROTATE')) define('CAPTCHA_TYPE_ROTATE', 'ROTATE');
if (!defined('CAPTCHA_TYPE_WORD_CLICK')) define('CAPTCHA_TYPE_WORD_CLICK', 'WORD_IMAGE_CLICK');
if (!defined('CAPTCHA_TYPE_SLIDER_RESTORE')) define('CAPTCHA_TYPE_SLIDER_RESTORE', 'CONCAT');

/**
 * 获取所有支持的验证码类型
 */
function captcha_get_supported_types() {
    return [
        CAPTCHA_TYPE_SLIDER         => '滑块验证码',
        CAPTCHA_TYPE_ROTATE         => '旋转验证码',
        CAPTCHA_TYPE_WORD_CLICK     => '文字点选验证码',
        CAPTCHA_TYPE_SLIDER_RESTORE => '滑动还原验证码',
    ];
}

/**
 * 旧类型名 → 新类型名 映射（向后兼容）
 * 前端 tac.min.js SDK 仅识别 WORD_IMAGE_CLICK / CONCAT，不识别旧的 WORD_CLICK / SLIDER_RESTORE
 */
function captcha_normalize_type($type) {
    $type = strtoupper(trim($type));
    $aliases = [
        'WORD_CLICK'     => CAPTCHA_TYPE_WORD_CLICK,      // → WORD_IMAGE_CLICK
        'SLIDER_RESTORE' => CAPTCHA_TYPE_SLIDER_RESTORE,  // → CONCAT
    ];
    return $aliases[$type] ?? $type;
}

/**
 * 获取配置的验证码类型（可随机）
 */
function captcha_get_active_type() {
    $config = function_exists('db_get_settings') ? db_get_settings('captcha') : [];
    // 配置存储的键名为 captcha_type（与 captcha_settings.php 保存时一致）
    $type = $config['captcha_type'] ?? ($config['type'] ?? 'SLIDER');

    // 支持随机模式：RANDOM 表示从所有类型中随机选择
    if (strtoupper($type) === 'RANDOM') {
        $types = array_keys(captcha_get_supported_types());
        $type = $types[array_rand($types)];
    }

    return captcha_normalize_type($type);
}

// ===================== 工具函数 =====================

/**
 * 生成随机背景图（无上传图片时使用）
 */
function captcha_make_random_bg($w, $h) {
    $source = imagecreatetruecolor($w, $h);
    $bgColor = imagecolorallocate($source, rand(230, 245), rand(230, 245), rand(230, 245));
    imagefill($source, 0, 0, $bgColor);

    $style = rand(0, 3);
    if ($style === 0) {
        for ($i = 0; $i < 5; $i++) {
            $c = imagecolorallocatealpha($source, rand(100, 200), rand(100, 200), rand(100, 200), rand(40, 80));
            imagefilledellipse($source, rand(0, $w), rand(0, $h), rand(80, 200), rand(80, 200), $c);
        }
    } elseif ($style === 1) {
        for ($i = 0; $i < 15; $i++) {
            $c = imagecolorallocatealpha($source, rand(100, 200), rand(100, 200), rand(100, 200), 60);
            imageline($source, rand(0, $w), rand(0, $h), rand(0, $w), rand(0, $h), $c);
        }
    } elseif ($style === 2) {
        for ($i = 0; $i < 80; $i++) {
            $c = imagecolorallocatealpha($source, rand(100, 200), rand(100, 200), rand(100, 200), rand(30, 80));
            imagefilledellipse($source, rand(0, $w), rand(0, $h), rand(5, 20), rand(5, 20), $c);
        }
    } else {
        for ($i = 0; $i < 10; $i++) {
            $c = imagecolorallocatealpha($source, rand(100, 200), rand(100, 200), rand(100, 200), 50);
            imagefilledrectangle($source, rand(0, $w), rand(0, $h), rand(0, $w), rand(0, $h), $c);
        }
    }
    return $source;
}

/**
 * 加载背景图（优先使用上传的图片）
 */
function captcha_load_bg() {
    if (function_exists('captcha_load_uploaded_bg')) {
        $img = captcha_load_uploaded_bg();
        if ($img) return $img;
    }
    return captcha_make_random_bg(CAPTCHA_W, CAPTCHA_H);
}

/**
 * GD 资源转 base64
 */
function captcha_img_to_base64($img, $type = 'jpeg', $quality = 85) {
    ob_start();
    if ($type === 'png') {
        imagesavealpha($img, true);
        imagepng($img);
    } else {
        imagejpeg($img, null, $quality);
    }
    $data = ob_get_clean();
    $mime = $type === 'png' ? 'image/png' : 'image/jpeg';
    return 'data:' . $mime . ';base64,' . base64_encode($data);
}

// ===================== 1. 滑块验证码 SLIDER =====================

/**
 * 生成滑块验证码
 */
function captcha_gen_slider() {
    $w = CAPTCHA_W;
    $h = CAPTCHA_H;
    $bw = BLOCK_W;
    $bh = BLOCK_H;

    $x = rand(150, $w - $bw - 30);
    $y = rand(10, $h - $bh - 10);

    $source = captcha_load_bg();

    // 背景图（带缺口）
    $bg = imagecreatetruecolor($w, $h);
    imagecopy($bg, $source, 0, 0, 0, 0, $w, $h);

    $maskColor = imagecolorallocatealpha($bg, 0, 0, 0, 60);
    imagefilledrectangle($bg, $x, $y, $x + $bw, $y + $bh, $maskColor);
    $borderColor = imagecolorallocate($bg, 255, 255, 255);
    imagerectangle($bg, $x, $y, $x + $bw, $y + $bh, $borderColor);

    // 滑块图
    $slider = imagecreatetruecolor($bw, $bh);
    $transparent = imagecolorallocatealpha($slider, 0, 0, 0, 127);
    imagefill($slider, 0, 0, $transparent);
    imagecopy($slider, $source, 0, 0, $x, $y, $bw, $bh);
    $sliderBorder = imagecolorallocate($slider, 100, 100, 100);
    imagerectangle($slider, 0, 0, $bw - 1, $bh - 1, $sliderBorder);

    $bgBase64 = captcha_img_to_base64($bg, 'jpeg');
    $sliderBase64 = captcha_img_to_base64($slider, 'png');

    imagedestroy($bg);
    imagedestroy($slider);
    imagedestroy($source);

    return [
        'type' => CAPTCHA_TYPE_SLIDER,
        'backgroundImage' => $bgBase64,
        'templateImage' => $sliderBase64,
        'backgroundImageWidth' => $w,
        'backgroundImageHeight' => $h,
        'templateImageWidth' => $bw,
        'templateImageHeight' => $bh,
        'data' => [
            'randomY' => $y,
        ],
        'answer' => ['x' => $x, 'y' => $y],
    ];
}

/**
 * 校验滑块验证码
 */
function captcha_check_slider($stored, $trackData) {
    $trackList = $trackData['trackList'] ?? [];

    $startX = 0;
    $endX = 0;
    $firstDown = null;
    $lastUp = null;

    foreach ($trackList as $track) {
        if (($track['type'] ?? '') === 'down' && !$firstDown) {
            $firstDown = $track;
            $startX = intval($track['x'] ?? 0);
        }
        if (($track['type'] ?? '') === 'up') {
            $lastUp = $track;
            $endX = intval($track['x'] ?? 0);
        }
    }

    if (!$lastUp) {
        foreach ($trackList as $track) {
            if (($track['type'] ?? '') === 'move') {
                $lastUp = $track;
                $endX = intval($track['x'] ?? 0);
            }
        }
    }

    $userMoveX = max(0, $endX - $startX);

    $bgImageWidth = intval($trackData['bgImageWidth'] ?? CAPTCHA_W);
    $scale = CAPTCHA_W / $bgImageWidth;
    $actualX = intval($userMoveX * $scale);

    $correctX = intval($stored['x']);
    $diff = abs($actualX - $correctX);
    $tolerance = 15;

    if ($diff > $tolerance) {
        return ['success' => false, 'message' => '验证失败，位置偏差' . $diff . 'px'];
    }

    return ['success' => true];
}

// ===================== 2. 旋转验证码 ROTATE =====================

/**
 * 生成旋转验证码
 *
 * 前端 SDK 期望（参照 StandardRotateImageCaptchaGenerator + rotate.js）：
 *   - backgroundImage: 原始背景图（带中心凹槽 overlay，暗示正确位置）
 *   - templateImage:   正方形图（边长 = 背景高度），从背景中心抠出，
 *                      【已被旋转了 degree 度】（即初始是歪的）
 *                      前端通过 CSS transform: rotate() 继续旋转此图
 *   - 用户拖动滑块 moveX → templateImage 旋转 (moveX / end * 360) 度（顺时针）
 *   - end = 300 - 63 + 5 = 242（滑轨可移动距离，前端硬编码）
 *   - 目标：把歪的图片旋转回"正"的角度（与背景凹槽对齐）
 *
 * 数学关系：
 *   - degree: 图片初始歪的角度（PHP imagerotate 逆时针旋转 degree 度）
 *   - 用户需要顺时针旋转 degree 度（或逆时针 360-degree）来还原
 *   - 但 CSS rotate(正值) 是顺时针，所以还原需要旋转 degree 度顺时针？
 *   - 不对：PHP imagerotate 逆时针 = CSS rotate(-degree) = CSS rotate(360-degree)
 *   - 初始状态 CSS rotate(0deg)，显示的是已逆时针旋转 degree 度的图片
 *   - 要还原到正向，需要 CSS rotate(degree deg) 顺时针抵消
 *   - 所以 moveX/end*360 = degree → moveX = degree * end / 360
 *
 *   Java 代码关系：
 *   - degree = 360 - randomX / (bgWidth/360)
 *   - randomX/bgWidth = (360-degree) / 360
 *   - 即 oriPercentage = (360-degree) / 360
 *
 *   但 moveX/end = degree/360 ≠ oriPercentage
 *   这说明我理解错了...
 *
 * 重新理解（结合 Java 校验器 doValidSliderCaptcha）：
 *   - Java 把 ROTATE 归类为"滑动类"，走同一套百分比校验
 *   - oriPercentage = randomX / backgroundImageWidth
 *   - userPercentage = (lastX - firstX) / bgImageWidth (= end)
 *   - 这说明 moveX/end = randomX/bgWidth 就是正确答案
 *
 * 因此正确关系是：
 *   - degree = randomX / (bgWidth/360) = 用户拖动 randomX 时图片旋转的角度
 *   - 初始状态：templateImage 是正的（未旋转）
 *   - 不对，那用户为什么要旋转？
 *
 * 最终理解（结合常见旋转验证码设计）：
 *   - backgroundImage 凹槽中有一个固定的参考标记（比如顶部三角形）
 *   - templateImage 是一张完整圆形图，已经被旋转了 randomAngle
 *   - templateImage 上也有一个标记（比如顶部指针）
 *   - 用户拖动滑块旋转 templateImage，让两个标记对齐
 *   - 需要旋转的角度 = randomAngle
 *   - moveX/end*360 = randomAngle
 *   - moveX/end = randomAngle/360 = randomX/bgWidth (其中 randomX = randomAngle/360 * bgWidth)
 *   - 所以 oriPercentage = randomX/bgWidth = randomAngle/360
 *
 * 简化实现：
 *   - templateImage 是正的圆形图 + 顶部红色指针（指针在 0 度位置）
 *   - backgroundImage 凹槽处有一个蓝色参考指针（在 randomAngle 位置）
 *   - 用户拖动滑块旋转 templateImage，让红色指针对齐蓝色指针
 *   - 需要旋转的角度 = randomAngle
 *   - randomX = randomAngle / 360 * bgWidth
 *   - oriPercentage = randomX / bgWidth = randomAngle / 360
 */
function captcha_gen_rotate() {
    $w = CAPTCHA_W;   // 600
    $h = CAPTCHA_H;   // 360

    // templateImage 是正方形，边长 = 背景高度
    $tplSize = $h;    // 360x360 正方形
    $radius = intval($tplSize / 2);

    // 加载背景图
    $source = captcha_load_bg();

    // ====== 生成随机角度 ======
    // 15~345 度（避免太接近 0 度或 360 度，太容易）
    // randomAngle = 用户需要顺时针旋转的角度（CSS rotate 角度）
    $randomAngle = mt_rand(15, 345);
    // randomX = 对应百分比的背景宽度（用于百分比校验）
    $randomX = intval($randomAngle / 360 * $w);

    // ====== 1. 生成 backgroundImage：原图 + 中心凹槽 + 参考指针 ======
    $bg = imagecreatetruecolor($w, $h);
    imagecopy($bg, $source, 0, 0, 0, 0, $w, $h);

    // 凹槽位置：水平居中
    $slotX = intval(($w - $tplSize) / 2);
    $slotY = 0;

    // 半透明圆形凹槽区域
    $slotBgColor = imagecolorallocatealpha($bg, 0, 0, 0, 40);
    imagefilledellipse($bg, $slotX + $radius, $radius, $radius * 2 - 10, $radius * 2 - 10, $slotBgColor);

    // 凹槽圆形边框
    $slotBorderColor = imagecolorallocate($bg, 255, 255, 255);
    imageellipse($bg, $slotX + $radius, $radius, $radius * 2 - 10, $radius * 2 - 10, $slotBorderColor);

    // 参考指针（蓝色）：固定在顶部（12 点方向），标记目标位置
    // templateImage 会被旋转 randomAngle 度（歪的），用户需要旋转让红色指针回到顶部对齐蓝色
    $refColor = imagecolorallocate($bg, 80, 150, 255);
    $refX = $slotX + $radius;
    $refY = 10;
    imagefilledellipse($bg, intval($refX), intval($refY), 12, 12, $refColor);
    imageline($bg, $slotX + $radius, $radius, intval($refX), intval($refY), $refColor);

    // ====== 2. 生成 templateImage：先画正方形+红色指针，再整体旋转 randomAngle 度 ======
    // 旋转后图片内容是歪的，用户需要 CSS rotate(randomAngle) 顺时针旋转来还原
    $template = imagecreatetruecolor($tplSize, $tplSize);
    imagesavealpha($template, true);
    $tplTransparent = imagecolorallocatealpha($template, 0, 0, 0, 127);
    imagefill($template, 0, 0, $tplTransparent);

    // 从源图抠出正方形区域（和背景凹槽位置一致）
    imagecopy($template, $source, 0, 0, $slotX, 0, $tplSize, $tplSize);

    // 画红色指针（顶部，旋转前画，会随 imagerotate 一起旋转）
    $ptrColor = imagecolorallocate($template, 230, 60, 60);
    $ptrX = $radius;
    $ptrY = 12;
    imagefilledpolygon($template, [
        $ptrX, $ptrY - 5,
        $ptrX - 9, $ptrY + 10,
        $ptrX + 9, $ptrY + 10,
    ], 3, $ptrColor);
    imagefilledellipse($template, $radius, $radius, 10, 10, $ptrColor);

    // 用 imagerotate 逆时针旋转 randomAngle 度
    // PHP imagerotate: angle > 0 = 逆时针
    // 用户需要 CSS rotate(randomAngle deg) 顺时针来还原
    $rotated = imagerotate($template, $randomAngle, $tplTransparent);
    imagedestroy($template);

    // 旋转后图片尺寸变大，从中心裁剪回 $tplSize × $tplSize
    $rotW = imagesx($rotated);
    $rotH = imagesy($rotated);
    $offsetX = intval(($rotW - $tplSize) / 2);
    $offsetY = intval(($rotH - $tplSize) / 2);

    $template = imagecreatetruecolor($tplSize, $tplSize);
    imagesavealpha($template, true);
    imagefill($template, 0, 0, $tplTransparent);
    imagecopy($template, $rotated, 0, 0, $offsetX, $offsetY, $tplSize, $tplSize);
    imagedestroy($rotated);

    // 圆形 mask 裁剪（圆外区域设为透明）
    for ($y = 0; $y < $tplSize; $y++) {
        for ($x = 0; $x < $tplSize; $x++) {
            $dx = $x - $radius;
            $dy = $y - $radius;
            if (sqrt($dx * $dx + $dy * $dy) > $radius - 3) {
                imagesetpixel($template, $x, $y, $tplTransparent);
            }
        }
    }

    // 外圈白色边框
    $tplBorderColor = imagecolorallocate($template, 255, 255, 255);
    imageellipse($template, $radius, $radius, ($radius - 3) * 2, ($radius - 3) * 2, $tplBorderColor);

    $bgBase64 = captcha_img_to_base64($bg, 'jpeg');
    $templateBase64 = captcha_img_to_base64($template, 'png');

    imagedestroy($bg);
    imagedestroy($source);
    imagedestroy($template);

    return [
        'type' => CAPTCHA_TYPE_ROTATE,
        'backgroundImage' => $bgBase64,
        'templateImage' => $templateBase64,
        'backgroundImageWidth' => $w,
        'backgroundImageHeight' => $h,
        'templateImageWidth' => $tplSize,
        'templateImageHeight' => $tplSize,
        'randomX' => $randomX,          // 后端校验用（百分比校验）
        'degree' => $randomAngle,       // 旋转角度（参考用）
        'tolerant' => 0.05,             // 容错 5%
        'data' => null,                 // ROTATE 前端不读 data 字段
        'answer' => [
            'randomX' => $randomX,
            'backgroundImageWidth' => $w,
        ],
    ];
}

/**
 * 校验旋转验证码（百分比校验，与前端 SDK 一致）
 *
 * 前端 ROTATE 提交时 bgImageWidth 被替换为 end=242（滑轨可移动距离）
 * oriPercentage = randomX / backgroundImageWidth
 * userPercentage = (lastX - firstX) / bgImageWidth
 */
function captcha_check_rotate($stored, $trackData) {
    $trackList = $trackData['trackList'] ?? [];

    // 提取首个 down 和末个 up/move 的 x 坐标
    $firstX = null;
    $lastX = null;
    foreach ($trackList as $track) {
        $type = $track['type'] ?? '';
        $x = floatval($track['x'] ?? 0);
        if ($type === 'down' && $firstX === null) {
            $firstX = $x;
        }
        if ($type === 'up' || $type === 'move') {
            $lastX = $x;  // 不断更新，最后一个就是末点
        }
    }

    if ($firstX === null || $lastX === null) {
        return ['success' => false, 'message' => '轨迹数据不完整'];
    }

    $moveX = $lastX - $firstX;
    if ($moveX < 0) $moveX = 0;

    $randomX = floatval($stored['randomX'] ?? $stored['answer']['randomX'] ?? 0);
    $bgWidth = floatval($stored['backgroundImageWidth'] ?? $stored['answer']['backgroundImageWidth'] ?? CAPTCHA_W);
    // ROTATE 前端将 bgImageWidth 替换为 end=242（滑轨可移动距离）
    $userEnd = floatval($trackData['bgImageWidth'] ?? 242);

    $oriPercentage = $randomX / $bgWidth;
    $userPercentage = $moveX / $userEnd;

    $diff = abs($userPercentage - $oriPercentage);
    $tolerant = floatval($stored['tolerant'] ?? 0.05);

    if ($diff > $tolerant) {
        return ['success' => false, 'message' => '旋转角度偏差' . round($diff * 360) . '度'];
    }

    return ['success' => true];
}

// ===================== 3. 图形点选验证码 WORD_IMAGE_CLICK =====================

/**
 * 生成图形点选验证码
 * 在背景图上随机放置 4 个几何图形，用户需按提示图顺序点击
 *
 * 前端 SDK 期望：
 *   - backgroundImage: 含所有可点击图形的大图（用户在此图上点击）
 *   - templateImage:   提示图（小图，显示需点击的图形顺序，显示在右上角）
 *   - 校验时 trackList 包含 type:"click" 的点击事件
 */

function captcha_draw_shape($img, $shape, $cx, $cy, $size, $color, $fill = true) {
    $s = intval($size / 2);
    switch ($shape) {
        case 'circle':
            if ($fill) {
                imagefilledellipse($img, $cx, $cy, $size, $size, $color);
            } else {
                imageellipse($img, $cx, $cy, $size, $size, $color);
            }
            break;
        case 'square':
            if ($fill) {
                imagefilledrectangle($img, $cx - $s, $cy - $s, $cx + $s, $cy + $s, $color);
            } else {
                imagerectangle($img, $cx - $s, $cy - $s, $cx + $s, $cy + $s, $color);
            }
            break;
        case 'triangle':
            $pts = [$cx, $cy - $s, $cx - $s, $cy + $s, $cx + $s, $cy + $s];
            if ($fill) {
                imagefilledpolygon($img, $pts, 3, $color);
            } else {
                imagepolygon($img, $pts, 3, $color);
            }
            break;
        case 'diamond':
            $pts = [$cx, $cy - $s, $cx + $s, $cy, $cx, $cy + $s, $cx - $s, $cy];
            if ($fill) {
                imagefilledpolygon($img, $pts, 4, $color);
            } else {
                imagepolygon($img, $pts, 4, $color);
            }
            break;
        case 'star':
            $pts = [];
            for ($i = 0; $i < 10; $i++) {
                $angle = $i * M_PI / 5 - M_PI / 2;
                $r = ($i % 2 === 0) ? $s : intval($s * 0.45);
                $pts[] = $cx + intval(cos($angle) * $r);
                $pts[] = $cy + intval(sin($angle) * $r);
            }
            if ($fill) {
                imagefilledpolygon($img, $pts, 10, $color);
            } else {
                imagepolygon($img, $pts, 10, $color);
            }
            break;
        case 'hexagon':
            $pts = [];
            for ($i = 0; $i < 6; $i++) {
                $angle = $i * M_PI / 3;
                $pts[] = $cx + intval(cos($angle) * $s);
                $pts[] = $cy + intval(sin($angle) * $s);
            }
            if ($fill) {
                imagefilledpolygon($img, $pts, 6, $color);
            } else {
                imagepolygon($img, $pts, 6, $color);
            }
            break;
        case 'cross':
            $t = intval($s * 0.35);
            if ($fill) {
                imagefilledrectangle($img, $cx - $t, $cy - $s, $cx + $t, $cy + $s, $color);
                imagefilledrectangle($img, $cx - $s, $cy - $t, $cx + $s, $cy + $t, $color);
            } else {
                imagerectangle($img, $cx - $t, $cy - $s, $cx + $t, $cy + $s, $color);
                imagerectangle($img, $cx - $s, $cy - $t, $cx + $s, $cy + $t, $color);
            }
            break;
        case 'heart':
            $w = $size;
            $h = $size;
            $steps = 60;
            $pts = [];
            for ($i = 0; $i <= $steps; $i++) {
                $t = $i / $steps * M_PI * 2;
                $x = 16 * pow(sin($t), 3);
                $y = -(13 * cos($t) - 5 * cos(2 * $t) - 2 * cos(3 * $t) - cos(4 * $t));
                $pts[] = $cx + intval($x / 17 * $w / 2);
                $pts[] = $cy + intval($y / 16 * $h / 2);
            }
            if ($fill) {
                imagefilledpolygon($img, $pts, $steps + 1, $color);
            } else {
                imagepolygon($img, $pts, $steps + 1, $color);
            }
            break;
    }
}

function captcha_gen_word_click() {
    $w = CAPTCHA_W;
    $h = CAPTCHA_H;

    $shapePool = ['circle', 'square', 'triangle', 'diamond', 'star', 'hexagon', 'cross', 'heart'];
    $shapeNames = [
        'circle' => '圆形', 'square' => '方块', 'triangle' => '三角',
        'diamond' => '菱形', 'star' => '星形', 'hexagon' => '六边形',
        'cross' => '十字', 'heart' => '心形',
    ];

    $colorPool = [
        [230, 60, 60],
        [60, 140, 230],
        [80, 180, 80],
        [240, 170, 50],
        [170, 80, 200],
        [50, 180, 190],
        [230, 100, 150],
        [255, 140, 50],
    ];

    $shapeCount = 4;
    $idx = array_rand($shapePool, $shapeCount);
    shuffle($idx);
    $selectedShapes = [];
    foreach ($idx as $k) {
        $selectedShapes[] = $shapePool[$k];
    }

    $source = captcha_load_bg();
    $bg = imagecreatetruecolor($w, $h);
    imagecopy($bg, $source, 0, 0, 0, 0, $w, $h);

    $shapeSize = 46;
    $charSize = $shapeSize + 10;
    $positions = [];
    $margin = 45;

    $attempts = 0;
    while (count($positions) < $shapeCount && $attempts < 100) {
        $px = rand($margin, $w - $charSize - $margin);
        $py = rand($margin, $h - $charSize - $margin);
        $overlap = false;
        foreach ($positions as $p) {
            if (abs($p['x'] - $px) < $charSize + 10 && abs($p['y'] - $py) < $charSize + 10) {
                $overlap = true;
                break;
            }
        }
        if (!$overlap) {
            $positions[] = ['x' => $px, 'y' => $py];
        }
        $attempts++;
    }

    if (count($positions) < $shapeCount) {
        $positions = [];
        $gridX = [$margin, intval($w / 3), intval($w * 2 / 3), $w - $charSize - $margin];
        $gridY = [$margin, intval($h / 2), $h - $charSize - $margin, intval($h / 3)];
        shuffle($gridX);
        shuffle($gridY);
        for ($i = 0; $i < $shapeCount; $i++) {
            $positions[] = [
                'x' => $gridX[$i],
                'y' => $gridY[$i],
            ];
        }
    }

    // ====== 干扰元素：随机曲线 ======
    $lineColors = [
        imagecolorallocatealpha($bg, 200, 100, 100, 80),
        imagecolorallocatealpha($bg, 100, 150, 220, 80),
        imagecolorallocatealpha($bg, 150, 200, 120, 80),
        imagecolorallocatealpha($bg, 220, 180, 80, 80),
    ];
    for ($i = 0; $i < 6; $i++) {
        $lc = $lineColors[array_rand($lineColors)];
        $x1 = rand(0, $w);
        $y1 = rand(0, $h);
        $x2 = rand(0, $w);
        $y2 = rand(0, $h);
        $cx1 = rand(0, $w);
        $cy1 = rand(0, $h);
        $cx2 = rand(0, $w);
        $cy2 = rand(0, $h);
        // 用多点折线模拟贝塞尔曲线
        $steps = 20;
        $prevX = $x1;
        $prevY = $y1;
        for ($s = 1; $s <= $steps; $s++) {
            $t = $s / $steps;
            $t2 = $t * $t;
            $t3 = $t2 * $t;
            $mt = 1 - $t;
            $mt2 = $mt * $mt;
            $mt3 = $mt2 * $mt;
            $px_line = $mt3 * $x1 + 3 * $mt2 * $t * $cx1 + 3 * $mt * $t2 * $cx2 + $t3 * $x2;
            $py_line = $mt3 * $y1 + 3 * $mt2 * $t * $cy1 + 3 * $mt * $t2 * $cy2 + $t3 * $y2;
            imageline($bg, intval($prevX), intval($prevY), intval($px_line), intval($py_line), $lc);
            $prevX = $px_line;
            $prevY = $py_line;
        }
    }

    // ====== 干扰元素：随机小圆点（噪点） ======
    $dotColor1 = imagecolorallocatealpha($bg, 80, 80, 80, 50);
    $dotColor2 = imagecolorallocatealpha($bg, 180, 80, 80, 50);
    $dotColor3 = imagecolorallocatealpha($bg, 80, 120, 180, 50);
    $dotColors = [$dotColor1, $dotColor2, $dotColor3];
    for ($i = 0; $i < 150; $i++) {
        $dc = $dotColors[array_rand($dotColors)];
        $dx = rand(0, $w - 1);
        $dy = rand(0, $h - 1);
        $dr = rand(1, 4);
        imagefilledellipse($bg, $dx, $dy, $dr, $dr, $dc);
    }

    // ====== 干扰元素：小的干扰图形（半透明，尺寸小） ======
    $distractorShapes = $shapePool;
    $distractorCount = 8;
    for ($i = 0; $i < $distractorCount; $i++) {
        $ds = $distractorShapes[array_rand($distractorShapes)];
        $dsSize = rand(15, 28);
        $dx = rand($margin, $w - $margin);
        $dy = rand($margin, $h - $margin);
        // 避免和正确图形太近
        $tooClose = false;
        foreach ($positions as $p) {
            if (abs($p['x'] + $charSize / 2 - $dx) < $charSize && abs($p['y'] + $charSize / 2 - $dy) < $charSize) {
                $tooClose = true;
                break;
            }
        }
        if ($tooClose) continue;
        $dcArr = [
            [200, 200, 200, 60],
            [180, 160, 200, 60],
            [160, 200, 180, 60],
            [220, 190, 160, 60],
            [190, 190, 220, 60],
        ];
        $dcRgb = $dcArr[array_rand($dcArr)];
        $distColor = imagecolorallocatealpha($bg, $dcRgb[0], $dcRgb[1], $dcRgb[2], $dcRgb[3]);
        captcha_draw_shape($bg, $ds, $dx, $dy, $dsSize, $distColor, true);
    }

    // ====== 绘制正确图形（白色描边，与干扰区分） ======
    $shapePositions = [];
    for ($i = 0; $i < $shapeCount; $i++) {
        $shape = $selectedShapes[$i];
        $px = $positions[$i]['x'];
        $py = $positions[$i]['y'];
        $cx = $px + intval($charSize / 2);
        $cy = $py + intval($charSize / 2);
        $num = $i + 1;

        $colorArr = $colorPool[$i % count($colorPool)];
        $fillColor = imagecolorallocate($bg, $colorArr[0], $colorArr[1], $colorArr[2]);
        $borderColor = imagecolorallocate($bg, 255, 255, 255);

        captcha_draw_shape($bg, $shape, $cx, $cy, $shapeSize, $fillColor, true);
        captcha_draw_shape($bg, $shape, $cx, $cy, $shapeSize, $borderColor, false);

        $shapePositions[] = [
            'shape' => $shape,
            'name'  => $shapeNames[$shape],
            'num'   => $num,
            'x'     => $px,
            'y'     => $py,
            'centerX' => $cx,
            'centerY' => $cy,
        ];
    }

    $clickOrderKeys = [0, 1, 2, 3];
    shuffle($clickOrderKeys);

    $clickOrder = [];
    $clickOrderNums = [];
    $clickOrderShapes = [];
    foreach ($clickOrderKeys as $k) {
        $clickOrder[] = $shapePositions[$k]['name'];
        $clickOrderNums[] = $shapePositions[$k]['num'];
        $clickOrderShapes[] = $shapePositions[$k]['shape'];
    }

    $tipShapeSize = 34;
    $tipPadding = 12;
    $tipShapeSpacing = 44;
    $tipWidth = $tipShapeSpacing * 4 + $tipPadding * 2;
    $tipHeight = 70;

    $tipImg = imagecreatetruecolor($tipWidth, $tipHeight);
    imagesavealpha($tipImg, true);
    $tipBgColor = imagecolorallocatealpha($tipImg, 255, 255, 255, 0);
    imagefill($tipImg, 0, 0, $tipBgColor);

    $tipArrowColor = imagecolorallocatealpha($tipImg, 180, 180, 180, 0);
    $tipNumColor = imagecolorallocatealpha($tipImg, 220, 50, 50, 0);

    for ($i = 0; $i < 4; $i++) {
        $cx = $tipPadding + $i * $tipShapeSpacing + intval($tipShapeSize / 2);
        $cy = 25;
        $shape = $clickOrderShapes[$i];

        $colorArr = $colorPool[array_search($shape, $selectedShapes) % count($colorPool)];
        $fillColor = imagecolorallocate($tipImg, $colorArr[0], $colorArr[1], $colorArr[2]);
        $borderColor = imagecolorallocate($tipImg, 80, 80, 80);

        captcha_draw_shape($tipImg, $shape, $cx, $cy, $tipShapeSize, $fillColor, true);
        captcha_draw_shape($tipImg, $shape, $cx, $cy, $tipShapeSize, $borderColor, false);

        $numY = 52;
        $tmpW = 10; $tmpH = 14;
        $tmp = imagecreatetruecolor($tmpW, $tmpH);
        $blk = imagecolorallocate($tmp, 0, 0, 0);
        imagefill($tmp, 0, 0, $blk);
        imagecolortransparent($tmp, $blk);
        imagestring($tmp, 5, 1, 0, strval($i + 1), $tipNumColor);
        imagecopyresized($tipImg, $tmp, $cx - 8, $numY, 0, 0, 16, 22, $tmpW, $tmpH);
        imagedestroy($tmp);

        if ($i < 3) {
            $arrowX = $tipPadding + $i * $tipShapeSpacing + $tipShapeSpacing - 4;
            $arrowY = 25;
            imageline($tipImg, $arrowX - 10, $arrowY, $arrowX, $arrowY, $tipArrowColor);
            imagefilledpolygon($tipImg, [
                $arrowX, $arrowY,
                $arrowX - 6, $arrowY - 5,
                $arrowX - 6, $arrowY + 5,
            ], 3, $tipArrowColor);
        }
    }

    $bgBase64 = captcha_img_to_base64($bg, 'jpeg');
    $tipBase64 = captcha_img_to_base64($tipImg, 'png');

    imagedestroy($bg);
    imagedestroy($source);
    imagedestroy($tipImg);

    return [
        'type' => CAPTCHA_TYPE_WORD_CLICK,
        'backgroundImage' => $bgBase64,
        'templateImage' => $tipBase64,
        'backgroundImageWidth' => $w,
        'backgroundImageHeight' => $h,
        'templateImageWidth' => $tipWidth,
        'templateImageHeight' => $tipHeight,
        'data' => [
            'uuid' => uniqid('img_click_', true),
        ],
        'answer' => [
            'clickOrder' => $clickOrder,
            'clickOrderNums' => $clickOrderNums,
            'positions' => $shapePositions,
            'checkClickCount' => 4,
        ],
    ];
}

/**
 * 校验文字点选验证码
 * 前端 trackList 包含 type:"click" 的点击事件 + type:"move" 的移动事件，需过滤
 */
function captcha_check_word_click($stored, $trackData) {
    // 从 trackList 中过滤出 type="click" 的点击事件
    $allTrack = $trackData['trackList'] ?? $trackData['clickList'] ?? [];
    $clickList = [];
    foreach ($allTrack as $track) {
        if (($track['type'] ?? '') === 'click') {
            $clickList[] = $track;
        }
    }

    // 如果过滤后为空，可能整个 trackList 都是点击事件（旧格式），直接使用
    if (empty($clickList) && !empty($allTrack)) {
        $clickList = $allTrack;
    }

    $expectedCount = intval($stored['checkClickCount'] ?? 4);
    if (count($clickList) < $expectedCount) {
        return ['success' => false, 'message' => '请完成所有点选（需点击' . $expectedCount . '次）'];
    }

    $expectedOrder = $stored['clickOrder'] ?? [];
    $expectedNums = $stored['clickOrderNums'] ?? [];
    $positions = $stored['positions'] ?? [];

    if (empty($expectedOrder) || empty($positions)) {
        return ['success' => false, 'message' => '验证码数据无效'];
    }

    // 推断缩放比例：前端 currentCaptchaData 可能因 load 事件未触发而未初始化，
    // 导致 bgImageWidth/bgImageHeight 为默认值（原始尺寸）。
    // 使用两个维度中更合理的那个来推断 scale（取较大的，更可能是真实渲染尺寸）
    $bgImageWidth = intval($trackData['bgImageWidth'] ?? CAPTCHA_W);
    $bgImageHeight = intval($trackData['bgImageHeight'] ?? CAPTCHA_H);
    $scaleX = ($bgImageWidth > 0 && $bgImageWidth < CAPTCHA_W) ? CAPTCHA_W / $bgImageWidth : 1;
    $scaleY = ($bgImageHeight > 0 && $bgImageHeight < CAPTCHA_H) ? CAPTCHA_H / $bgImageHeight : 1;
    $scale = max($scaleX, $scaleY);

    $tolerance = 50;

    for ($i = 0; $i < $expectedCount; $i++) {
        $click = $clickList[$i] ?? null;
        if (!$click) {
            return ['success' => false, 'message' => '点击次数不足'];
        }

        $clickX = intval(($click['x'] ?? 0) * $scale);
        $clickY = intval(($click['y'] ?? 0) * $scale);

        $expectedShapeName = $expectedOrder[$i] ?? '';
        $expectedPos = null;
        foreach ($positions as $p) {
            $pName = $p['name'] ?? ($p['char'] ?? '');
            if ($pName === $expectedShapeName) {
                $expectedPos = $p;
                break;
            }
        }

        if (!$expectedPos) {
            return ['success' => false, 'message' => '验证失败'];
        }

        $diffX = abs($clickX - $expectedPos['centerX']);
        $diffY = abs($clickY - $expectedPos['centerY']);

        if ($diffX > $tolerance || $diffY > $tolerance) {
            return ['success' => false, 'message' => '点击位置不准确（偏差 ' . $diffX . 'x' . $diffY . 'px）'];
        }
    }

    return ['success' => true];
}

// ===================== 4. 滑动还原验证码 CONCAT =====================

/**
 * 生成滑动还原验证码
 *
 * 前端 SDK 期望（参照 StandardConcatImageCaptchaGenerator）：
 *   - backgroundImage: 切分重组图
 *       1. 按 randomY 水平切成上/下两块
 *       2. 上半部分按 randomX 垂直切成左/右两块，左右交换拼接 → sliderImage
 *       3. sliderImage（上）+ 下半部分（下）上下拼接 = 最终 backgroundImage
 *   - templateImage: null（前端不使用，直接复用 backgroundImage 作为滑动 div 背景）
 *   - 用户拖动滑块 → background-position-x 移动 → 上半部分还原
 *   - 前端读取 data.data.randomY 计算上层 div 高度
 *   - 校验用百分比：oriPercentage = randomX / backgroundImageWidth
 */
function captcha_gen_slider_restore() {
    $w = CAPTCHA_W;   // 600
    $h = CAPTCHA_H;   // 360

    // 加载背景图
    $source = captcha_load_bg();

    // ====== 随机切割位置 ======
    // randomY: 水平切割线（上半部分高度），取 1/3 ~ 2/3 之间
    $randomY = mt_rand(intval($h / 3), intval($h * 2 / 3));
    // randomX: 垂直切割线（上半部分左右交换的距离），取 1/4 ~ 3/4 之间
    $randomX = mt_rand(intval($w / 4), intval($w * 3 / 4));

    // ====== 1. 切出上半部分和下半部分 ======
    $topHeight = $randomY;
    $bottomHeight = $h - $randomY;

    $topPart = imagecreatetruecolor($w, $topHeight);
    imagecopy($topPart, $source, 0, 0, 0, 0, $w, $topHeight);

    $bottomPart = imagecreatetruecolor($w, $bottomHeight);
    imagecopy($bottomPart, $source, 0, 0, 0, $topHeight, $w, $bottomHeight);

    // ====== 2. 上半部分按 randomX 左右交换 ======
    // 左半部分：x=0 ~ randomX，宽度 randomX
    // 右半部分：x=randomX ~ w，宽度 w-randomX
    // 交换后：右半部分在左，左半部分在右
    $leftWidth = $randomX;
    $rightWidth = $w - $randomX;

    $swappedTop = imagecreatetruecolor($w, $topHeight);
    // 右半部分放到左边
    imagecopy($swappedTop, $topPart, 0, 0, $randomX, 0, $rightWidth, $topHeight);
    // 左半部分放到右边
    imagecopy($swappedTop, $topPart, $rightWidth, 0, 0, 0, $leftWidth, $topHeight);

    // ====== 3. 上下拼接成最终 backgroundImage ======
    $bg = imagecreatetruecolor($w, $h);
    imagecopy($bg, $swappedTop, 0, 0, 0, 0, $w, $topHeight);
    imagecopy($bg, $bottomPart, 0, $topHeight, 0, 0, $w, $bottomHeight);

    // 在切割线处画一条细线，提示用户这里是拼接边界（可选）
    $lineColor = imagecolorallocatealpha($bg, 255, 255, 255, 60);
    imageline($bg, 0, $topHeight, $w, $topHeight, $lineColor);

    $bgBase64 = captcha_img_to_base64($bg, 'jpeg');

    imagedestroy($source);
    imagedestroy($topPart);
    imagedestroy($bottomPart);
    imagedestroy($swappedTop);
    imagedestroy($bg);

    return [
        'type' => CAPTCHA_TYPE_SLIDER_RESTORE,
        'backgroundImage' => $bgBase64,
        'templateImage' => null,           // CONCAT 前端不使用 templateImage
        'backgroundImageWidth' => $w,
        'backgroundImageHeight' => $h,
        'templateImageWidth' => null,
        'templateImageHeight' => null,
        'randomX' => $randomX,             // 后端校验用
        'tolerant' => 0.05,                // 容错 5%
        // 前端上层 div 高度 = (bgH - randomY) / bgH * 180
        // 要使上层高度 = 上半部分缩放高度，randomY 必须传"下半部分高度"
        // 这样上层只覆盖打乱的上半部分，拖动时不会影响下半部分
        'data' => [
            'randomY' => $h - $randomY,    // 下半部分高度（非切割线位置）
        ],
        'answer' => [
            'randomX' => $randomX,
            'backgroundImageWidth' => $w,
        ],
    ];
}

/**
 * 校验滑动还原验证码（百分比校验，与前端 SDK 一致）
 *
 * oriPercentage = randomX / backgroundImageWidth
 * userPercentage = (lastX - firstX) / bgImageWidth
 */
function captcha_check_slider_restore($stored, $trackData) {
    $trackList = $trackData['trackList'] ?? [];

    // 提取首个 down 和末个 up/move 的 x 坐标
    $firstX = null;
    $lastX = null;
    foreach ($trackList as $track) {
        $type = $track['type'] ?? '';
        $x = floatval($track['x'] ?? 0);
        if ($type === 'down' && $firstX === null) {
            $firstX = $x;
        }
        if ($type === 'up' || $type === 'move') {
            $lastX = $x;
        }
    }

    if ($firstX === null || $lastX === null) {
        return ['success' => false, 'message' => '轨迹数据不完整'];
    }

    $randomX = floatval($stored['randomX'] ?? $stored['answer']['randomX'] ?? 0);
    $bgWidth = floatval($stored['backgroundImageWidth'] ?? $stored['answer']['backgroundImageWidth'] ?? CAPTCHA_W);
    $userBgWidth = floatval($trackData['bgImageWidth'] ?? CAPTCHA_W);

    $oriPercentage = $randomX / $bgWidth;
    $userPercentage = ($lastX - $firstX) / $userBgWidth;

    $diff = abs($userPercentage - $oriPercentage);
    $tolerant = floatval($stored['tolerant'] ?? 0.05);

    if ($diff > $tolerant) {
        return ['success' => false, 'message' => '还原位置偏差' . round($diff * 100) . '%'];
    }

    return ['success' => true];
}

// ===================== 统一生成/校验入口 =====================

/**
 * 生成验证码（根据类型调度）
 */
function generateCaptchaImage($type = null) {
    if ($type === null) {
        $type = captcha_get_active_type();
    }
    // 归一化旧类型名（WORD_CLICK → WORD_IMAGE_CLICK, SLIDER_RESTORE → CONCAT）
    $type = captcha_normalize_type($type);

    switch ($type) {
        case CAPTCHA_TYPE_ROTATE:
            return captcha_gen_rotate();
        case CAPTCHA_TYPE_WORD_CLICK:
            return captcha_gen_word_click();
        case CAPTCHA_TYPE_SLIDER_RESTORE:
            return captcha_gen_slider_restore();
        case CAPTCHA_TYPE_SLIDER:
        default:
            return captcha_gen_slider();
    }
}

/**
 * 校验验证码（根据类型调度）
 */
function checkCaptchaByType($stored, $trackData) {
    // 归一化旧类型名（兼容已存入 SESSION 的旧类型）
    $type = captcha_normalize_type($stored['type'] ?? CAPTCHA_TYPE_SLIDER);

    switch ($type) {
        case CAPTCHA_TYPE_ROTATE:
            return captcha_check_rotate($stored, $trackData);
        case CAPTCHA_TYPE_WORD_CLICK:
            return captcha_check_word_click($stored, $trackData);
        case CAPTCHA_TYPE_SLIDER_RESTORE:
            return captcha_check_slider_restore($stored, $trackData);
        case CAPTCHA_TYPE_SLIDER:
        default:
            return captcha_check_slider($stored, $trackData);
    }
}
