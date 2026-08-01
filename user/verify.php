<?php
require_once __DIR__ . '/../config/helper.php';
require_auth();

$user = auth_user();
$uid = auth_id();

@db_ensure_idverify_columns();

$success = flash('success');
$error = flash('error');

$id_cfg = config('idverify');
$enabled = !empty($id_cfg['enabled']);

$pdo = db();
$user_db = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$user_db->execute([$uid]);
$user_info = $user_db->fetch(PDO::FETCH_ASSOC);

if (is_post()) {
    $action = post('action');
    if ($action === 'verify') {
        if (!$enabled) {
            flash('error', '实名认证功能未启用');
            header('Location: /user/verify.php');
            exit;
        }
        if (!empty($user_info['id_verify_status']) && $user_info['id_verify_status'] == 1) {
            flash('error', '您已完成实名认证，无需重复提交');
            header('Location: /user/verify.php');
            exit;
        }
        $realname = trim(post('realname', ''));
        $idcard = trim(post('idcard', ''));
        if (mb_strlen($realname) < 2 || mb_strlen($realname) > 20) {
            flash('error', '请上传身份证正面完成身份识别');
            header('Location: /user/verify.php');
            exit;
        }
        if (!preg_match('/^\d{17}[\dXx]$/', $idcard)) {
            flash('error', '请上传身份证正面完成身份识别');
            header('Location: /user/verify.php');
            exit;
        }
        // 保存上传的身份证图片
        $front_img = '';
        $back_img = '';
        $upload_dir = __DIR__ . '/../upload/idcard/';
        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0755, true);
        }
        if (!empty($_FILES['front_file']['tmp_name']) && $_FILES['front_file']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['front_file']['name'], PATHINFO_EXTENSION);
            $ext = strtolower($ext ?: 'jpg');
            $front_img = 'upload/idcard/' . $uid . '_front_' . date('YmdHis') . '_' . mt_rand(1000, 9999) . '.' . $ext;
            @move_uploaded_file($_FILES['front_file']['tmp_name'], __DIR__ . '/../' . $front_img);
        }
        if (!empty($_FILES['back_file']['tmp_name']) && $_FILES['back_file']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['back_file']['name'], PATHINFO_EXTENSION);
            $ext = strtolower($ext ?: 'jpg');
            $back_img = 'upload/idcard/' . $uid . '_back_' . date('YmdHis') . '_' . mt_rand(1000, 9999) . '.' . $ext;
            @move_uploaded_file($_FILES['back_file']['tmp_name'], __DIR__ . '/../' . $back_img);
        }

        $result = idverify_api_query($realname, $idcard);
        if ($result['success'] && $result['match']) {
            $upd = $pdo->prepare("UPDATE users SET real_name = ?, id_card = ?, id_verify_status = 1, id_verify_time = NOW(), id_verify_orderid = ?, idcard_front_img = ?, idcard_back_img = ? WHERE id = ?");
            $upd->execute([$realname, substr($idcard, 0, 6) . '********' . substr($idcard, -4), $result['orderid'] ?? '', $front_img, $back_img, $uid]);
            flash('success', '实名认证成功！');
        } elseif ($result['success'] && !$result['match']) {
            $upd = $pdo->prepare("UPDATE users SET real_name = ?, id_card = ?, id_verify_status = 2, id_verify_time = NOW(), idcard_front_img = ?, idcard_back_img = ? WHERE id = ?");
            $upd->execute([$realname, '', $front_img, $back_img, $uid]);
            flash('error', $result['message']);
        } else {
            flash('error', '认证失败: ' . $result['message']);
        }
        header('Location: /user/verify.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>实名认证 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 13px; }
        .status-verified { background: rgba(34, 197, 94, 0.1); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.2); }
        .status-unverified { background: rgba(234, 179, 8, 0.1); color: #eab308; border: 1px solid rgba(234, 179, 8, 0.2); }
        .status-failed { background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2); }
        .id-card-display { font-family: monospace; letter-spacing: 1px; }

        .id-upload-area {
            border: 2px dashed var(--border, #d0d0d0);
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            background: var(--bg-secondary, #fafafa);
            position: relative;
            min-height: 160px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .id-upload-area:hover {
            border-color: var(--primary, #2563eb);
            background: rgba(37, 99, 235, 0.03);
        }
        .id-upload-area.has-image {
            border-style: solid;
            border-color: var(--primary, #2563eb);
            padding: 8px;
        }
        .id-upload-area.uploading {
            border-color: var(--primary, #2563eb);
            opacity: 0.7;
        }
        .id-upload-icon {
            font-size: 36px;
            margin-bottom: 8px;
            color: var(--text-muted, #999);
        }
        .id-upload-area.has-image .id-upload-icon { display: none; }
        .id-upload-text { font-size: 13px; color: var(--text-muted, #999); }
        .id-upload-label { font-size: 14px; font-weight: 600; margin-bottom: 4px; }
        .id-preview {
            max-width: 100%;
            max-height: 180px;
            border-radius: 8px;
            object-fit: contain;
        }
        .id-upload-area .remove-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            background: rgba(239,68,68,0.9);
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            font-size: 14px;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .id-upload-area.has-image .remove-btn { display: flex; }

        .ocr-result-box {
            background: var(--bg-secondary, #f8f9fa);
            border: 1px solid var(--border, #e5e5e5);
            border-radius: 8px;
            padding: 16px;
            margin-top: 16px;
        }
        .ocr-result-row {
            display: flex;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid var(--border, #eee);
        }
        .ocr-result-row:last-child { border-bottom: none; }
        .ocr-result-label {
            width: 80px;
            font-size: 13px;
            color: var(--text-muted, #999);
            flex-shrink: 0;
        }
        .ocr-result-value {
            flex: 1;
            font-size: 14px;
            font-weight: 500;
        }
        .ocr-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid var(--border, #ddd);
            border-top-color: var(--primary, #2563eb);
            border-radius: 50%;
            animation: ocr-spin 0.6s linear infinite;
            vertical-align: middle;
            margin-right: 6px;
        }
        @keyframes ocr-spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>

        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">实名认证</h1>
                    <p class="page-subtitle">上传身份证完成实名认证，保障账户安全</p>
                </div>
            </div>

            <?php if ($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

            <div class="card">
                <div class="card-title">当前认证状态</div>
                <div class="credentials-box">
                    <div class="credentials-row">
                        <span class="label">认证状态</span>
                        <span class="value">
                            <?php
                            $status = intval($user_info['id_verify_status'] ?? 0);
                            if ($status == 1) {
                                echo '<span class="status-badge status-verified">✓ 已认证</span>';
                            } elseif ($status == 2) {
                                echo '<span class="status-badge status-failed">✗ 认证失败</span>';
                            } else {
                                echo '<span class="status-badge status-unverified">● 未认证</span>';
                            }
                            ?>
                        </span>
                    </div>
                    <?php if (!empty($user_info['real_name'])): ?>
                    <div class="credentials-row">
                        <span class="label">真实姓名</span>
                        <span class="value"><?php echo e($user_info['real_name']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($user_info['id_card'])): ?>
                    <div class="credentials-row">
                        <span class="label">身份证号</span>
                        <span class="value id-card-display"><?php echo e($user_info['id_card']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($user_info['id_verify_time'])): ?>
                    <div class="credentials-row">
                        <span class="label">认证时间</span>
                        <span class="value"><?php echo e($user_info['id_verify_time']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($user_info['id_verify_status']) || $user_info['id_verify_status'] != 1): ?>
            <div class="card">
                <div class="card-title">上传身份证进行实名认证</div>
                <?php if (!$enabled): ?>
                <div class="alert alert-error">实名认证功能暂未启用，请联系管理员</div>
                <?php else: ?>
                <form method="POST" id="verifyForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="verify">
                    <input type="hidden" name="realname" id="inputRealname" value="">
                    <input type="hidden" name="idcard" id="inputIdcard" value="">

                    <div class="form-row" style="gap: 16px;">
                        <div class="form-group" style="flex:1;">
                            <label class="id-upload-label">身份证正面（人像面）</label>
                            <div class="id-upload-area" id="frontUploadArea" onclick="document.getElementById('frontFileInput').click()">
                                <button class="remove-btn" onclick="event.stopPropagation(); removeImage('front')">&times;</button>
                                <div class="id-upload-icon">📄</div>
                                <div class="id-upload-label">点击上传人像面</div>
                                <div class="id-upload-text">支持 JPG/PNG，最大10MB</div>
                                <img class="id-preview" id="frontPreview" style="display:none;">
                            </div>
                            <input type="file" name="front_file" id="frontFileInput" accept="image/jpeg,image/png,image/jpg,image/bmp,image/webp" style="display:none" onchange="handleFileSelect(this, 'front')">
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label class="id-upload-label">身份证反面（国徽面）</label>
                            <div class="id-upload-area" id="backUploadArea" onclick="document.getElementById('backFileInput').click()">
                                <button class="remove-btn" onclick="event.stopPropagation(); removeImage('back')">&times;</button>
                                <div class="id-upload-icon">📄</div>
                                <div class="id-upload-label">点击上传国徽面</div>
                                <div class="id-upload-text">支持 JPG/PNG，最大10MB</div>
                                <img class="id-preview" id="backPreview" style="display:none;">
                            </div>
                            <input type="file" name="back_file" id="backFileInput" accept="image/jpeg,image/png,image/jpg,image/bmp,image/webp" style="display:none" onchange="handleFileSelect(this, 'back')">
                        </div>
                    </div>

                    <div id="ocrStatus" style="margin-top: 12px; font-size: 13px; color: var(--text-muted, #999);"></div>

                    <div id="ocrResultBox" class="ocr-result-box" style="display:none;">
                        <div style="font-size: 14px; font-weight: 600; margin-bottom: 12px;">识别结果</div>
                        <div class="ocr-result-row">
                            <span class="ocr-result-label">姓名</span>
                            <span class="ocr-result-value" id="ocrName">-</span>
                        </div>
                        <div class="ocr-result-row">
                            <span class="ocr-result-label">身份证号</span>
                            <span class="ocr-result-value" id="ocrIdcard">-</span>
                        </div>
                        <div class="ocr-result-row">
                            <span class="ocr-result-label">性别</span>
                            <span class="ocr-result-value" id="ocrGender">-</span>
                        </div>
                        <div class="ocr-result-row">
                            <span class="ocr-result-label">民族</span>
                            <span class="ocr-result-value" id="ocrNation">-</span>
                        </div>
                        <div class="ocr-result-row">
                            <span class="ocr-result-label">住址</span>
                            <span class="ocr-result-value" id="ocrAddress">-</span>
                        </div>
                        <div class="ocr-result-row" id="ocrValidRow" style="display:none;">
                            <span class="ocr-result-label">有效期限</span>
                            <span class="ocr-result-value" id="ocrValid">-</span>
                        </div>
                    </div>

                    <div id="ocrError" class="alert alert-error" style="display:none; margin-top: 12px;"></div>

                    <div class="alert alert-error" style="margin-top: 16px;">
                        <strong>注意：</strong>请上传本人真实身份证，系统将自动识别信息并调用第三方接口核验。认证信息与身份证号码不匹配将认证失败。
                    </div>
                    <button type="submit" class="btn btn-primary" id="submitBtn" style="margin-top: 12px;" disabled>请先上传身份证正面</button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    let frontData = null;
    let backData = null;

    function handleFileSelect(input, side) {
        const file = input.files[0];
        if (!file) return;

        // 验证文件大小
        if (file.size > 10 * 1024 * 1024) {
            alert('图片大小不能超过10MB');
            input.value = '';
            return;
        }

        const area = document.getElementById(side + 'UploadArea');
        const preview = document.getElementById(side + 'Preview');

        // 显示预览
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
            area.classList.add('has-image');
            area.querySelector('.id-upload-label').style.display = 'none';
            area.querySelector('.id-upload-text').style.display = 'none';
        };
        reader.readAsDataURL(file);

        // 调用 OCR 识别
        callOCR(file, side);
    }

    function removeImage(side) {
        const area = document.getElementById(side + 'UploadArea');
        const preview = document.getElementById(side + 'Preview');
        const input = document.getElementById(side + 'FileInput');

        preview.src = '';
        preview.style.display = 'none';
        area.classList.remove('has-image');
        area.querySelector('.id-upload-label').style.display = '';
        area.querySelector('.id-upload-text').style.display = '';
        input.value = '';

        if (side === 'front') {
            frontData = null;
            document.getElementById('inputRealname').value = '';
            document.getElementById('inputIdcard').value = '';
            updateSubmitButton();
        } else {
            backData = null;
        }
    }

    async function callOCR(file, side) {
        const statusEl = document.getElementById('ocrStatus');
        const errorEl = document.getElementById('ocrError');
        const area = document.getElementById(side + 'UploadArea');

        area.classList.add('uploading');
        statusEl.innerHTML = '<span class="ocr-spinner"></span>正在识别' + (side === 'front' ? '正面' : '反面') + '信息...';
        errorEl.style.display = 'none';

        const formData = new FormData();
        formData.append('file', file);

        // 设置前端超时控制，避免长时间阻塞界面
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 20000);

        try {
            const resp = await fetch('/api/ocr_idcard.php', {
                method: 'POST',
                body: formData,
                signal: controller.signal
            });
            clearTimeout(timeoutId);

            let result;
            try {
                result = await resp.json();
            } catch (e) {
                throw new Error('OCR服务返回了非JSON响应，请检查OCR服务是否正常运行');
            }

            if (!result.success) {
                errorEl.textContent = result.message || 'OCR识别失败';
                errorEl.style.display = 'block';
                statusEl.textContent = '识别失败';
                area.classList.remove('uploading');
                if (side === 'front') {
                    frontData = null;
                    document.getElementById('inputRealname').value = '';
                    document.getElementById('inputIdcard').value = '';
                    updateSubmitButton();
                }
                return;
            }

            // 显示识别结果
            const data = result.data;
            const resultBox = document.getElementById('ocrResultBox');
            resultBox.style.display = 'block';

            if (side === 'front' && result.side === 'front') {
                frontData = data;
                document.getElementById('ocrName').textContent = data.realname || '-';
                document.getElementById('ocrIdcard').textContent = data.idcard ? (data.idcard.substring(0,4) + '**********' + data.idcard.substring(14)) : '-';
                document.getElementById('ocrGender').textContent = data.gender || '-';
                document.getElementById('ocrNation').textContent = data.nation || '-';
                document.getElementById('ocrAddress').textContent = data.address || '-';

                document.getElementById('inputRealname').value = data.realname || '';
                document.getElementById('inputIdcard').value = data.idcard || '';

                if (!data.realname || !data.idcard) {
                    errorEl.textContent = '未能完整识别姓名或身份证号，请重新拍摄清晰照片上传';
                    errorEl.style.display = 'block';
                }
            } else if (side === 'back' && result.side === 'back') {
                backData = data;
                document.getElementById('ocrValid').textContent = data.valid_date || '-';
                document.getElementById('ocrValidRow').style.display = '';
                if (data.authority) {
                    document.getElementById('ocrAddress').textContent = (frontData?.address || '-') + ' (签发: ' + data.authority + ')';
                }
            } else {
                // 上传了正面但识别为反面，或反之
                const wrongSide = side === 'front' ? '反面' : '正面';
                errorEl.textContent = '检测到您上传的可能是身份证' + wrongSide + '，请确认后重新上传';
                errorEl.style.display = 'block';
            }

            const speedTime = result.speed_time ? (' (耗时 ' + result.speed_time.toFixed(1) + 's)') : '';
            statusEl.innerHTML = '✓ ' + (side === 'front' ? '正面' : '反面') + '识别完成' + speedTime;

        } catch (err) {
            if (err.name === 'AbortError') {
                errorEl.textContent = 'OCR识别超时，请稍后重试或检查OCR服务状态';
            } else {
                errorEl.textContent = 'OCR识别请求失败: ' + err.message;
            }
            errorEl.style.display = 'block';
            statusEl.textContent = '识别失败';
        } finally {
            clearTimeout(timeoutId);
            area.classList.remove('uploading');
            updateSubmitButton();
        }
    }

    function updateSubmitButton() {
        const btn = document.getElementById('submitBtn');
        const name = document.getElementById('inputRealname').value;
        const idcard = document.getElementById('inputIdcard').value;

        if (name && idcard && /^\d{17}[\dXx]$/.test(idcard)) {
            btn.disabled = false;
            btn.textContent = '提交实名认证';
        } else {
            btn.disabled = true;
            btn.textContent = '请先上传身份证正面完成识别';
        }
    }
    </script>
</body>
</html>
