<?php
/**
 * 验证码图片管理模块
 * 提供上传、查询、删除、启用/禁用验证码背景图片的功能
 *
 * 图片存储于 hym_license/uploads/captcha/
 * 数据存储于 captcha_images 表
 *
 * 文档: https://doc.captcha.tianai.cloud/
 */

// 验证码图片尺寸（tianai-captcha标准）
if (!defined('CAPTCHA_W')) define('CAPTCHA_W', 600);
if (!defined('CAPTCHA_H')) define('CAPTCHA_H', 360);
if (!defined('BLOCK_W')) define('BLOCK_W', 110);
if (!defined('BLOCK_H')) define('BLOCK_H', 360);

/**
 * 获取上传目录绝对路径
 */
function captcha_upload_dir() {
    return __DIR__ . '/uploads/captcha';
}

/**
 * 获取上传目录的 URL 前缀
 */
function captcha_upload_url() {
    return '/hym_license/uploads/captcha';
}

/**
 * 初始化验证码图片表
 */
function captcha_init_images_table() {
    static $initialized = false;
    if ($initialized) return;
    $initialized = true;
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS captcha_images (
            id INT PRIMARY KEY AUTO_INCREMENT,
            filename VARCHAR(255) NOT NULL,
            original_name VARCHAR(255),
            file_path VARCHAR(500) NOT NULL,
            file_url VARCHAR(500) NOT NULL,
            file_size INT DEFAULT 0,
            width INT DEFAULT 0,
            height INT DEFAULT 0,
            status TINYINT DEFAULT 1 COMMENT '1=启用 0=禁用',
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
    }
}

/**
 * 获取启用的验证码背景图片列表
 */
function captcha_get_active_images() {
    captcha_init_images_table();
    try {
        return Database::fetchAll(
            "SELECT * FROM captcha_images WHERE status = 1 ORDER BY sort_order ASC, id ASC"
        );
    } catch (Exception $e) {
        return [];
    }
}

/**
 * 获取所有验证码图片（含禁用）
 */
function captcha_get_all_images() {
    captcha_init_images_table();
    try {
        return Database::fetchAll(
            "SELECT * FROM captcha_images ORDER BY sort_order ASC, id DESC"
        );
    } catch (Exception $e) {
        return [];
    }
}

/**
 * 从上传的图片中随机选取一张作为背景
 * 返回 GD 资源 或 null（无可用图片时）
 */
function captcha_load_uploaded_bg() {
    $images = captcha_get_active_images();
    if (empty($images)) {
        return null;
    }

    $pick = $images[array_rand($images)];
    $file_path = $pick['file_path'];

    if (!file_exists($file_path)) {
        return null;
    }

    $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    $img = null;
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            $img = @imagecreatefromjpeg($file_path);
            break;
        case 'png':
            $img = @imagecreatefrompng($file_path);
            break;
        case 'gif':
            $img = @imagecreatefromgif($file_path);
            break;
        case 'webp':
            if (function_exists('imagecreatefromwebp')) {
                $img = @imagecreatefromwebp($file_path);
            }
            break;
    }

    if (!$img) {
        return null;
    }

    // 调整尺寸到标准大小 600x360
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w !== CAPTCHA_W || $h !== CAPTCHA_H) {
        $resized = imagecreatetruecolor(CAPTCHA_W, CAPTCHA_H);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, CAPTCHA_W, CAPTCHA_H, $w, $h);
        imagedestroy($img);
        $img = $resized;
    }

    return $img;
}

/**
 * 处理图片上传
 * @param array $file $_FILES 数组中的单个文件项
 * @return array ['success' => bool, 'message' => string, 'image' => array|null]
 */
function captcha_upload_image($file) {
    captcha_init_images_table();

    if (empty($file) || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'message' => '未接收到有效文件'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => '文件超过 php.ini 上传限制',
            UPLOAD_ERR_FORM_SIZE => '文件超过表单上传限制',
            UPLOAD_ERR_PARTIAL => '文件仅部分上传',
            UPLOAD_ERR_NO_FILE => '未上传文件',
            UPLOAD_ERR_NO_TMP_DIR => '缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '写入磁盘失败',
            UPLOAD_ERR_EXTENSION => 'PHP扩展阻止了上传',
        ];
        $msg = $errors[$file['error']] ?? '未知上传错误';
        return ['success' => false, 'message' => $msg];
    }

    // 文件大小限制 5MB
    $max_size = 5 * 1024 * 1024;
    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => '文件过大，最大允许 5MB'];
    }

    // 验证文件类型
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed_types)) {
        return ['success' => false, 'message' => '仅支持 JPG/PNG/GIF/WEBP 格式图片'];
    }

    // 扩展名映射
    $ext_map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    $ext = $ext_map[$mime];

    // 确保上传目录存在
    $upload_dir = captcha_upload_dir();
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0755, true);
    }
    if (!is_writable($upload_dir)) {
        @chmod($upload_dir, 0755);
    }
    if (!is_writable($upload_dir)) {
        return ['success' => false, 'message' => '上传目录不可写: ' . $upload_dir];
    }

    // 生成唯一文件名
    $filename = 'captcha_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $file_path = $upload_dir . '/' . $filename;
    $file_url = captcha_upload_url() . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $file_path)) {
        return ['success' => false, 'message' => '文件保存失败'];
    }

    // 获取图片尺寸
    $image_info = @getimagesize($file_path);
    $width = $image_info ? intval($image_info[0]) : 0;
    $height = $image_info ? intval($image_info[1]) : 0;

    // 建议尺寸提示（不强制）
    $size_warning = '';
    if ($width > 0 && $height > 0 && ($width !== CAPTCHA_W || $height !== CAPTCHA_H)) {
        $size_warning = "（原图尺寸 {$width}x{$height}，系统将自动缩放到 " . CAPTCHA_W . 'x' . CAPTCHA_H . '）';
    }

    // 写入数据库
    try {
        $sort_order = intval(Database::fetchValue("SELECT MAX(sort_order) FROM captcha_images") ?: 0) + 1;
        Database::insert('captcha_images', [
            'filename' => $filename,
            'original_name' => $file['name'] ?? $filename,
            'file_path' => $file_path,
            'file_url' => $file_url,
            'file_size' => intval($file['size']),
            'width' => $width,
            'height' => $height,
            'status' => 1,
            'sort_order' => $sort_order,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $image_id = Database::fetchValue("SELECT LAST_INSERT_ID()");
        $image = Database::fetch("SELECT * FROM captcha_images WHERE id = ?", [$image_id]);
    } catch (Exception $e) {
        @unlink($file_path);
        return ['success' => false, 'message' => '数据库写入失败: ' . $e->getMessage()];
    }

    return [
        'success' => true,
        'message' => '上传成功' . $size_warning,
        'image' => $image,
    ];
}

/**
 * 删除验证码图片
 */
function captcha_delete_image($id) {
    captcha_init_images_table();
    $id = intval($id);
    if ($id <= 0) {
        return ['success' => false, 'message' => '无效的图片ID'];
    }

    $image = Database::fetch("SELECT * FROM captcha_images WHERE id = ?", [$id]);
    if (!$image) {
        return ['success' => false, 'message' => '图片不存在'];
    }

    // 删除文件
    if (!empty($image['file_path']) && file_exists($image['file_path'])) {
        @unlink($image['file_path']);
    }

    // 删除数据库记录
    Database::delete('captcha_images', 'id = ?', [$id]);

    return ['success' => true, 'message' => '已删除'];
}

/**
 * 切换图片启用/禁用状态
 */
function captcha_toggle_image_status($id) {
    captcha_init_images_table();
    $id = intval($id);
    if ($id <= 0) {
        return ['success' => false, 'message' => '无效的图片ID'];
    }

    $image = Database::fetch("SELECT * FROM captcha_images WHERE id = ?", [$id]);
    if (!$image) {
        return ['success' => false, 'message' => '图片不存在'];
    }

    $new_status = $image['status'] ? 0 : 1;
    Database::update('captcha_images', ['status' => $new_status], 'id = ?', [$id]);

    return [
        'success' => true,
        'message' => $new_status ? '已启用' : '已禁用',
        'new_status' => $new_status,
    ];
}

/**
 * 更新图片排序
 */
function captcha_update_image_sort($id, $sort_order) {
    captcha_init_images_table();
    $id = intval($id);
    $sort_order = intval($sort_order);
    if ($id <= 0) {
        return ['success' => false, 'message' => '无效的图片ID'];
    }

    Database::update('captcha_images', ['sort_order' => $sort_order], 'id = ?', [$id]);
    return ['success' => true, 'message' => '排序已更新'];
}

/**
 * 获取图片统计信息
 */
function captcha_get_image_stats() {
    captcha_init_images_table();
    $stats = [
        'total' => 0,
        'active' => 0,
        'disabled' => 0,
        'total_size' => 0,
    ];
    try {
        $stats['total'] = intval(Database::fetchValue("SELECT COUNT(*) FROM captcha_images") ?: 0);
        $stats['active'] = intval(Database::fetchValue("SELECT COUNT(*) FROM captcha_images WHERE status = 1") ?: 0);
        $stats['disabled'] = $stats['total'] - $stats['active'];
        $stats['total_size'] = intval(Database::fetchValue("SELECT IFNULL(SUM(file_size), 0) FROM captcha_images") ?: 0);
    } catch (Exception $e) {
    }
    return $stats;
}

// 注意: generateCaptchaImage() 统一入口已移至 captcha_types.php
// 该文件仅提供 captcha_load_uploaded_bg() 等图片管理功能供 captcha_types.php 调用
