<?php
/**
 * 套餐分类管理
 */
require_once __DIR__ . '/../config/helper.php';
require_admin();

$page_title = '套餐分类管理';

// 处理POST请求
if (is_post()) {
    $action = post('action', '');

    if ($action === 'create') {
        $name = trim(post('name', ''));
        $description = trim(post('description', ''));
        $sort_order = intval(post('sort_order', 0));

        if (empty($name)) {
            flash('error', '分类名称不能为空');
        } else {
            Database::insert('package_categories', [
                'name' => $name,
                'description' => $description,
                'sort_order' => $sort_order,
                'status' => 'active',
            ]);
            flash('success', '分类创建成功');
        }
    } elseif ($action === 'update') {
        $id = intval(post('id', 0));
        $name = trim(post('name', ''));
        $description = trim(post('description', ''));
        $sort_order = intval(post('sort_order', 0));

        if ($id <= 0 || empty($name)) {
            flash('error', '参数错误');
        } else {
            Database::update('package_categories', [
                'name' => $name,
                'description' => $description,
                'sort_order' => $sort_order,
            ], 'id = ?', [$id]);
            flash('success', '分类更新成功');
        }
    } elseif ($action === 'toggle_status') {
        $id = intval(post('id', 0));
        if ($id > 0) {
            $cat = Database::fetch("SELECT status FROM package_categories WHERE id = ?", [$id]);
            $new_status = ($cat['status'] ?? '') === 'active' ? 'disabled' : 'active';
            Database::update('package_categories', ['status' => $new_status], 'id = ?', [$id]);
            flash('success', '状态已切换');
        }
    } elseif ($action === 'delete') {
        $id = intval(post('id', 0));
        if ($id > 0) {
            // 检查是否有套餐使用此分类
            $count = intval(Database::fetchColumn("SELECT COUNT(*) FROM packages WHERE category_id = ?", [$id]) ?? 0);
            if ($count > 0) {
                flash('error', '该分类下还有 ' . $count . ' 个套餐，请先移除或转移');
            } else {
                Database::delete('package_categories', 'id = ?', [$id]);
                flash('success', '分类已删除');
            }
        }
    }

    header('Location: /admin/package_categories.php');
    exit;
}

// 获取分类列表
$categories = Database::fetchAll("SELECT * FROM package_categories ORDER BY sort_order ASC, id ASC");

// 统计每个分类下的套餐数量
$cat_package_counts = [];
foreach ($categories as $cat) {
    $cnt = Database::fetchColumn("SELECT COUNT(*) FROM packages WHERE category_id = ?", [$cat['id']]);
    $cat_package_counts[$cat['id']] = intval($cnt ?? 0);
}
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
                    <p class="page-subtitle">管理主机套餐分类</p>
                </div>
                <button class="btn btn-primary" onclick="showCreateModal()">+ 添加分类</button>
            </div>

            <?php if (flash('success')): ?><div class="alert alert-success"><?php echo flash('success'); ?></div><?php endif; ?>
            <?php if (flash('error')): ?><div class="alert alert-error"><?php echo flash('error'); ?></div><?php endif; ?>

            <!-- 分类列表 -->
            <div class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <h2 class="card-title">分类列表</h2>
                    <span style="color: #86909c; font-size: 13px;">共 <?php echo count($categories); ?> 个分类</span>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width:60px;">ID</th>
                                <th>分类名称</th>
                                <th>描述</th>
                                <th style="width:100px;">套餐数</th>
                                <th style="width:100px;">排序</th>
                                <th style="width:100px;">状态</th>
                                <th style="width:180px;">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $cat): ?>
                            <tr>
                                <td><?php echo $cat['id']; ?></td>
                                <td><strong><?php echo e($cat['name']); ?></strong></td>
                                <td><?php echo e($cat['description']); ?></td>
                                <td><?php echo $cat_package_counts[$cat['id']] ?? 0; ?></td>
                                <td><?php echo $cat['sort_order']; ?></td>
                                <td>
                                    <?php if ($cat['status'] === 'active'): ?>
                                        <span class="badge badge-success">启用</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">禁用</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-secondary" onclick="editCategory(<?php echo $cat['id']; ?>, '<?php echo e(js_escape($cat['name'])); ?>', '<?php echo e(js_escape($cat['description'])); ?>', <?php echo $cat['sort_order']; ?>)" style="margin-right:4px;">编辑</button>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('确定切换状态?');">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-secondary" style="margin-right:4px;">
                                            <?php echo $cat['status'] === 'active' ? '禁用' : '启用'; ?>
                                        </button>
                                    </form>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('确定删除此分类?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">删除</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($categories)): ?>
                            <tr>
                                <td colspan="7" style="text-align:center; color:#86909c; padding:40px;">暂无分类，点击右上角添加</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 创建分类弹窗 -->
            <div class="modal" id="createModal">
                <div class="modal-content" style="max-width:480px;">
                    <div class="modal-header">
                        <h3 class="modal-title">添加分类</h3>
                        <button class="btn btn-icon" onclick="closeModal('createModal')">&times;</button>
                    </div>
                    <form method="post">
                        <input type="hidden" name="action" value="create">
                        <div class="form-group">
                            <label class="form-label">分类名称 <span style="color:var(--danger);">*</span></label>
                            <input type="text" name="name" class="form-control" placeholder="如：虚拟主机、KVM云服务器" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">分类描述</label>
                            <input type="text" name="description" class="form-control" placeholder="简要描述该分类">
                        </div>
                        <div class="form-group">
                            <label class="form-label">排序</label>
                            <input type="number" name="sort_order" class="form-control" value="0" placeholder="数字越小越靠前">
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">取消</button>
                            <button type="submit" class="btn btn-primary">创建</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- 编辑分类弹窗 -->
            <div class="modal" id="editModal">
                <div class="modal-content" style="max-width:480px;">
                    <div class="modal-header">
                        <h3 class="modal-title">编辑分类</h3>
                        <button class="btn btn-icon" onclick="closeModal('editModal')">&times;</button>
                    </div>
                    <form method="post">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="form-group">
                            <label class="form-label">分类名称 <span style="color:var(--danger);">*</span></label>
                            <input type="text" name="name" id="edit_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">分类描述</label>
                            <input type="text" name="description" id="edit_description" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">排序</label>
                            <input type="number" name="sort_order" id="edit_sort_order" class="form-control">
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
    function editCategory(id, name, description, sortOrder) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_name').value = name;
        document.getElementById('edit_description').value = description;
        document.getElementById('edit_sort_order').value = sortOrder;
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
