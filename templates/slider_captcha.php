<!-- =============================================
     自研滑块验证码组件 - 弹窗版
     点击登录/注册后弹出弹窗进行验证
     ============================================= -->

<!-- 验证弹窗 -->
<div id="captcha-modal" class="captcha-modal" style="display:none;">
    <div class="captcha-modal-overlay" onclick="CaptchaModal.close()"></div>
    <div class="captcha-modal-box">
        <div class="captcha-modal-header">
            <span class="captcha-modal-icon">🛡️</span>
            <span class="captcha-modal-title">安全验证</span>
            <button type="button" class="captcha-modal-close" onclick="CaptchaModal.close()">✕</button>
        </div>

        <div class="captcha-modal-body">
            <div class="sc-bg-wrap" id="scBgWrap">
                <!-- 验证码图片区域 -->
            </div>
            <div class="sc-track" id="scTrack">
                <div class="sc-track-bg" id="scTrackBg"></div>
                <div class="sc-track-btn" id="scTrackBtn">➤</div>
                <div class="sc-track-text" id="scTrackText">拖动滑块完成拼图</div>
            </div>
            <div class="sc-status" id="scStatus" style="display:none;"></div>
        </div>

        <div class="captcha-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="CaptchaModal.reset()">🔄 刷新</button>
            <button type="button" class="btn btn-primary" id="captchaConfirmBtn" onclick="CaptchaModal.confirm()" disabled>确认验证</button>
        </div>
    </div>
</div>

<style>
/* ===== 验证弹窗 ===== */
.captcha-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
}
.captcha-modal-overlay {
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5);
}
.captcha-modal-box {
    position: relative;
    background: #fff;
    border-radius: 12px;
    width: 380px;
    max-width: 95vw;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    overflow: hidden;
}
.captcha-modal-header {
    display: flex;
    align-items: center;
    padding: 14px 16px;
    background: linear-gradient(135deg, #f8fbff, #f0f5ff);
    border-bottom: 1px solid #e2e8f0;
    gap: 8px;
}
.captcha-modal-icon { font-size: 18px; }
.captcha-modal-title { flex: 1; font-size: 14px; font-weight: 600; color: #1e293b; }
.captcha-modal-close {
    width: 28px; height: 28px; border-radius: 6px;
    background: #fff; border: 1px solid #d1d9e6; cursor: pointer;
    font-size: 14px; color: #64748b;
    display: flex; align-items: center; justify-content: center;
    transition: all 0.2s;
}
.captcha-modal-close:hover { background: #f1f5f9; color: #ef4444; border-color: #ef4444; }
.captcha-modal-body { padding: 16px; }
.captcha-modal-footer {
    display: flex; gap: 10px; padding: 12px 16px;
    border-top: 1px solid #e2e8f0;
    justify-content: flex-end;
}

/* ===== 验证码样式（尺寸与PHP生成的340×200一致，确保坐标不偏移） ===== */
.sc-bg-wrap {
    position: relative;
    width: 340px;        /* 与 PHP 画布一致 */
    height: 200px;       /* 与 PHP 画布一致 */
    border-radius: 8px;
    overflow: hidden;
    background: #e8eeff;
    user-select: none;
    margin: 0 auto;
}
.sc-bg-img {
    width: 340px;        /* 不缩放，1:1 对应坐标 */
    height: 200px;
    display: block;
}
.sc-slider-block {
    position: absolute;
    top: 0;              /* 由 JS 内联 top 覆盖 */
    left: 0;
    width: 50px;
    height: 50px;
    cursor: grab;
    z-index: 10;
    border-radius: 4px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    transition: box-shadow 0.2s;
}
.sc-slider-block:active { cursor: grabbing; }
.sc-slider-block.success { box-shadow: 0 0 0 3px rgba(34,197,94,0.5), 0 4px 12px rgba(34,197,94,0.3); }
.sc-slider-block.error {
    box-shadow: 0 0 0 3px rgba(239,68,68,0.5), 0 4px 12px rgba(239,68,68,0.3);
    animation: scShake 0.4s ease;
}
@keyframes scShake {
    0%, 100% { transform: translateX(0); }
    20% { transform: translateX(-6px); }
    40% { transform: translateX(6px); }
    60% { transform: translateX(-4px); }
    80% { transform: translateX(4px); }
}
.sc-slider-img { width: 50px; height: 50px; display: block; pointer-events: none; }

/* 轨道 */
.sc-track {
    position: relative; height: 40px; margin-top: 12px;
    border-radius: 20px; background: #f1f5f9;
    border: 1px solid #d1d9e6; overflow: hidden;
}
.sc-track-bg {
    position: absolute; top: 0; left: 0; height: 100%;
    width: 0; background: linear-gradient(90deg, #e6f0ff, #d4e4ff);
    border-radius: 20px; transition: width 0.05s linear;
}
.sc-track-btn {
    position: absolute; top: 4px; left: 4px;
    width: 32px; height: 32px; background: #fff;
    border: 1px solid #d1d9e6; border-radius: 50%;
    cursor: grab; display: flex; align-items: center; justify-content: center;
    font-size: 12px; color: #64748b; z-index: 2;
    transition: transform 0.1s, box-shadow 0.2s; user-select: none;
}
.sc-track-btn:hover { box-shadow: 0 0 0 3px rgba(22,119,255,0.15); color: #1677ff; }
.sc-track-btn.dragging { cursor: grabbing; transform: scale(1.1); box-shadow: 0 2px 8px rgba(22,119,255,0.25); }
.sc-track-text {
    position: absolute; top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    font-size: 12px; color: #94a3b8; pointer-events: none; white-space: nowrap;
}
.sc-track.success .sc-track-bg { background: linear-gradient(90deg, #dcfce7, #bbf7d0); border-color: #22c55e; }
.sc-track.success .sc-track-btn { background: #22c55e; border-color: #22c55e; color: #fff; }
.sc-track.success .sc-track-text { color: #22c55e; font-weight: 600; }
.sc-track.error .sc-track-bg { background: linear-gradient(90deg, #fee2e2, #fecaca); border-color: #ef4444; }
.sc-track.error .sc-track-btn { background: #ef4444; border-color: #ef4444; color: #fff; }
.sc-track.error .sc-track-text { color: #ef4444; }

/* 状态 */
.sc-status {
    padding: 8px 16px; font-size: 12px; text-align: center; font-weight: 500;
    border-radius: 0 0 8px 8px; margin-top: -8px;
}
.sc-status.success { color: #22c55e; background: #f0fdf4; }
.sc-status.error { color: #ef4444; background: #fef2f2; }
.sc-loading {
    display: flex; align-items: center; justify-content: center;
    height: 180px; color: #94a3b8; font-size: 13px; gap: 8px;
}
.sc-spinner {
    width: 20px; height: 20px; border: 2px solid #e2e8f0;
    border-top-color: #1677ff; border-radius: 50%;
    animation: scSpin 0.8s linear infinite;
}
@keyframes scSpin { to { transform: rotate(360deg); } }
</style>

<script>
(function() {
    var captchaToken = '';
    var correctX = 0;
    var isVerified = false;
    var isDragging = false;
    var startX = 0;
    var startY = 0; // 记录起始鼠标Y坐标
    var maxSlide = 290;
    var formToSubmit = null;

    // DOM 元素缓存
    var $ = function(id) { return document.getElementById(id); };

    window.CaptchaModal = {
        // 打开弹窗，formEl 是需要提交的表单
        open: function(formEl) {
            formToSubmit = formEl;
            isVerified = false;
            $('captcha-modal').style.display = 'flex';
            CaptchaModal.reset();
        },

        close: function() {
            $('captcha-modal').style.display = 'none';
        },

        reset: function() {
            captchaToken = '';
            isVerified = false;
            var confirmBtn = $('captchaConfirmBtn');
            if (confirmBtn) { confirmBtn.disabled = true; confirmBtn.textContent = '确认验证'; }

            var trackWrap = $('scTrack');
            if (trackWrap) trackWrap.className = 'sc-track';

            var trackBtn = $('scTrackBtn');
            if (trackBtn) { trackBtn.style.left = '4px'; trackBtn.className = 'sc-track-btn'; }

            var trackBg = $('scTrackBg');
            if (trackBg) trackBg.style.width = '0px';

            var trackText = $('scTrackText');
            if (trackText) { trackText.textContent = '拖动滑块完成拼图'; trackText.style.color = ''; }

            var statusEl = $('scStatus');
            if (statusEl) { statusEl.style.display = 'none'; }

            showLoading(true);

            fetch('/api/captcha.php?action=generate')
                .then(function(r) {
                    var ct = r.headers.get('content-type') || '';
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    if (ct.indexOf('application/json') === -1) throw new Error('非JSON响应');
                    return r.json();
                })
                .then(function(data) {
                    showLoading(false);
                    if (data.success) {
                        captchaToken = data.token;
                        correctX = parseInt(data.x);
                        // 滑块的 y 坐标与背景缺口对齐（容器固定340×200，PHP图也是340×200，坐标1:1）
                        var sliderY = parseInt(data.y) || 0;
                        var bgW = 340;
                        var bgH = 200;
                        var blockSize = 50;
                        // 最大可滑动距离 = 容器宽 - 滑块块宽
                        maxSlide = bgW - blockSize;
                        // 渲染：背景图 340×200，滑块块绝对定位到 (0, sliderY)，用户拖动改变 left
                        $('scBgWrap').innerHTML =
                            '<img id="scBgImg" src="' + data.bg + '" class="sc-bg-img" alt="" style="width:' + bgW + 'px;height:' + bgH + 'px;display:block;">' +
                            '<div id="scSliderBlock" style="position:absolute;left:0px;top:' + sliderY + 'px;width:' + blockSize + 'px;height:' + blockSize + 'px;cursor:grab;z-index:10;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,0.2);">' +
                            '<img id="scSliderImg" src="' + data.slider + '" style="width:' + blockSize + 'px;height:' + blockSize + 'px;display:block;" alt="">' +
                            '</div>';
                        bindTrack();
                    } else {
                        showStatus(data.message || '加载失败', 'error');
                    }
                })
                .catch(function(err) {
                    showLoading(false);
                    showStatus('加载失败: ' + (err.message || '未知错误'), 'error');
                });
        },

        confirm: function() {
            if (!isVerified) {
                showStatus('请先完成滑块验证', 'error');
                return;
            }
            // 验证通过，关闭弹窗，提交原表单
            CaptchaModal.close();
            if (formToSubmit) {
                // 把验证标记写入表单
                var tokenInput = formToSubmit.querySelector('[name=captcha_token]');
                if (tokenInput) tokenInput.value = captchaToken;
                var xInput = formToSubmit.querySelector('[name=captcha_x]');
                if (xInput) xInput.value = correctX;
                formToSubmit.submit();
            }
        }
    };

    function showLoading(show) {
        if (show) {
            $('scBgWrap').innerHTML = '<div class="sc-loading"><div class="sc-spinner"></div>加载验证码...</div>';
        }
    }

    function showStatus(msg, type) {
        var el = $('scStatus');
        if (!el) return;
        el.textContent = msg;
        el.className = 'sc-status ' + type;
        el.style.display = 'block';
    }

    function resetSlider() {
        var block = $('scSliderBlock');
        var track = $('scTrack');
        var trackBtn = $('scTrackBtn');
        var trackBg = $('scTrackBg');
        var trackText = $('scTrackText');
        // 重置X，Y保持与背景缺口对齐
        if (block) {
            block.style.left = '0px';
            block.className = 'sc-slider-block';
        }
        if (track) track.className = 'sc-track';
        if (trackBtn) { trackBtn.style.left = '4px'; trackBtn.className = 'sc-track-btn'; }
        if (trackBg) trackBg.style.width = '0px';
        if (trackText) { trackText.textContent = '拖动滑块完成拼图'; trackText.style.color = ''; }
        showStatus('', '');
    }

    function bindTrack() {
        var track = $('scTrack');
        if (!track) return;
        // 移除旧事件（防止重复绑定）
        track.onmousedown = null;
        document.onmousemove = null;
        document.onmouseup = null;
        track.ontouchstart = null;
        document.ontouchmove = null;
        document.ontouchend = null;

        track.onmousedown = onDragStart;
        document.onmousemove = onDragMove;
        document.onmouseup = onDragEnd;

        track.ontouchstart = function(e) { e.preventDefault(); onDragStart(e.touches[0]); };
        document.ontouchmove = function(e) { if (isDragging) { e.preventDefault(); onDragMove(e.touches[0]); } };
        document.ontouchend = onDragEnd;
    }

    // 拖动轨迹记录 - 用于真人行为分析
    var dragTrajectory = [];
    var dragStartTime = 0;

    function onDragStart(e) {
        if (isVerified) return;
        var block = $('scSliderBlock');
        if (!block) return;
        if (block.classList.contains('error')) {
            CaptchaModal.reset();
            return;
        }
        isDragging = true;
        startX = e.clientX;
        startY = e.clientY || 0;
        // 重置轨迹记录
        dragTrajectory = [];
        dragStartTime = Date.now();
        // 记录起点
        var left0 = parseInt(block.style.left) || 0;
        dragTrajectory.push({ t: 0, x: left0, y: 0, _abs: dragStartTime });
        var trackBtn = $('scTrackBtn');
        var trackText = $('scTrackText');
        if (trackBtn) trackBtn.classList.add('dragging');
        if (trackText) trackText.textContent = '请继续拖动...';
    }

    function onDragMove(e) {
        if (!isDragging) return;
        var block = $('scSliderBlock');
        var trackBtn = $('scTrackBtn');
        var trackBg = $('scTrackBg');
        if (!block || !trackBtn || !trackBg) return;

        var dx = e.clientX - startX;
        var left = Math.max(0, Math.min(dx, maxSlide));
        block.style.left = left + 'px';
        trackBtn.style.left = (left + 4) + 'px';
        trackBg.style.width = left + 'px';

        // 每隔 ~15ms 记录一次轨迹点（含真实Y抖动）
        var now = Date.now();
        var last = dragTrajectory[dragTrajectory.length - 1];
        if (!last || now - (last._abs || 0) > 15) {
            // y: 记录鼠标 Y 坐标相对于起始Y的真实偏移（反映真人手抖动）
            var dy = 0;
            if (typeof e.clientY === 'number') {
                dy = e.clientY - startY;
            }
            dragTrajectory.push({
                t: now - dragStartTime,
                x: left,
                y: dy,
                _abs: now
            });
        }
    }

    function onDragEnd() {
        if (!isDragging) return;
        isDragging = false;
        var trackBtn = $('scTrackBtn');
        if (trackBtn) trackBtn.classList.remove('dragging');

        var block = $('scSliderBlock');
        var finalX = block ? (parseInt(block.style.left) || 0) : 0;

        // 补一个终点
        dragTrajectory.push({ t: Date.now() - dragStartTime, x: finalX, y: 0 });

        if (finalX < 5) {
            resetSlider();
            return;
        }

        verifySlider(finalX, dragTrajectory);
    }

    function verifySlider(inputX, trajectory) {
        if (!captchaToken) {
            showStatus('验证已过期，请刷新', 'error');
            return;
        }
        var trackText = $('scTrackText');
        if (trackText) trackText.textContent = '验证中...';

        // 轨迹：[{t:毫秒,x:像素,y:像素}]
        var trajJson = JSON.stringify(trajectory || []);

        var formData = new FormData();
        formData.append('token', captchaToken);
        formData.append('x', inputX);
        formData.append('y', '0');
        formData.append('trajectory', trajJson);

        fetch('/api/captcha.php?action=verify', {
            method: 'POST',
            body: formData,
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.verified) {
                console.log('验证失败 - x:' + inputX + ' 正确x:' + correctX + ' 轨迹点数:' + (trajectory ? trajectory.length : 0));
            }
            if (data.verified) {
                isVerified = true;
                smoothSlide(parseInt(correctX));
                setTimeout(function() {
                    var block = $('scSliderBlock');
                    var track = $('scTrack');
                    var trackText = $('scTrackText');
                    var confirmBtn = $('captchaConfirmBtn');
                    if (block) block.classList.add('success');
                    if (track) track.classList.add('success');
                    if (trackText) trackText.textContent = '✓ 验证成功';
                    showStatus('验证成功！', 'success');
                    if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.textContent = '确认登录 →'; }
                }, 400);
            } else {
                var block = $('scSliderBlock');
                var track = $('scTrack');
                var trackText = $('scTrackText');
                if (block) block.classList.add('error');
                if (track) track.classList.add('error');
                if (trackText) trackText.textContent = '✗ 验证失败';
                var reason = data.reason || ('差' + Math.abs(inputX - correctX) + 'px');
                showStatus('验证失败，请重试（' + reason + '）', 'error');
                setTimeout(function() { CaptchaModal.reset(); }, 1200);
            }
        })
        .catch(function(err) {
            console.error('验证请求错误:', err);
            showStatus('网络错误，请重试', 'error');
        });
    }

    function smoothSlide(targetX) {
        var block = $('scSliderBlock');
        var trackBtn = $('scTrackBtn');
        var trackBg = $('scTrackBg');
        if (!block || !trackBtn || !trackBg) return;
        var current = parseInt(block.style.left) || 0;
        // 记录滑块当前Y（渲染时已设为与背景缺口对齐，动画全程保持）
        var sliderY = parseInt(block.style.top) || 0;
        var step = (targetX - current) / 8;
        var i = 0;
        var timer = setInterval(function() {
            i++;
            current += step;
            if (i >= 8) { current = targetX; clearInterval(timer); }
            block.style.left = current + 'px';
            block.style.top = sliderY + 'px'; // 动画全程保持Y对齐
            trackBtn.style.left = (current + 4) + 'px';
            trackBg.style.width = current + 'px';
        }, 30);
    }
})();
</script>
