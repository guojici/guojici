<?php
/**
 * 地区管理 API
 */
require_once __DIR__ . '/../config/helper.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'list':
        // 获取启用的地区列表
        $regions = Database::fetchAll("SELECT id, name, code, description, is_default FROM regions WHERE status = 'active' ORDER BY sort_order ASC, id ASC");
        json_response(['code' => 0, 'data' => $regions]);
        break;

    case 'select':
        // 用户选择地区（保存到session）
        if (!auth_check()) {
            json_response(['code' => 401, 'message' => '请先登录']);
        }
        $region_id = intval($_POST['region_id'] ?? 0);
        if ($region_id <= 0) {
            json_response(['code' => 1, 'message' => '参数错误']);
        }
        $region = Database::fetch("SELECT * FROM regions WHERE id = ? AND status = 'active'", [$region_id]);
        if (!$region) {
            json_response(['code' => 1, 'message' => '地区不存在或已禁用']);
        }
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['selected_region_id'] = $region_id;
        json_response([
            'code' => 0,
            'region' => [
                'id' => $region['id'],
                'name' => $region['name'],
                'code' => $region['code'],
            ],
        ]);
        break;

    case 'current':
        // 获取当前选中的地区
        $region = null;
        if (auth_check()) {
            if (session_status() === PHP_SESSION_NONE) session_start();
            $selected_id = intval($_SESSION['selected_region_id'] ?? 0);
            if ($selected_id > 0) {
                $region = Database::fetch("SELECT * FROM regions WHERE id = ? AND status = 'active'", [$selected_id]);
            }
        }
        if (!$region) {
            $region = Database::fetch("SELECT * FROM regions WHERE is_default = 1 AND status = 'active' LIMIT 1");
        }
        if (!$region) {
            $region = Database::fetch("SELECT * FROM regions WHERE status = 'active' ORDER BY sort_order ASC LIMIT 1");
        }
        json_response(['code' => 0, 'data' => $region]);
        break;

    default:
        json_response(['code' => 1, 'message' => '未知操作']);
}
