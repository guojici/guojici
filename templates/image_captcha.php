<!-- =============================================
     图片选择验证码组件 - 弹窗版
     用户需要点击包含特定图形的图片
     ============================================= -->

<!-- 验证弹窗 -->
<div id="captcha-modal" class="captcha-modal" style="display:none;">
    <div class="captcha-modal-overlay" onclick="CaptchaModal.close()"></div>
    <div class="captcha-modal-box" style="width: 420px;">
        <div class="captcha-modal-header">
            <span class="captcha-modal-icon">🛡️</span>
            <span class="captcha-modal-title">安全验证</span>
            <button type="button" class="captcha-modal-close" onclick="CaptchaModal.close()">✕</button>
        </div>

        <div class="captcha-modal-body">
            <!-- 加载状态 -->
            <div id="imgcap-loading" class="sc-loading" style="display:none;">
                <div class="sc-spinner"></div>加载验证码...
            </div>
            
            <!-- 验证码内容 -->
            <div id="imgcap-content" style="display:none;">
                <div id="imgcap-question" class="imgcap-question"></div>
                <div id="imgcap-grid" class="imgcap-grid"></div>
                <div id="imgcap-tip" class="imgcap-tip">已选择 <span id="imgcap-count">0</span> 张图片</div>
            </div>
            
            <!-- 状态提示 -->
            <div id="imgcap-status" class="sc-status" style="display:none;"></div>
        </div>

        <div class="captcha-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="ImageCaptcha.reset()">🔄 刷新</button>
            <button type="button" class="btn btn-primary" id="imgcap-confirm" onclick="ImageCaptcha.confirm()" disabled>确认验证</button>
        </div>
    </div>
</div>

<style>
/* ===== 图片选择验证码样式 ===== */
.imgcap-question {
    font-size: 16px;
    font-weight: 600;
    color: #1e293b;
    text-align: center;
    padding: 12px 0;
    background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
    border-radius: 8px;
    margin-bottom: 12px;
}

.imgcap-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    padding: 8px;
    background: #f8fafc;
    border-radius: 12px;
}

.imgcap-cell {
    position: relative;
    aspect-ratio: 1;
    background: #fff;
    border-radius: 8px;
    overflow: hidden;
    cursor: pointer;
    border: 2px solid transparent;
    transition: all 0.2s;
}

.imgcap-cell:hover {
    transform: scale(1.02);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.imgcap-cell.selected {
    border-color: #1677ff;
    background: #e6f4ff;
}

.imgcap-cell.selected::after {
    content: '✓';
    position: absolute;
    top: 4px;
    right: 4px;
    width: 20px;
    height: 20px;
    background: #1677ff;
    color: #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: bold;
}

.imgcap-cell.correct {
    border-color: #22c55e;
    background: #f0fdf4;
}

.imgcap-cell.correct::after {
    content: '✓';
    background: #22c55e;
}

.imgcap-cell.wrong {
    border-color: #ef4444;
    background: #fef2f2;
}

.imgcap-cell.wrong::after {
    content: '✕';
    background: #ef4444;
}

.imgcap-cell img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.imgcap-tip {
    text-align: center;
    font-size: 13px;
    color: #64748b;
    margin-top: 8px;
}

.imgcap-success-icon {
    font-size: 64px;
    text-align: center;
    padding: 20px 0;
}

.sc-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 200px;
    color: #94a3b8;
    font-size: 14px;
    gap: 8px;
}

.sc-spinner {
    width: 20px;
    height: 20px;
    border: 2px solid #e2e8f0;
    border-top-color: #1677ff;
    border-radius: 50%;
    animation: scSpin 0.8s linear infinite;
}

@keyframes scSpin { to { transform: rotate(360deg); } }
</style>

<script>
(function() {
    var captchaToken = '';
    var selectedImages = [];
    var isVerified = false;
    var formToSubmit = null;

    var $ = function(id) { return document.getElementById(id); };

    window.ImageCaptcha = {
        // 打开弹窗
        open: function(formEl) {
            formToSubmit = formEl;
            isVerified = false;
            selectedImages = [];
            $('captcha-modal').style.display = 'flex';
            ImageCaptcha.reset();
        },

        close: function() {
            $('captcha-modal').style.display = 'none';
        },

        reset: function() {
            captchaToken = '';
            selectedImages = [];
            isVerified = false;
            
            var confirmBtn = $('imgcap-confirm');
            if (confirmBtn) { 
                confirmBtn.disabled = true; 
                confirmBtn.textContent = '确认验证';
            }
            
            $('imgcap-loading').style.display = 'flex';
            $('imgcap-content').style.display = 'none';
            $('imgcap-status').style.display = 'none';

            // 加载新验证码
            ImageCaptcha.loadCaptcha();
        },

        loadCaptcha: function() {
            fetch('/api/captcha.php?action=generate_img')
                .then(function(r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function(data) {
                    $('imgcap-loading').style.display = 'none';
                    
                    if (data.success) {
                        captchaToken = data.token;
                        $('imgcap-question').innerHTML = data.question;
                        ImageCaptcha.renderGrid(data.images);
                        $('imgcap-content').style.display = 'block';
                    } else {
                        ImageCaptcha.showStatus(data.message || '加载失败', 'error');
                    }
                })
                .catch(function(err) {
                    $('imgcap-loading').style.display = 'none';
                    ImageCaptcha.showStatus('加载失败: ' + (err.message || '未知错误'), 'error');
                });
        },

        renderGrid: function(images) {
            var grid = $('imgcap-grid');
            grid.innerHTML = '';
            
            images.forEach(function(img, index) {
                var cell = document.createElement('div');
                cell.className = 'imgcap-cell';
                cell.dataset.index = img.id;
                cell.innerHTML = '<img src="' + img.image + '" alt="">';
                cell.onclick = function() { ImageCaptcha.toggleSelect(img.id, this); };
                grid.appendChild(cell);
            });
            
            selectedImages = [];
            $('imgcap-count').textContent = '0';
        },

        toggleSelect: function(id, cell) {
            if (isVerified) return;
            
            var index = selectedImages.indexOf(id);
            if (index > -1) {
                selectedImages.splice(index, 1);
                cell.classList.remove('selected');
            } else {
                selectedImages.push(id);
                cell.classList.add('selected');
            }
            
            $('imgcap-count').textContent = selectedImages.length;
            
            // 有选择时启用确认按钮
            var confirmBtn = $('imgcap-confirm');
            if (confirmBtn) {
                confirmBtn.disabled = selectedImages.length === 0;
            }
        },

        confirm: function() {
            if (selectedImages.length === 0) {
                ImageCaptcha.showStatus('请先选择图片', 'error');
                return;
            }

            var confirmBtn = $('imgcap-confirm');
            confirmBtn.disabled = true;
            confirmBtn.textContent = '验证中...';

            var formData = new FormData();
            formData.append('token', captchaToken);
            formData.append('selected', JSON.stringify(selectedImages));

            fetch('/api/captcha.php?action=verify_img', {
                method: 'POST',
                body: formData,
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.verified) {
                    isVerified = true;
                    ImageCaptcha.showSuccess();
                } else {
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = '确认验证';
                    ImageCaptcha.showStatus('验证失败，请重试', 'error');
                    
                    // 显示正确/错误的图片
                    if (data.correct) {
                        ImageCaptcha.showResults(data.correct, data.selected || []);
                    }
                    
                    setTimeout(function() { ImageCaptcha.reset(); }, 1500);
                }
            })
            .catch(function(err) {
                confirmBtn.disabled = false;
                confirmBtn.textContent = '确认验证';
                ImageCaptcha.showStatus('网络错误，请重试', 'error');
            });
        },

        showSuccess: function() {
            var grid = $('imgcap-grid');
            var cells = grid.querySelectorAll('.imgcap-cell');
            cells.forEach(function(cell) {
                cell.classList.add('correct');
                cell.classList.remove('selected');
            });
            
            ImageCaptcha.showStatus('✓ 验证成功！', 'success');
            
            setTimeout(function() {
                ImageCaptcha.close();
                if (formToSubmit) {
                    var tokenInput = formToSubmit.querySelector('[name=captcha_token]');
                    if (tokenInput) tokenInput.value = captchaToken;
                    formToSubmit.submit();
                }
            }, 800);
        },

        showResults: function(correct, selected) {
            var grid = $('imgcap-grid');
            var cells = grid.querySelectorAll('.imgcap-cell');
            cells.forEach(function(cell) {
                var id = parseInt(cell.dataset.index);
                if (correct.indexOf(id) > -1) {
                    cell.classList.add('correct');
                } else if (selected.indexOf(id) > -1) {
                    cell.classList.add('wrong');
                }
            });
        },

        showStatus: function(msg, type) {
            var el = $('imgcap-status');
            if (!el) return;
            el.textContent = msg;
            el.className = 'sc-status ' + type;
            el.style.display = 'block';
        }
    };
})();
</script>
