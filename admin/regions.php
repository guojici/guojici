<?php
/**
 * 地区管理
 */
require_once __DIR__ . '/../config/helper.php';
require_admin();

$page_title = '地区管理';

// 处理POST请求
if (is_post()) {
    $action = post('action', '');

    if ($action === 'create') {
        $name = trim(post('name', ''));
        $code = trim(post('code', ''));
        $description = trim(post('description', ''));
        $sort_order = intval(post('sort_order', 0));
        $is_default = intval(post('is_default', 0)) ? 1 : 0;

        if (empty($name) || empty($code)) {
            flash('error', '地区名称和代码不能为空');
        } else {
            // 检查代码是否重复
            $exists = Database::fetch("SELECT id FROM regions WHERE code = ?", [$code]);
            if ($exists) {
                flash('error', '地区代码已存在，请使用其他代码');
            } else {
                // 如果设为默认，先取消其他默认
                if ($is_default) {
                    Database::query("UPDATE regions SET is_default = 0 WHERE is_default = 1");
                }
                Database::insert('regions', [
                    'name' => $name,
                    'code' => $code,
                    'description' => $description,
                    'sort_order' => $sort_order,
                    'is_default' => $is_default,
                    'status' => 'active',
                ]);
                flash('success', '地区创建成功');
            }
        }
    } elseif ($action === 'update') {
        $id = intval(post('id', 0));
        $name = trim(post('name', ''));
        $code = trim(post('code', ''));
        $description = trim(post('description', ''));
        $sort_order = intval(post('sort_order', 0));
        $is_default = intval(post('is_default', 0)) ? 1 : 0;

        if ($id <= 0 || empty($name) || empty($code)) {
            flash('error', '参数错误');
        } else {
            // 检查代码是否重复（排除自身）
            $exists = Database::fetch("SELECT id FROM regions WHERE code = ? AND id != ?", [$code, $id]);
            if ($exists) {
                flash('error', '地区代码已存在，请使用其他代码');
            } else {
                // 如果设为默认，先取消其他默认
                if ($is_default) {
                    Database::query("UPDATE regions SET is_default = 0 WHERE is_default = 1 AND id != ?", [$id]);
                }
                Database::update('regions', [
                    'name' => $name,
                    'code' => $code,
                    'description' => $description,
                    'sort_order' => $sort_order,
                    'is_default' => $is_default,
                ], 'id = ?', [$id]);
                flash('success', '地区更新成功');
            }
        }
    } elseif ($action === 'toggle_status') {
        $id = intval(post('id', 0));
        if ($id > 0) {
            $region = Database::fetch("SELECT status, is_default FROM regions WHERE id = ?", [$id]);
            if ($region && $region['is_default']) {
                flash('error', '默认地区不能禁用');
            } else {
                $new_status = ($region['status'] ?? '') === 'active' ? 'disabled' : 'active';
                Database::update('regions', ['status' => $new_status], 'id = ?', [$id]);
                flash('success', '状态已切换');
            }
        }
    } elseif ($action === 'delete') {
        $id = intval(post('id', 0));
        if ($id > 0) {
            $region = Database::fetch("SELECT is_default FROM regions WHERE id = ?", [$id]);
            if ($region && $region['is_default']) {
                flash('error', '默认地区不能删除');
            } else {
                Database::delete('regions', 'id = ?', [$id]);
                flash('success', '地区已删除');
            }
        }
    } elseif ($action === 'set_default') {
        $id = intval(post('id', 0));
        if ($id > 0) {
            Database::query("UPDATE regions SET is_default = 0 WHERE is_default = 1");
            Database::update('regions', ['is_default' => 1, 'status' => 'active'], 'id = ?', [$id]);
            flash('success', '已设为默认地区');
        }
    }

    header('Location: /admin/regions.php');
    exit;
}

// 获取地区列表
$regions = Database::fetchAll("SELECT * FROM regions ORDER BY sort_order ASC, id ASC");
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - guojici云管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>
        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title"><?php echo $page_title; ?></h1>
                    <p class="page-subtitle">管理主机地区，前台右上角将显示默认地区</p>
                </div>
                <button class="btn btn-primary" onclick="showCreateModal()">+ 添加地区</button>
            </div>

            <?php if (flash('success')): ?><div class="alert alert-success"><?php echo flash('success'); ?></div><?php endif; ?>
            <?php if (flash('error')): ?><div class="alert alert-error"><?php echo flash('error'); ?></div><?php endif; ?>

            <!-- 地区列表 -->
            <div class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <h2 class="card-title">地区列表</h2>
                    <span style="color: #86909c; font-size: 13px;">共 <?php echo count($regions); ?> 个地区</span>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width:60px;">ID</th>
                                <th>地区名称</th>
                                <th>地区代码</th>
                                <th>描述</th>
                                <th style="width:100px;">排序</th>
                                <th style="width:100px;">默认</th>
                                <th style="width:100px;">状态</th>
                                <th style="width:240px;">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($regions as $r): ?>
                            <tr>
                                <td><?php echo $r['id']; ?></td>
                                <td><strong><?php echo e($r['name']); ?></strong></td>
                                <td><code><?php echo e($r['code']); ?></code></td>
                                <td><?php echo e($r['description']); ?></td>
                                <td><?php echo $r['sort_order']; ?></td>
                                <td>
                                    <?php if ($r['is_default']): ?>
                                        <span class="badge badge-success">默认</span>
                                    <?php else: ?>
                                        <span style="color:#86909c;font-size:12px;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($r['status'] === 'active'): ?>
                                        <span class="badge badge-success">启用</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">禁用</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-secondary" onclick="editRegion(<?php echo $r['id']; ?>, '<?php echo e(js_escape($r['name'])); ?>', '<?php echo e(js_escape($r['code'])); ?>', '<?php echo e(js_escape($r['description'])); ?>', <?php echo $r['sort_order']; ?>, <?php echo $r['is_default']; ?>)" style="margin-right:4px;">编辑</button>
                                    <?php if (!$r['is_default']): ?>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('确定设为默认地区?');">
                                        <input type="hidden" name="action" value="set_default">
                                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-secondary" style="margin-right:4px;">设默认</button>
                                    </form>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('确定切换状态?');">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-secondary" style="margin-right:4px;">
                                            <?php echo $r['status'] === 'active' ? '禁用' : '启用'; ?>
                                        </button>
                                    </form>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('确定删除此地区?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">删除</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($regions)): ?>
                            <tr>
                                <td colspan="8" style="text-align:center; color:#86909c; padding:40px;">暂无地区，点击右上角添加</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 创建地区弹窗 -->
            <div class="modal" id="createModal">
                <div class="modal-content" style="max-width:480px;">
                    <div class="modal-header">
                        <h3 class="modal-title">添加地区</h3>
                        <button class="btn btn-icon" onclick="closeModal('createModal')">&times;</button>
                    </div>
                    <form method="post">
                        <input type="hidden" name="action" value="create">
                        <div class="form-group">
                            <label class="form-label">地区名称 <span style="color:var(--danger);">*</span></label>
                            <input type="text" name="name" class="form-control" placeholder="如：上海、北京、广州" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">地区代码 <span style="color:var(--danger);">*</span></label>
                            <input type="text" name="code" class="form-control" placeholder="如：AP-Shanghai、AP-Beijing" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">地区描述</label>
                            <input type="text" name="description" class="form-control" placeholder="简要描述该地区">
                        </div>
                        <div class="form-group">
                            <label class="form-label">排序</label>
                            <input type="number" name="sort_order" class="form-control" value="0" placeholder="数字越小越靠前">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><input type="checkbox" name="is_default" value="1"> 设为默认地区（前台右上角显示）</label>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">取消</button>
                            <button type="submit" class="btn btn-primary">创建</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- 编辑地区弹窗 -->
            <div class="modal" id="editModal">
                <div class="modal-content" style="max-width:480px;">
                    <div class="modal-header">
                        <h3 class="modal-title">编辑地区</h3>
                        <button class="btn btn-icon" onclick="closeModal('editModal')">&times;</button>
                    </div>
                    <form method="post">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="form-group">
                            <label class="form-label">地区名称 <span style="color:var(--danger);">*</span></label>
                            <input type="text" name="name" id="edit_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">地区代码 <span style="color:var(--danger);">*</span></label>
                            <input type="text" name="code" id="edit_code" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">地区描述</label>
                            <input type="text" name="description" id="edit_description" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">排序</label>
                            <input type="number" name="sort_order" id="edit_sort_order" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><input type="checkbox" name="is_default" value="1" id="edit_is_default"> 设为默认地区（前台右上角显示）</label>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">取消</button>
                            <button type="submit" class="btn btn-primary">保存</button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>

    <script>
    function showCreateModal() {
        document.getElementById('createModal').classList.add('active');
    }
    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
    }
    function editRegion(id, name, code, description, sortOrder, isDefault) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_name').value = name;
        document.getElementById('edit_code').value = code;
        document.getElementById('edit_description').value = description;
        document.getElementById('edit_sort_order').value = sortOrder;
        document.getElementById('edit_is_default').checked = isDefault == 1;
        document.getElementById('editModal').classList.add('active');
    }
    // 点击模态框背景关闭
    document.querySelectorAll('.modal').forEach(function(modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) modal.classList.remove('active');
        });
    });
    </script>
</body>
</html>
