<?php
require_once __DIR__ . '/../config/helper.php';

// ============ AJAX: KVM环境自检（放在最前面，避免任何HTML/警告污染JSON） ============
if (is_post() && post('action', '') === 'diag' && is_ajax()) {
    // 禁止错误输出到响应体
    @ini_set('display_errors', 0);
    @error_reporting(0);
    // 清空之前可能已有的任何输出（空格、换行、警告等）
    while (ob_get_level() > 0) ob_end_clean();
    require_admin();

    header('Content-Type: application/json; charset=utf-8');
    $kvm_cfg = config('kvm');
    if (empty($kvm_cfg) || empty($kvm_cfg['enabled'])) {
        echo json_encode(['success' => false, 'message' => 'KVM功能未启用，请在 config/app.php 中配置 kvm 节点'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    require_once __DIR__ . '/../config/KvmManager.php';
    try {
        $mgr = new KvmManager($kvm_cfg);
        $result = $mgr->runDiagnostics();
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'KVM管理器错误: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ============ AJAX: 获取ISO文件列表 ============
if (is_post() && post('action', '') === 'list_iso' && is_ajax()) {
    @ini_set('display_errors', 0);
    @error_reporting(0);
    while (ob_get_level() > 0) ob_end_clean();
    require_admin();
    header('Content-Type: application/json; charset=utf-8');

    $kvm_cfg = config('kvm');
    if (empty($kvm_cfg) || empty($kvm_cfg['enabled'])) {
        echo json_encode(['success' => false, 'message' => 'KVM功能未启用'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $iso_files = kvm_list_iso_files();
    echo json_encode(['success' => true, 'files' => $iso_files], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============ AJAX: ISO转qcow2（异步模式，QEMU引导安装） ============
if (is_post() && post('action', '') === 'convert_iso' && is_ajax()) {
    @ini_set('display_errors', 0);
    @error_reporting(0);
    while (ob_get_level() > 0) ob_end_clean();
    require_admin();
    header('Content-Type: application/json; charset=utf-8');

    $iso_path = trim(post('iso_path', ''));
    $output_path = trim(post('output_path', ''));
    $disk_size_gb = intval(post('disk_size_gb', 40));
    $memory_mb = intval(post('memory_mb', 2048));

    if (empty($iso_path)) {
        echo json_encode(['success' => false, 'message' => '请选择ISO文件'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($disk_size_gb < 10 || $disk_size_gb > 500) {
        echo json_encode(['success' => false, 'message' => '磁盘大小需在10-500GB之间'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($memory_mb < 1024 || $memory_mb > 8192) {
        $memory_mb = 2048;
    }

    // 创建异步任务
    $task_id = Database::insert('vm_tasks', [
        'host_id' => 0,
        'user_id' => 0,
        'task_type' => 'convert_iso',
        'task_data' => json_encode([
            'iso_path' => $iso_path,
            'output_path' => $output_path,
            'disk_size_gb' => $disk_size_gb,
            'memory_mb' => $memory_mb,
        ]),
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    // 启动后台转换进程
    $php_bin = '';
    $php_paths = [
        '/www/server/php/83/bin/php',
        '/www/server/php/74/bin/php',
        '/usr/bin/php',
        '/usr/local/bin/php',
    ];
    foreach ($php_paths as $p) {
        if (file_exists($p) && is_executable($p) && strpos($p, 'php-fpm') === false) {
            $php_bin = $p;
            break;
        }
    }

    if ($php_bin) {
        $worker_script = __DIR__ . '/iso_convert_worker.php';
        $cmd = sprintf(
            '%s %s %d > /dev/null 2>&1 &',
            $php_bin,
            escapeshellarg($worker_script),
            $task_id
        );
        @exec($cmd);
    }

    echo json_encode([
        'success' => true,
        'message' => 'QEMU安装环境启动中，请稍候...',
        'task_id' => $task_id,
        'async' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============ AJAX: 查询转换任务状态 ============
if (is_post() && post('action', '') === 'check_convert_status' && is_ajax()) {
    @ini_set('display_errors', 0);
    @error_reporting(0);
    while (ob_get_level() > 0) ob_end_clean();
    require_admin();
    header('Content-Type: application/json; charset=utf-8');

    $task_id = intval(post('task_id', 0));
    $output_path_param = trim(post('output_path', ''));

    // 当task_id为0但提供了output_path时，按output_path查询实时状态（手动刷新场景）
    if ($task_id <= 0 && !empty($output_path_param)) {
        $live_status = kvm_check_iso_convert_status($output_path_param);
        echo json_encode([
            'success' => true,
            'status' => 'completed',
            'live' => $live_status,
            'result' => ['output_path' => $output_path_param],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($task_id <= 0) {
        echo json_encode(['success' => false, 'message' => '任务ID无效'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $task = Database::fetch("SELECT * FROM vm_tasks WHERE id = ?", [$task_id]);
    if (!$task) {
        echo json_encode(['success' => false, 'message' => '任务不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result_data = null;
    if ($task['status'] === 'completed' && !empty($task['result_msg'])) {
        $result_data = json_decode($task['result_msg'], true);
    }

    // 如果任务已完成（QEMU已启动），额外查询实时安装状态
    $live_status = null;
    if ($task['status'] === 'completed' && $result_data && !empty($result_data['output_path'])) {
        $live_status = kvm_check_iso_convert_status($result_data['output_path']);
    }

    echo json_encode([
        'success' => true,
        'status' => $task['status'],
        'error_msg' => $task['error_msg'] ?? '',
        'result' => $result_data,
        'live' => $live_status,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============ AJAX: 停止ISO转qcow2安装进程 ============
if (is_post() && post('action', '') === 'stop_convert' && is_ajax()) {
    @ini_set('display_errors', 0);
    @error_reporting(0);
    while (ob_get_level() > 0) ob_end_clean();
    require_admin();
    header('Content-Type: application/json; charset=utf-8');

    $output_path = trim(post('output_path', ''));
    if (empty($output_path)) {
        echo json_encode(['success' => false, 'message' => '输出路径为空'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ok = kvm_stop_iso_convert($output_path);
    sec_admin_audit('stop_iso_convert', 'vm_images', 0, ['output_path' => $output_path]);
    echo json_encode([
        'success' => true,
        'message' => $ok ? '安装进程已停止' : '停止操作已完成（进程可能已退出）',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============ AJAX: 上传镜像图标 ============
if (is_post() && post('action', '') === 'upload_image' && is_ajax()) {
    @ini_set('display_errors', 0);
    @error_reporting(0);
    while (ob_get_level() > 0) ob_end_clean();
    require_admin();

    header('Content-Type: application/json; charset=utf-8');
    
    if (!isset($_FILES['image'])) {
        echo json_encode(['success' => false, 'message' => '未选择文件'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $upload_dir = __DIR__ . '/../uploads/images/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $result = sec_safe_upload(
        $_FILES['image'],
        ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        2 * 1024 * 1024,
        $upload_dir
    );
    
    if (!$result['success']) {
        echo json_encode(['success' => false, 'message' => $result['message']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $image_url = '/uploads/images/' . $result['filename'];
    sec_admin_audit('upload_image', 'vm_images', 0, ['filename' => $result['filename'], 'size' => $_FILES['image']['size']]);
    echo json_encode(['success' => true, 'message' => '上传成功', 'url' => $image_url], JSON_UNESCAPED_UNICODE);
    exit;
}
if (is_post() && post('action', '') === 'check_image' && is_ajax()) {
    @ini_set('display_errors', 0);
    @error_reporting(0);
    while (ob_get_level() > 0) ob_end_clean();
    require_admin();

    header('Content-Type: application/json; charset=utf-8');
    $id = intval(post('id', 0));
    $img = Database::fetch("SELECT iso_path, preinstalled_image, disk_type, name FROM vm_images WHERE id = ?", [$id]);
    if (!$img) {
        echo json_encode(['success' => false, 'message' => '镜像不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $kvm_cfg = config('kvm');
    if (empty($kvm_cfg) || empty($kvm_cfg['enabled'])) {
        echo json_encode(['success' => false, 'message' => 'KVM未启用'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    require_once __DIR__ . '/../config/KvmManager.php';
    $mgr = new KvmManager($kvm_cfg);
    
    $results = [];
    
    // 检查安装引导 ISO（如果有）
    if (!empty($img['iso_path'])) {
        try {
            $r = $mgr->checkImageFile($img['iso_path']);
            $results['iso'] = [
                'exists' => $r['exists'],
                'size' => $r['size'],
                'path' => $img['iso_path'],
                'type' => 'ISO安装镜像'
            ];
        } catch (Exception $e) {
            $results['iso'] = ['exists' => false, 'error' => $e->getMessage()];
        }
    }
    
    // 检查预装系统镜像（如果有）
    if (!empty($img['preinstalled_image'])) {
        try {
            $r = $mgr->checkImageFile($img['preinstalled_image']);
            $results['preinstalled'] = [
                'exists' => $r['exists'],
                'size' => $r['size'],
                'path' => $img['preinstalled_image'],
                'type' => '预装系统镜像 (' . strtoupper($img['disk_type'] ?? 'qcow2') . ')'
            ];
        } catch (Exception $e) {
            $results['preinstalled'] = ['exists' => false, 'error' => $e->getMessage()];
        }
    }
    
    $all_exist = true;
    foreach ($results as $r) {
        if (!$r['exists']) $all_exist = false;
    }
    
    echo json_encode([
        'success' => true,
        'all_exist' => $all_exist,
        'name' => $img['name'],
        'disk_type' => $img['disk_type'] ?? 'qcow2',
        'results' => $results
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============ 以下为页面渲染 ============
require_admin();
migrate_new_tables();

// 获取所有镜像
$images = kvm_get_images(false);

// 处理操作
if (is_post()) {
    $action = post('action', '');
    if ($action === 'add' || $action === 'edit') {
        $id = intval(post('id', 0));
        $data = [
            'name' => trim(post('name', '')),
            'os_type' => trim(post('os_type', 'linux')),
            'iso_path' => trim(post('image_path', '')),
            'disk_type' => trim(post('disk_type', 'qcow2')),
            'preinstalled_image' => trim(post('preinstalled_image', '')),
            'min_cpu' => intval(post('min_cpu', 1)),
            'min_memory_mb' => intval(post('min_memory_mb', 1024)),
            'min_disk_gb' => intval(post('min_disk_gb', 20)),
            'default_username' => trim(post('default_username', 'root')),
            'recommended' => trim(post('recommended', '')),
            'status' => post('enabled') ? 'active' : 'disabled',
            'sort_order' => intval(post('sort_order', 0)),
            'description' => trim(post('description', '')),
            'image_url' => trim(post('image_url', '')),
        ];
        // 验证磁盘格式
        if (!in_array($data['disk_type'], ['qcow2', 'raw', 'img', 'vmdk', 'vdi'])) {
            $data['disk_type'] = 'qcow2';
        }
        if (empty($data['name'])) { flash('error', '请输入镜像名称'); }
        elseif (empty($data['iso_path'])) { flash('error', '请输入镜像文件路径'); }
        elseif (!in_array($data['os_type'], ['linux', 'windows', 'other'])) { flash('error', '不支持的操作系统类型'); }
        else {
            if ($action === 'add') {
                $iid = Database::insert('vm_images', $data);
                flash('success', '镜像已添加 ID: ' . $iid);
            } else {
                $existing = Database::fetch("SELECT id FROM vm_images WHERE id = ?", [$id]);
                if (!$existing) { flash('error', '镜像不存在'); }
                else {
                    Database::update('vm_images', $data, 'id = ?', [$id]);
                    flash('success', '镜像已更新');
                }
            }
            header('Location: /admin/vm_images.php');
            exit;
        }
        // 回显
        $edit_data = $data;
        $edit_data['id'] = $id;
    }
    if ($action === 'delete') {
        $id = intval(post('id', 0));
        $existing = Database::fetch("SELECT id FROM vm_images WHERE id = ?", [$id]);
        if (!$existing) { flash('error', '镜像不存在'); }
        else {
            Database::query("DELETE FROM vm_images WHERE id = ?", [$id]);
            flash('success', '镜像已删除');
        }
        header('Location: /admin/vm_images.php');
        exit;
    }
    
    // 批量启用
    if ($action === 'batch_enable') {
        $ids = post('ids', '');
        if (empty($ids)) {
            flash('error', '请选择要启用的镜像');
        } else {
            $id_arr = explode(',', $ids);
            $count = 0;
            foreach ($id_arr as $id) {
                $id = intval($id);
                if ($id > 0) {
                    Database::execute("UPDATE vm_images SET status = 'active' WHERE id = ?", [$id]);
                    $count++;
                }
            }
            flash('success', "已启用 {$count} 个镜像");
        }
        header('Location: /admin/vm_images.php');
        exit;
    }
    
    // 批量禁用
    if ($action === 'batch_disable') {
        $ids = post('ids', '');
        if (empty($ids)) {
            flash('error', '请选择要禁用的镜像');
        } else {
            $id_arr = explode(',', $ids);
            $count = 0;
            foreach ($id_arr as $id) {
                $id = intval($id);
                if ($id > 0) {
                    Database::execute("UPDATE vm_images SET status = 'disabled' WHERE id = ?", [$id]);
                    $count++;
                }
            }
            flash('success', "已禁用 {$count} 个镜像");
        }
        header('Location: /admin/vm_images.php');
        exit;
    }
    
    // 单个切换状态
    if ($action === 'toggle_status') {
        $id = intval(post('id', 0));
        $img = Database::fetch("SELECT id, status FROM vm_images WHERE id = ?", [$id]);
        if (!$img) {
            flash('error', '镜像不存在');
        } else {
            $new_status = ($img['status'] === 'active') ? 'disabled' : 'active';
            Database::execute("UPDATE vm_images SET status = ? WHERE id = ?", [$new_status, $id]);
            flash('success', "镜像已" . ($new_status === 'active' ? '启用' : '禁用'));
        }
        header('Location: /admin/vm_images.php');
        exit;
    }
}

// 编辑模式
$edit = null;
if (get('action') === 'edit') {
    $edit = Database::fetch("SELECT * FROM vm_images WHERE id = ?", [intval(get('id', 0))]);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KVM镜像管理 - <?php echo e(config('app.name')); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .table-actions { display: flex; gap: 8px; }
        .form-row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>
        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">KVM 系统镜像管理</h1>
                    <p class="page-subtitle">管理可供用户选择的操作系统镜像</p>
                </div>
                <a href="/admin/vm_images.php?action=add" class="btn btn-primary">+ 添加新镜像</a>
            </div>

            <?php if ($msg = flash('error')): ?><div class="alert alert-error"><?php echo e($msg); ?></div><?php endif; ?>
            <?php if ($msg = flash('success')): ?><div class="alert alert-success"><?php echo e($msg); ?></div><?php endif; ?>

            <!-- 添加/编辑表单 -->
            <?php if (get('action') === 'add' || get('action') === 'edit'): ?>
            <div class="card">
                <div class="card-title"><?php echo $edit ? '编辑镜像' : '添加新镜像'; ?></div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="<?php echo $edit ? 'edit' : 'add'; ?>">
                    <input type="hidden" name="id" value="<?php echo $edit['id'] ?? 0; ?>">
                    <input type="hidden" name="image_url" id="imageUrlInput" value="<?php echo e($edit['image_url'] ?? ''); ?>">
                    <div class="form-row">
                        <div class="form-group">
                            <label>镜像名称 *</label>
                            <input type="text" class="form-control" name="name" value="<?php echo e($edit['name'] ?? ''); ?>" placeholder="如: Ubuntu 22.04 LTS">
                        </div>
                        <div class="form-group">
                            <label>操作系统类型</label>
                            <select class="form-control" name="os_type">
                                <option value="linux" <?php echo ($edit['os_type'] ?? 'linux') === 'linux' ? 'selected' : ''; ?>>Linux</option>
                                <option value="windows" <?php echo ($edit['os_type'] ?? '') === 'windows' ? 'selected' : ''; ?>>Windows</option>
                                <option value="other" <?php echo ($edit['os_type'] ?? '') === 'other' ? 'selected' : ''; ?>>其他</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>镜像文件路径 (宿主机上) *</label>
                        <input type="text" class="form-control" name="image_path" value="<?php echo e($edit['iso_path'] ?? ''); ?>" placeholder="如: /var/lib/libvirt/images/iso/ubuntu2204.iso">
                        <div style="font-size:11px; color:#86909c; margin-top:4px;">安装引导用 ISO 文件路径（如用于全新安装的系统镜像）</div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>磁盘格式</label>
                            <select class="form-control" name="disk_type">
                                <option value="qcow2" <?php echo ($edit['disk_type'] ?? 'qcow2') === 'qcow2' ? 'selected' : ''; ?>>qcow2（推荐，支持快照和压缩）</option>
                                <option value="raw" <?php echo ($edit['disk_type'] ?? '') === 'raw' ? 'selected' : ''; ?>>raw（无压缩，性能好）</option>
                                <option value="img" <?php echo ($edit['disk_type'] ?? '') === 'img' ? 'selected' : ''; ?>>img（与传统镜像兼容）</option>
                                <option value="vmdk" <?php echo ($edit['disk_type'] ?? '') === 'vmdk' ? 'selected' : ''; ?>>vmdk（VMware格式）</option>
                                <option value="vdi" <?php echo ($edit['disk_type'] ?? '') === 'vdi' ? 'selected' : ''; ?>>vdi（VirtualBox格式）</option>
                            </select>
                            <div style="font-size:11px; color:#86909c; margin-top:4px;">创建虚拟机磁盘时使用的格式</div>
                        </div>
                        <div class="form-group">
                            <label>预装系统镜像（可选）</label>
                            <input type="text" class="form-control" name="preinstalled_image" value="<?php echo e($edit['preinstalled_image'] ?? ''); ?>" placeholder="如: /var/lib/libvirt/images/preinstalled/centos7.qcow2">
                            <div style="font-size:11px; color:#86909c; margin-top:4px;">预装好的系统镜像路径，有此路径则优先克隆使用，跳过安装步骤</div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>镜像图标</label>
                        <div id="imagePreview" style="margin-bottom:8px;">
                            <?php if (!empty($edit['image_url'])): ?>
                                <img src="<?php echo e($edit['image_url']); ?>" style="max-width:120px; max-height:80px; border-radius:4px; border:1px solid #e5e6eb;">
                            <?php else: ?>
                                <div style="width:120px; height:80px; border:2px dashed #d1d9e6; border-radius:4px; display:flex; align-items:center; justify-content:center; color:#86909c; font-size:12px;">暂无图片</div>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <input type="file" id="imageFile" accept="image/*" style="display:none;">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('imageFile').click();">选择图片</button>
                            <button type="button" class="btn btn-sm btn-danger" onclick="clearImage()" <?php echo empty($edit['image_url']) ? 'disabled' : ''; ?>>清除图片</button>
                        </div>
                        <div style="font-size:11px; color:#86909c; margin-top:4px;">支持 JPG、PNG、WebP、GIF 格式，最大2MB</div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>最小CPU (核)</label>
                            <input type="number" class="form-control" name="min_cpu" value="<?php echo $edit['min_cpu'] ?? 1; ?>">
                        </div>
                        <div class="form-group">
                            <label>最小内存 (MB)</label>
                            <input type="number" class="form-control" name="min_memory_mb" value="<?php echo $edit['min_memory_mb'] ?? 1024; ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>最小磁盘 (GB)</label>
                            <input type="number" class="form-control" name="min_disk_gb" value="<?php echo $edit['min_disk_gb'] ?? 20; ?>">
                        </div>
                        <div class="form-group">
                            <label>默认用户名</label>
                            <input type="text" class="form-control" name="default_username" value="<?php echo e($edit['default_username'] ?? 'root'); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>推荐说明</label>
                            <input type="text" class="form-control" name="recommended" value="<?php echo e($edit['recommended'] ?? ''); ?>" placeholder="如: 推荐新手使用">
                        </div>
                        <div class="form-group">
                            <label>排序</label>
                            <input type="number" class="form-control" name="sort_order" value="<?php echo $edit['sort_order'] ?? 0; ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>描述</label>
                        <textarea class="form-control" name="description" rows="3" placeholder="镜像描述..."><?php echo e($edit['description'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="enabled" value="1" <?php echo ($edit['status'] ?? 'active') === 'active' ? 'checked' : ''; ?>> 启用此镜像
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary">保存</button>
                    <a href="/admin/vm_images.php" class="btn btn-secondary">取消</a>
                </form>
            </div>
            <?php else: ?>

            <!-- 镜像列表 -->
            <div class="card">
                <div class="card-title" style="display:flex; justify-content:space-between; align-items:center;">
                    <span>现有镜像 (共 <?php echo count($images); ?> 个)</span>
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="btn btn-sm btn-success" onclick="batchEnable()">批量启用</button>
                        <button type="button" class="btn btn-sm btn-warning" onclick="batchDisable()">批量禁用</button>
                    </div>
                </div>
                <?php if (empty($images)): ?>
                    <div class="empty-state"><div class="empty-state-icon">💿</div><h3>暂无镜像</h3><p>请先添加系统镜像</p></div>
                <?php else: ?>
                    <form id="batchForm" method="POST" style="display:none;">
                        <input type="hidden" name="action" id="batchAction" value="">
                        <input type="hidden" name="ids" id="batchIds" value="">
                    </form>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th style="width:40px;"><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                                <th>ID</th>
                                <th>名称</th>
                                <th>系统</th>
                                <th>磁盘格式</th>
                                <th>最小配置</th>
                                <th>路径</th>
                                <th>预装镜像</th>
                                <th>状态</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($images as $img): ?>
                            <tr>
                                <td><input type="checkbox" class="img-checkbox" value="<?php echo $img['id']; ?>"></td>
                                <td><?php echo $img['id']; ?></td>
                                <td>
                                    <strong><?php echo e($img['name']); ?></strong>
                                    <?php if (!empty($img['recommended'])): ?><br><small style="color:#22c55e;">✓ <?php echo e($img['recommended']); ?></small><?php endif; ?>
                                </td>
                                <td><?php echo $img['os_type'] === 'windows' ? '🪟 Windows' : ($img['os_type'] === 'linux' ? '🐧 Linux' : '💾 其他'); ?></td>
                                <td><span style="background:#e6f4ff; color:#1677ff; padding:2px 8px; border-radius:10px; font-size:11px;"><?php echo strtoupper($img['disk_type'] ?? 'qcow2'); ?></span></td>
                                <td><?php echo $img['min_cpu']; ?>核 / <?php echo $img['min_memory_mb']; ?>MB / <?php echo $img['min_disk_gb']; ?>GB</td>
                                <td style="font-size:11px; font-family:monospace; max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo e($img['iso_path']); ?>"><?php echo e($img['iso_path']); ?></td>
                                <td style="font-size:11px; font-family:monospace; max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo e($img['preinstalled_image'] ?? ''); ?>"><?php echo !empty($img['preinstalled_image']) ? '<span style="color:#22c55e;">✓ 已配置</span>' : '<span style="color:#86909c;">-</span>'; ?></td>
                                <td>
                                    <?php if (($img['status'] ?? 'active') === 'active'): ?>
                                        <span style="color:#22c55e;">已启用</span>
                                    <?php else: ?>
                                        <span style="color:#86909c;">已禁用</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="id" value="<?php echo $img['id']; ?>">
                                            <button type="submit" class="btn btn-sm <?php echo ($img['status'] ?? 'active') === 'active' ? 'btn-warning' : 'btn-success'; ?>">
                                                <?php echo ($img['status'] ?? 'active') === 'active' ? '禁用' : '启用'; ?>
                                            </button>
                                        </form>
                                        <button type="button" class="btn btn-sm btn-info" onclick="checkImage(<?php echo $img['id']; ?>, this)">✓ 测镜像</button>
                                        <a href="/admin/vm_images.php?action=edit&id=<?php echo $img['id']; ?>" class="btn btn-sm btn-secondary">编辑</a>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('确认删除此镜像？');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $img['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">删除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- KVM宿主机配置 -->
            <div class="card">
                <div class="card-title" style="display:flex; justify-content:space-between; align-items:center;">
                    <span>KVM 宿主机配置</span>
                    <button type="button" class="btn btn-primary" id="btnRunDiag" onclick="runDiag()">🔍 运行KVM环境自检</button>
                </div>
                <?php
                $kvm_cfg = config('kvm');
                ?>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px,1fr)); gap:12px; padding:8px 0;">
                    <div style="padding:12px; background:#f5f9ff; border-radius:8px;">
                        <div style="font-size:12px; color:#86909c;">宿主机地址</div>
                        <div style="font-size:15px; font-weight:600; color:#1d2129; margin-top:4px; font-family:monospace;"><?php echo e($kvm_cfg['host']); ?></div>
                    </div>
                    <div style="padding:12px; background:#f5f9ff; border-radius:8px;">
                        <div style="font-size:12px; color:#86909c;">SSH端口</div>
                        <div style="font-size:15px; font-weight:600; color:#1d2129; margin-top:4px; font-family:monospace;"><?php echo intval($kvm_cfg['port']); ?></div>
                    </div>
                    <div style="padding:12px; background:#f5f9ff; border-radius:8px;">
                        <div style="font-size:12px; color:#86909c;">SSH用户</div>
                        <div style="font-size:15px; font-weight:600; color:#1d2129; margin-top:4px; font-family:monospace;"><?php echo e($kvm_cfg['user']); ?></div>
                    </div>
                    <div style="padding:12px; background:#f5f9ff; border-radius:8px;">
                        <div style="font-size:12px; color:#86909c;">网桥</div>
                        <div style="font-size:15px; font-weight:600; color:#1d2129; margin-top:4px; font-family:monospace;"><?php echo e($kvm_cfg['bridge']); ?></div>
                    </div>
                    <div style="padding:12px; background:#f5f9ff; border-radius:8px;">
                        <div style="font-size:12px; color:#86909c;">存储池</div>
                        <div style="font-size:13px; font-weight:600; color:#1d2129; margin-top:4px; font-family:monospace;"><?php echo e($kvm_cfg['storage']); ?></div>
                    </div>
                    <div style="padding:12px; background:#f5f9ff; border-radius:8px;">
                        <div style="font-size:12px; color:#86909c;">功能开关</div>
                        <div style="font-size:15px; font-weight:600; color:#1d2129; margin-top:4px;"><?php echo !empty($kvm_cfg['enabled']) ? '<span style="color:#22c55e;">✓ 已启用</span>' : '<span style="color:#86909c;">✕ 已禁用</span>'; ?></div>
                    </div>
                </div>
                <div style="padding:12px; background:#fef3c7; border:1px solid #fde68a; border-radius:8px; color:#92400e; font-size:13px; margin-top:12px;">
                    💡 提示：修改KVM宿主机配置请编辑 <code>config/app.php</code> 中的 <code>kvm</code> 配置节点。镜像文件需要提前放置到宿主机指定路径。
                </div>
            </div>

            <!-- KVM环境自检结果 -->
            <div class="card" id="diagCard" style="display:none;">
                <div class="card-title" style="display:flex; justify-content:space-between; align-items:center;">
                    <span>KVM 环境自检结果</span>
                    <span id="diagSummary" style="font-size:13px;"></span>
                </div>
                <div id="diagContent" style="font-size:13px;"></div>
            </div>

            <!-- ISO转qcow2工具 -->
            <div class="card">
                <div class="card-title" style="display:flex; justify-content:space-between; align-items:center;">
                    <span>🗂️ ISO 转 qcow2 工具（QEMU引导安装）</span>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="loadIsoFiles()">🔄 刷新ISO列表</button>
                </div>
                <div style="padding:8px;">
                    <div style="padding:10px 12px; margin-bottom:12px; background:#fff8e1; border:1px solid #ffe082; border-radius:6px; font-size:12px; color:#8a6d3b;">
                        <strong>⚠️ 工作原理：</strong>ISO是光盘格式，直接格式转换无法作为硬盘启动。本工具会创建空白qcow2磁盘，用QEMU引导ISO进入安装程序，您需通过noVNC完成系统安装，安装完成后得到的qcow2即为可启动的预装镜像。
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="grid-column:span 1;">
                            <label>源ISO文件 *</label>
                            <select id="isoSelect" class="form-control" onchange="onIsoSelect()" style="font-family:monospace;">
                                <option value="">-- 加载中 --</option>
                            </select>
                            <div style="font-size:11px; color:#86909c; margin-top:4px;">从宿主机存储池读取ISO文件列表</div>
                        </div>
                        <div class="form-group" style="grid-column:span 1;">
                            <label>输出qcow2路径</label>
                            <input type="text" id="outputPath" class="form-control" placeholder="留空自动生成" style="font-family:monospace;">
                            <div style="font-size:11px; color:#86909c; margin-top:4px;">默认与ISO同级目录，自动替换扩展名</div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="grid-column:span 1;">
                            <label>磁盘大小 (GB) *</label>
                            <input type="number" id="diskSizeGb" class="form-control" value="40" min="10" max="500">
                            <div style="font-size:11px; color:#86909c; margin-top:4px;">新建qcow2磁盘容量（10-500GB）</div>
                        </div>
                        <div class="form-group" style="grid-column:span 1;">
                            <label>安装内存 (MB)</label>
                            <input type="number" id="memoryMb" class="form-control" value="2048" min="1024" max="8192">
                            <div style="font-size:11px; color:#86909c; margin-top:4px;">安装时分配给QEMU的内存（1024-8192MB）</div>
                        </div>
                    </div>
                    <div style="display:flex; gap:12px; margin-top:16px;">
                        <button type="button" id="btnConvert" class="btn btn-primary" onclick="startConvert()" disabled>🚀 启动安装环境</button>
                        <button type="button" id="btnStopConvert" class="btn btn-danger" onclick="stopConvert()" style="display:none;">⏹️ 停止安装进程</button>
                        <button type="button" class="btn btn-secondary" onclick="clearConvertForm()">重置</button>
                    </div>
                </div>

                <!-- 转换结果 -->
                <div id="convertResult" style="display:none; margin-top:16px; padding:12px; border-radius:8px;">
                    <div id="convertStatus" style="font-weight:600; margin-bottom:8px;"></div>
                    <div id="convertDetails" style="font-size:13px; font-family:monospace; white-space:pre-wrap;"></div>
                </div>

                <!-- VNC安装连接 -->
                <div id="vncConnect" style="display:none; margin-top:16px; padding:12px; border:1px solid #22c55e; border-radius:8px; background:#f0fdf4;">
                    <div style="font-weight:600; color:#15803d; margin-bottom:8px;">🖥️ 系统安装连接（请通过noVNC完成系统安装）</div>
                    <div id="vncInfo" style="font-size:13px; font-family:monospace; line-height:1.8;"></div>
                    <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
                        <a id="novncLink" href="#" target="_blank" class="btn btn-primary btn-sm">🔗 打开noVNC安装界面</a>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="checkConvertStatus(true)">🔄 刷新状态</button>
                    </div>
                    <div style="font-size:11px; color:#86909c; margin-top:8px;">
                        说明：安装完成后请手动关闭虚拟机（在安装程序中选择重启/关机），QEMU进程退出后即得到可启动的qcow2镜像。
                    </div>
                </div>

                <!-- 进度日志 -->
                <div id="convertProgress" style="display:none; margin-top:16px;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                        <span>安装状态</span>
                        <span id="progressText">等待中...</span>
                    </div>
                    <div id="progressLog" style="font-size:11px; color:#86909c; margin-top:4px; font-family:monospace; max-height:120px; overflow-y:auto; background:#f8f9fa; padding:8px; border-radius:4px;"></div>
                </div>
            </div>

            <script>
            // ============ 图片上传 ============
            (function() {
                var imageFileEl = document.getElementById('imageFile');
                if (imageFileEl) {
                    imageFileEl.addEventListener('change', function(e) {
                        var file = e.target.files[0];
                        if (!file) return;

                        var btn = document.querySelector('button[onclick="document.getElementById(\'imageFile\').click();"]');
                        var origText = btn ? btn.innerHTML : '';
                        if (btn) btn.innerHTML = '上传中...';
                        if (btn) btn.disabled = true;

                        var fd = new FormData();
                        fd.append('action', 'upload_image');
                        fd.append('image', file);

                        fetch('/admin/vm_images.php', {
                            method: 'POST',
                            body: fd,
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (btn) btn.innerHTML = origText;
                            if (btn) btn.disabled = false;
                            if (data.success) {
                                var urlInput = document.getElementById('imageUrlInput');
                                var preview = document.getElementById('imagePreview');
                                var clearBtn = document.querySelector('button[onclick="clearImage()"]');
                                if (urlInput) urlInput.value = data.url;
                                if (preview) preview.innerHTML = '<img src="' + data.url + '" style="max-width:120px; max-height:80px; border-radius:4px; border:1px solid #e5e6eb;">';
                                if (clearBtn) clearBtn.disabled = false;
                            } else {
                                alert('上传失败: ' + (data.message || '未知错误'));
                            }
                        })
                        .catch(function() {
                            if (btn) btn.innerHTML = origText;
                            if (btn) btn.disabled = false;
                            alert('上传失败，请重试');
                        });
                    });
                }
            })();
            
            function clearImage() {
                var urlInput = document.getElementById('imageUrlInput');
                var preview = document.getElementById('imagePreview');
                var clearBtn = document.querySelector('button[onclick="clearImage()"]');
                if (urlInput) urlInput.value = '';
                if (preview) preview.innerHTML = '<div style="width:120px; height:80px; border:2px dashed #d1d9e6; border-radius:4px; display:flex; align-items:center; justify-content:center; color:#86909c; font-size:12px;">暂无图片</div>';
                if (clearBtn) clearBtn.disabled = true;
            }

            // ============ 单个镜像测试 ============
            function checkImage(id, btn) {
                if (!btn) return;
                var orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '测试中...';
                var fd = new FormData();
                fd.append('action', 'check_image');
                fd.append('id', id);
                fetch('/admin/vm_images.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        btn.disabled = false;
                        btn.innerHTML = orig;
                        if (data.success === false) {
                            alert('测试失败: ' + (data.message || '未知错误'));
                            return;
                        }
                        
                        var msg = '镜像: ' + (data.name || '') + '\n磁盘格式: ' + (data.disk_type || 'qcow2').toUpperCase() + '\n\n';
                        
                        // 显示 ISO 和预装镜像的检查结果
                        if (data.results) {
                            for (var key in data.results) {
                                var r = data.results[key];
                                var label = r.type || key;
                                msg += label + ':\n';
                                msg += '  路径: ' + r.path + '\n';
                                if (r.exists) {
                                    msg += '  状态: ✓ 存在';
                                    if (r.size) msg += ' (' + r.size + ')';
                                    msg += '\n';
                                } else {
                                    msg += '  状态: ✗ 不存在\n';
                                    if (r.error) msg += '  错误: ' + r.error + '\n';
                                }
                                msg += '\n';
                            }
                        }
                        
                        if (data.all_exist) {
                            msg += '\n✓ 所有镜像文件均已配置';
                        } else {
                            msg += '\n⚠ 部分镜像文件不存在，请上传后再试';
                        }
                        
                        alert(msg);
                    })
                    .catch(function() {
                        btn.disabled = false;
                        btn.innerHTML = orig;
                        alert('请求失败，请刷新页面重试');
                    });
            }

            // ============ KVM环境完整自检 ============
            function runDiag() {
                var btn = document.getElementById('btnRunDiag');
                var card = document.getElementById('diagCard');
                var content = document.getElementById('diagContent');
                var summary = document.getElementById('diagSummary');
                btn.disabled = true;
                btn.innerHTML = '⏳ 正在检测 (约需5-15秒)...';
                card.style.display = 'block';
                content.innerHTML = '<div style="padding:20px; text-align:center; color:#86909c;">正在连接宿主机并执行检测，请稍候...</div>';
                summary.innerHTML = '';

                var fd = new FormData();
                fd.append('action', 'diag');
                fetch('/admin/vm_images.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        btn.disabled = false;
                        btn.innerHTML = '🔍 运行KVM环境自检';
                        if (data.success === false && !data.items) {
                            content.innerHTML = '<div style="padding:20px; background:#fef2f2; color:#b91c1c; border-radius:8px;">' + (data.message || '检测失败') + '</div>';
                            return;
                        }
                        var items = data.items || [];
                        var ok = 0, warn = 0, fail = 0;
                        var html = '<table style="width:100%; border-collapse:collapse;"><tbody>';
                        items.forEach(function(it) {
                            var icon, color;
                            if (it.status === 'ok') { icon = '✓'; color = '#22c55e'; ok++; }
                            else if (it.status === 'warn') { icon = '⚠'; color = '#f59e0b'; warn++; }
                            else { icon = '✗'; color = '#ef4444'; fail++; }
                            html += '<tr style="border-bottom:1px solid #f0f0f0;">'
                                 + '<td style="width:32px; padding:10px 8px; text-align:center; font-size:18px; color:' + color + ';">' + icon + '</td>'
                                 + '<td style="width:160px; padding:10px 8px; font-weight:600; color:#1d2129;">' + it.name + '</td>'
                                 + '<td style="padding:10px 8px; color:#4b5563; font-family:monospace; white-space:pre-wrap; word-break:break-all;">' + (it.detail || '') + '</td>'
                                 + '</tr>';
                        });
                        html += '</tbody></table>';
                        content.innerHTML = html;

                        var total = ok + warn + fail;
                        var summaryText = '';
                        if (fail === 0 && warn === 0) summaryText = '<span style="color:#22c55e;">✓ 全部正常 (' + total + '/' + total + ')</span>';
                        else if (fail === 0) summaryText = '<span style="color:#f59e0b;">⚠ 有 ' + warn + ' 项警告，请关注</span>';
                        else summaryText = '<span style="color:#ef4444;">✗ ' + fail + ' 项失败，无法创建虚拟机</span>';
                        summary.innerHTML = summaryText;
                    })
                    .catch(function(err) {
                        btn.disabled = false;
                        btn.innerHTML = '🔍 运行KVM环境自检';
                        content.innerHTML = '<div style="padding:20px; background:#fef2f2; color:#b91c1c; border-radius:8px;">请求失败: ' + (err.message || '请检查网络或刷新页面重试') + '</div>';
                    });
            }
            
            // ============ ISO转qcow2工具 ============
            function loadIsoFiles() {
                var select = document.getElementById('isoSelect');
                select.innerHTML = '<option value="">-- 加载中 --</option>';
                document.getElementById('btnConvert').disabled = true;

                var fd = new FormData();
                fd.append('action', 'list_iso');
                fetch('/admin/vm_images.php', {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success || !data.files || data.files.length === 0) {
                        select.innerHTML = '<option value="">-- 未找到ISO文件 --</option>';
                        return;
                    }
                    var html = '<option value="">-- 选择ISO文件 --</option>';
                    data.files.forEach(function(f) {
                        html += '<option value="' + (f.path || '') + '">' + (f.name || '') + ' (' + (f.size || '') + ')</option>';
                    });
                    select.innerHTML = html;
                })
                .catch(function() {
                    select.innerHTML = '<option value="">-- 加载失败 --</option>';
                });
            }

            function onIsoSelect() {
                var select = document.getElementById('isoSelect');
                var output = document.getElementById('outputPath');
                var btn = document.getElementById('btnConvert');
                var selected = select.value;

                btn.disabled = !selected;

                if (selected && !output.value) {
                    var autoPath = selected.replace(/\.(iso|ISO)$/i, '.qcow2');
                    if (autoPath === selected) {
                        autoPath = selected + '.qcow2';
                    }
                    output.value = autoPath;
                }
            }

            function clearConvertForm() {
                document.getElementById('isoSelect').value = '';
                document.getElementById('outputPath').value = '';
                document.getElementById('diskSizeGb').value = '40';
                document.getElementById('memoryMb').value = '2048';
                document.getElementById('btnConvert').disabled = true;
                document.getElementById('convertResult').style.display = 'none';
                document.getElementById('convertProgress').style.display = 'none';
                document.getElementById('vncConnect').style.display = 'none';
                document.getElementById('btnStopConvert').style.display = 'none';
            }

            function appendLog(msg) {
                var log = document.getElementById('progressLog');
                var ts = new Date().toLocaleTimeString();
                log.innerHTML += '[' + ts + '] ' + msg + '\n';
                log.scrollTop = log.scrollHeight;
            }

            function startConvert() {
                var isoPath = document.getElementById('isoSelect').value;
                var outputPath = document.getElementById('outputPath').value;
                var diskSizeGb = parseInt(document.getElementById('diskSizeGb').value) || 40;
                var memoryMb = parseInt(document.getElementById('memoryMb').value) || 2048;

                if (!isoPath) {
                    alert('请选择ISO文件');
                    return;
                }
                if (diskSizeGb < 10 || diskSizeGb > 500) {
                    alert('磁盘大小需在10-500GB之间');
                    return;
                }

                if (!confirm('将启动QEMU引导ISO安装环境：\n  磁盘大小: ' + diskSizeGb + 'GB\n  内存: ' + memoryMb + 'MB\n\n启动后请通过noVNC完成系统安装。确认继续？')) {
                    return;
                }

                var btn = document.getElementById('btnConvert');
                btn.disabled = true;
                btn.innerHTML = '⏳ 启动中...';

                document.getElementById('convertResult').style.display = 'none';
                document.getElementById('vncConnect').style.display = 'none';
                document.getElementById('convertProgress').style.display = 'block';
                document.getElementById('progressText').innerHTML = '启动中...';
                document.getElementById('progressLog').innerHTML = '';
                appendLog('开始启动QEMU安装环境...');

                var fd = new FormData();
                fd.append('action', 'convert_iso');
                fd.append('iso_path', isoPath);
                fd.append('output_path', outputPath);
                fd.append('disk_size_gb', diskSizeGb);
                fd.append('memory_mb', memoryMb);

                fetch('/admin/vm_images.php', {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) {
                        btn.disabled = false;
                        btn.innerHTML = '🚀 启动安装环境';
                        appendLog('启动失败: ' + (data.message || '未知错误'));
                        document.getElementById('convertResult').style.display = 'block';
                        document.getElementById('convertStatus').innerHTML = '<span style="color:#ef4444;">❌ 启动失败</span>';
                        document.getElementById('convertDetails').innerHTML = data.message || '未知错误';
                        return;
                    }

                    // 异步模式，开始轮询检查状态
                    appendLog('任务已创建 (ID: ' + data.task_id + ')，等待QEMU启动...');
                    btn.innerHTML = '⏳ 等待启动...';
                    pollConvertStatus(data.task_id, btn);
                })
                .catch(function(err) {
                    btn.disabled = false;
                    btn.innerHTML = '🚀 启动安装环境';
                    appendLog('请求失败: ' + (err.message || '请检查网络'));
                });
            }

            function pollConvertStatus(taskId, btn) {
                var checkCount = 0;
                var maxChecks = 30; // 最多轮询30次（约60秒），等待QEMU启动

                function check() {
                    checkCount++;
                    if (checkCount > maxChecks) {
                        appendLog('等待超时，请稍后点击「刷新状态」手动检查');
                        btn.disabled = false;
                        btn.innerHTML = '🚀 启动安装环境';
                        return;
                    }

                    var fd = new FormData();
                    fd.append('action', 'check_convert_status');
                    fd.append('task_id', taskId);

                    fetch('/admin/vm_images.php', {
                        method: 'POST',
                        body: fd,
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data.success) {
                            appendLog('查询状态失败');
                            btn.disabled = false;
                            btn.innerHTML = '🚀 启动安装环境';
                            return;
                        }

                        if (data.status === 'completed') {
                            // QEMU已启动，显示VNC连接信息
                            var result = data.result || {};
                            var live = data.live || {};
                            appendLog('✓ QEMU启动成功，ISO引导中');

                            // 显示结果
                            document.getElementById('convertResult').style.display = 'block';
                            document.getElementById('convertStatus').innerHTML = '<span style="color:#22c55e;">✓ 安装环境已就绪</span>';
                            var details = '输出文件: ' + (result.output_path || '-') + '\n';
                            details += '磁盘大小: ' + (result.disk_size_gb || '-') + 'GB\n';
                            if (result.virtual_size) details += '虚拟大小: ' + result.virtual_size + '\n';
                            if (live.file_size) details += '当前文件大小: ' + live.file_size + '\n';
                            if (live.disk_size) details += '磁盘占用: ' + live.disk_size;
                            document.getElementById('convertDetails').innerHTML = details;

                            // 显示VNC连接信息
                            document.getElementById('vncConnect').style.display = 'block';
                            var vncInfo = 'VNC端口: ' + (result.vnc_port || '-') + '\n';
                            vncInfo += 'websockify端口: ' + (result.ws_port || '-') + '\n';
                            vncInfo += 'QEMU PID: ' + (result.pid || '-');
                            if (live.running) {
                                vncInfo += '\n状态: <span style="color:#22c55e;">● 运行中（安装进行中）</span>';
                            } else {
                                vncInfo += '\n状态: <span style="color:#ef4444;">● 已退出（安装可能已完成）</span>';
                            }
                            document.getElementById('vncInfo').innerHTML = vncInfo;

                            // noVNC链接
                            var novncLink = document.getElementById('novncLink');
                            if (result.ws_url) {
                                novncLink.href = result.ws_url;
                                novncLink.style.display = 'inline-block';
                            } else {
                                novncLink.style.display = 'none';
                            }

                            // 显示停止按钮
                            document.getElementById('btnStopConvert').style.display = 'inline-block';
                            document.getElementById('progressText').innerHTML = live.running ? '安装进行中' : '进程已退出';

                            // 存储output_path供停止使用
                            window._convertOutputPath = result.output_path || '';

                            btn.disabled = false;
                            btn.innerHTML = '🚀 启动安装环境';

                            // 如果还在运行，继续定期刷新状态
                            if (live.running) {
                                setTimeout(function() { checkConvertStatus(false); }, 10000);
                            } else {
                                appendLog('QEMU进程已退出，qcow2镜像可能已就绪');
                            }
                        } else if (data.status === 'error') {
                            appendLog('❌ 启动失败: ' + (data.error_msg || '未知错误'));
                            btn.disabled = false;
                            btn.innerHTML = '🚀 启动安装环境';
                            document.getElementById('convertResult').style.display = 'block';
                            document.getElementById('convertStatus').innerHTML = '<span style="color:#ef4444;">❌ 启动失败</span>';
                            document.getElementById('convertDetails').innerHTML = data.error_msg || '未知错误';
                            document.getElementById('progressText').innerHTML = '失败';
                        } else {
                            // pending 或 running，继续轮询
                            appendLog('等待QEMU启动... (' + checkCount + '/' + maxChecks + ')');
                            document.getElementById('progressText').innerHTML = '等待启动... (' + data.status + ')';
                            setTimeout(check, 2000);
                        }
                    })
                    .catch(function() {
                        appendLog('网络错误，重试中...');
                        setTimeout(check, 3000);
                    });
                }

                setTimeout(check, 2000);
            }

            // 刷新安装状态（手动触发或自动轮询）
            function checkConvertStatus(manual) {
                if (!window._convertOutputPath) {
                    if (manual) alert('当前没有进行中的安装任务');
                    return;
                }
                var fd = new FormData();
                fd.append('action', 'check_convert_status');
                fd.append('task_id', 0);
                fd.append('output_path', window._convertOutputPath);

                // 直接用stop接口的逻辑不合适，改用task查询
                // 这里改为重新查询最近的任务
                fetch('/admin/vm_images.php', {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) {
                        if (manual) alert('查询失败: ' + (data.message || ''));
                        return;
                    }
                    var live = data.live || {};
                    var vncInfo = document.getElementById('vncInfo');
                    if (vncInfo) {
                        var html = vncInfo.innerHTML.replace(/状态:.*$/m, '');
                        if (live.running) {
                            html += '\n状态: <span style="color:#22c55e;">● 运行中（安装进行中）</span>';
                        } else {
                            html += '\n状态: <span style="color:#ef4444;">● 已退出（安装可能已完成）</span>';
                        }
                        vncInfo.innerHTML = html;
                    }
                    appendLog('状态刷新: ' + (live.running ? '运行中，文件大小 ' + (live.file_size || '-') : '进程已退出'));
                })
                .catch(function() {
                    if (manual) alert('查询失败，请重试');
                });
            }

            function stopConvert() {
                if (!window._convertOutputPath) {
                    alert('没有进行中的安装任务');
                    return;
                }
                if (!confirm('确定停止QEMU安装进程？\n注意：停止前请确保已通过noVNC完成系统安装，否则qcow2镜像将不可用。')) {
                    return;
                }
                var fd = new FormData();
                fd.append('action', 'stop_convert');
                fd.append('output_path', window._convertOutputPath);

                fetch('/admin/vm_images.php', {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    appendLog('停止操作: ' + (data.message || '完成'));
                    document.getElementById('btnStopConvert').style.display = 'none';
                    if (data.success) {
                        document.getElementById('progressText').innerHTML = '已停止';
                        setTimeout(function() { checkConvertStatus(true); }, 1000);
                    }
                })
                .catch(function() {
                    alert('停止请求失败');
                });
            }

            window.addEventListener('load', loadIsoFiles);

            // ============ 批量操作 ============
            function toggleSelectAll() {
                var checkboxes = document.querySelectorAll('.img-checkbox');
                var selectAll = document.getElementById('selectAll');
                checkboxes.forEach(function(cb) {
                    cb.checked = selectAll.checked;
                });
            }
            
            function getSelectedIds() {
                var checkboxes = document.querySelectorAll('.img-checkbox:checked');
                var ids = [];
                checkboxes.forEach(function(cb) {
                    ids.push(cb.value);
                });
                return ids.join(',');
            }
            
            function batchEnable() {
                var ids = getSelectedIds();
                if (!ids) {
                    alert('请先勾选要启用的镜像');
                    return;
                }
                if (!confirm('确认批量启用选中的镜像？')) return;
                document.getElementById('batchAction').value = 'batch_enable';
                document.getElementById('batchIds').value = ids;
                document.getElementById('batchForm').submit();
            }
            
            function batchDisable() {
                var ids = getSelectedIds();
                if (!ids) {
                    alert('请先勾选要禁用的镜像');
                    return;
                }
                if (!confirm('确认批量禁用选中的镜像？')) return;
                document.getElementById('batchAction').value = 'batch_disable';
                document.getElementById('batchIds').value = ids;
                document.getElementById('batchForm').submit();
            }
            </script>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
