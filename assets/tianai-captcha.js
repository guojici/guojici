/**
 * Tianai Captcha v1.5.5 - 前端 SDK
 * 协议参照：cloud.tianai.captcha.slider
 *
 * 使用方式:
 *   var tac = window.TianaiCaptcha.init({
 *       requestCaptchaDataUrl: '/api/captcha_tianai.php?action=gen',
 *       validCaptchaUrl: '/api/captcha_tianai.php?action=check',
 *       onSuccess: function(res) {
 *           // res.data.token / res.data.captcha_id
 *           tac.destroy();
 *       },
 *       onFail: function(res) {
 *           // 失败后会自动刷新
 *       }
 *   });
 */

(function (global) {
    'use strict';

    // ============= 样式注入 =============
    var cssText = ''
        + '.tac-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.45);z-index:99998;display:flex;align-items:center;justify-content:center;}'
        + '.tac-wrap{background:#fff;border-radius:8px;padding:18px 24px 14px;width:400px;box-sizing:border-box;box-shadow:0 6px 30px rgba(0,0,0,0.2);font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;user-select:none;}'
        + '.tac-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;padding-right:2px;}'
        + '.tac-title{font-size:14px;color:#333;font-weight:600;}'
        + '.tac-actions{display:flex;gap:10px;}'
        + '.tac-refresh,.tac-close{cursor:pointer;color:#888;font-size:14px;padding:2px 4px;}'
        + '.tac-refresh:hover,.tac-close:hover{color:#1890ff;}'
        + '.tac-img-box{position:relative;width:340px;height:200px;border-radius:6px;overflow:hidden;}'
        + '.tac-bg-img{width:340px;height:200px;display:block;pointer-events:none;}'
        + '.tac-slider-img{position:absolute;left:0;top:0;pointer-events:none;user-select:none;transition:none;}'
        + '.tac-tips{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);padding:6px 16px;border-radius:16px;font-size:13px;pointer-events:none;z-index:10;}'
        + '.tac-tips.success{background:rgba(82,196,26,0.92);color:#fff;}'
        + '.tac-tips.error{background:rgba(245,34,45,0.92);color:#fff;}'
        + '.tac-loading{position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,0.85);display:flex;align-items:center;justify-content:center;font-size:13px;color:#666;z-index:20;border-radius:6px;}'
        + '.tac-loading::before{content:"";width:18px;height:18px;margin-right:8px;border:2px solid #e0e0e0;border-top-color:#1890ff;border-radius:50%;animation:tacSpin 0.8s linear infinite;}'
        + '@keyframes tacSpin{to{transform:rotate(360deg);}}'
        + '.tac-slider-bar{position:relative;height:38px;margin:14px 0 8px;background:#f5f5f5;border:1px solid #eee;border-radius:6px;}'
        + '.tac-slider-mask{position:absolute;top:0;left:0;height:100%;background:#d1f0ff;border:1px solid #7cc8ff;border-radius:6px;box-sizing:border-box;width:0;}'
        + '.tac-slider-text{position:relative;text-align:center;line-height:38px;font-size:13px;color:#999;}'
        + '.tac-slider-btn{position:absolute;top:-1px;left:0;width:40px;height:38px;background:#fff;border:1px solid #ddd;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.15);cursor:grab;display:flex;align-items:center;justify-content:center;font-size:18px;color:#333;transition:background 0.15s;border-color:0.15s;z-index:2;}'
        + '.tac-slider-btn:hover{background:#1890ff;color:#fff;border-color:#1890ff;}'
        + '.tac-slider-btn.dragging{cursor:grabbing;background:#1890ff;color:#fff;border-color:#1890ff;}'
        + '.tac-slider-btn.success{background:#52c41a !important;border-color:#52c41a !important;color:#fff;}'
        + '.tac-slider-btn.error{background:#f5222d !important;border-color:#f5222d !important;color:#fff;}'
        ;

    function injectCss() {
        if (document.getElementById('tac-inline-style')) return;
        var style = document.createElement('style');
        style.id = 'tac-inline-style';
        style.type = 'text/css';
        if (style.styleSheet) {
            style.styleSheet.cssText = cssText;
        } else {
            style.appendChild(document.createTextNode(cssText));
        }
        document.getElementsByTagName('head')[0].appendChild(style);
    }

    // ============= 核心类 =============
    function Captcha(opts) {
        this.opts = opts || {};
        this.bgWidth = parseInt(opts.bgWidth || 340);
        this.bgHeight = parseInt(opts.bgHeight || 200);
        this.tplSize = parseInt(opts.tplSize || 55);
        this.genUrl = opts.requestCaptchaDataUrl || opts.genUrl || '/captcha/gen';
        this.checkUrl = opts.validCaptchaUrl || opts.checkUrl || '/captcha/check';

        this.state = {
            id: '',
            captchaData: null,
            yOffset: 0,
            trackList: [],
            startTime: 0,
            startX: 0,
            startY: 0,
            moveX: 0,
            dragging: false,
        };

        this._boundDown = null;
        this._boundMove = null;
        this._boundUp = null;
    }

    Captcha.prototype.init = function () {
        injectCss();
        this.render();
        this.loadCaptcha();
    };

    Captcha.prototype.render = function () {
        var self = this;
        // 创建遮罩和容器
        var overlay = document.createElement('div');
        overlay.className = 'tac-overlay';
        overlay.innerHTML = ''
            + '<div class="tac-wrap">'
            + '  <div class="tac-header">'
            + '    <div class="tac-title">请完成安全验证</div>'
            + '    <div class="tac-actions">'
            + '      <span class="tac-refresh" id="tacRefresh" title="换一张">↻</span>'
            + '      <span class="tac-close" id="tacClose" title="关闭">✕</span>'
            + '    </div>'
            + '  </div>'
            + '  <div class="tac-img-box">'
            + '    <img class="tac-bg-img" id="tacBg" alt="bg"/>'
            + '    <img class="tac-slider-img" id="tacSlider" alt="slider" style="height:' + this.tplSize + 'px;"/>'
            + '    <div class="tac-tips" id="tacTips" style="display:none;"></div>'
            + '    <div class="tac-loading" id="tacLoading">加载中...</div>'
            + '  </div>'
            + '  <div class="tac-slider-bar">'
            + '    <div class="tac-slider-mask" id="tacMask"></div>'
            + '    <div class="tac-slider-text">向右拖动滑块完成拼图</div>'
            + '    <div class="tac-slider-btn" id="tacBtn">→</div>'
            + '  </div>'
            + '</div>';
        document.body.appendChild(overlay);

        this.overlayEl = overlay;
        this.bgImg = overlay.querySelector('#tacBg');
        this.sliderImg = overlay.querySelector('#tacSlider');
        this.tipsEl = overlay.querySelector('#tacTips');
        this.loadingEl = overlay.querySelector('#tacLoading');
        this.sliderBtn = overlay.querySelector('#tacBtn');
        this.maskEl = overlay.querySelector('#tacMask');

        // 事件
        overlay.querySelector('#tacClose').onclick = function () { self.destroy(); };
        overlay.querySelector('#tacRefresh').onclick = function () { self.loadCaptcha(); };

        // 绑定滑块拖动事件（同时支持鼠标和触摸）
        this._boundDown = function (e) { self.onDown(e); };
        this._boundMove = function (e) { self.onMove(e); };
        this._boundUp = function (e) { self.onUp(e); };

        this.sliderBtn.addEventListener('mousedown', this._boundDown);
        this.sliderBtn.addEventListener('touchstart', this._boundDown, { passive: false });
        document.addEventListener('mousemove', this._boundMove);
        document.addEventListener('touchmove', this._boundMove, { passive: false });
        document.addEventListener('mouseup', this._boundUp);
        document.addEventListener('touchend', this._boundUp);
    };

    Captcha.prototype.loadCaptcha = function () {
        var self = this;
        this.showLoading(true);
        this.resetSlider();
        this.hideTips();

        this.ajax({
            url: this.genUrl,
            method: 'POST',
            headers: { 'Content-Type': 'application/json;charset=UTF-8' },
            data: '{}',
        }).then(function (res) {
            if (!res || res.code !== 200 || !res.data) {
                self.showTips('加载失败: ' + (res && res.message ? res.message : '未知错误'), 'error');
                return;
            }
            self.state.id = res.data.id;
            self.state.captchaData = res.data;
            self.state.yOffset = parseInt(res.data.yOffset) || 0;

            self.bgImg.src = res.data.backgroundImage;
            self.sliderImg.src = res.data.templateImage;
            // 设置滑块的垂直位置（缺口的 y 坐标）
            self.sliderImg.style.top = self.state.yOffset + 'px';

            // 动态更新 bg/tpl 尺寸（如果后端返回了不同的尺寸）
            if (res.data.bgImageWidth) self.bgWidth = parseInt(res.data.bgImageWidth);
            if (res.data.bgImageHeight) self.bgHeight = parseInt(res.data.bgImageHeight);
            if (res.data.templateImageWidth) self.tplSize = parseInt(res.data.templateImageWidth);
            if (res.data.templateImageHeight) {
                self.sliderImg.style.height = res.data.templateImageHeight + 'px';
            }

            self.showLoading(false);
        }).catch(function (err) {
            self.showLoading(false);
            self.showTips('网络错误，请重试', 'error');
        });
    };

    Captcha.prototype.onDown = function (e) {
        if (this.state.dragging) return;
        e.preventDefault();

        var pt = this.getPoint(e);
        this.state.dragging = true;
        this.state.startX = pt.x;
        this.state.startY = pt.y;
        this.state.startTime = Date.now();
        this.state.trackList = [
            { x: pt.x, y: pt.y, t: 0, type: 'down' }
        ];

        this.sliderBtn.classList.add('dragging');
        this.hideTips();
    };

    Captcha.prototype.onMove = function (e) {
        if (!this.state.dragging) return;
        // 某些 touch 事件没有 preventDefault，可能触发滚动；这里阻止默认行为
        if (e.cancelable) { try { e.preventDefault(); } catch (x) {} }

        var pt = this.getPoint(e);
        var dx = pt.x - this.state.startX;
        if (dx < 0) dx = 0;
        // 最大可移动距离 = 背景图宽度 - 滑块宽度
        var maxMove = this.bgWidth - this.tplSize;
        if (dx > maxMove) dx = maxMove;

        this.state.moveX = dx;
        this.sliderImg.style.transform = 'translate(' + dx + 'px, 0px)';
        this.sliderBtn.style.transform = 'translate(' + dx + 'px, 0px)';
        this.maskEl.style.width = dx + 'px';

        // 记录轨迹（pageX / pageY 绝对坐标）
        var t = Date.now() - this.state.startTime;
        this.state.trackList.push({
            x: pt.x,
            y: pt.y,
            t: t,
            type: 'move'
        });
    };

    Captcha.prototype.onUp = function (e) {
        if (!this.state.dragging) return;
        this.state.dragging = false;
        this.sliderBtn.classList.remove('dragging');

        var pt = this.getPoint(e);
        var t = Date.now() - this.state.startTime;
        this.state.trackList.push({
            x: pt.x,
            y: pt.y,
            t: t,
            type: 'up'
        });

        if (this.state.moveX < 10) {
            // 拖动太小，视为误触，重置
            this.resetSlider();
            return;
        }

        this.submit();
    };

    Captcha.prototype.submit = function () {
        var self = this;
        this.showLoading(true);

        var body = JSON.stringify({
            id: this.state.id,
            data: {
                bgImageWidth: this.bgWidth,
                bgImageHeight: this.bgHeight,
                templateImageWidth: this.tplSize,
                templateImageHeight: this.tplSize,
                startTime: this.state.startTime,
                stopTime: this.state.startTime + (this.state.trackList[this.state.trackList.length - 1].t || 0),
                trackList: this.state.trackList,
            }
        });

        this.ajax({
            url: this.checkUrl,
            method: 'POST',
            headers: { 'Content-Type': 'application/json;charset=UTF-8' },
            data: body,
        }).then(function (res) {
            self.showLoading(false);
            if (res && res.code === 200) {
                self.sliderBtn.classList.add('success');
                self.showTips('✓ 验证成功', 'success');
                setTimeout(function () {
                    if (typeof self.opts.onSuccess === 'function') {
                        self.opts.onSuccess(res);
                    }
                }, 600);
            } else {
                self.sliderBtn.classList.add('error');
                self.showTips('✕ ' + ((res && res.message) || '验证失败'), 'error');
                if (typeof self.opts.onFail === 'function') {
                    try { self.opts.onFail(res); } catch (x) {}
                }
                // 自动刷新
                setTimeout(function () { self.loadCaptcha(); }, 1200);
            }
        }).catch(function () {
            self.showLoading(false);
            self.sliderBtn.classList.add('error');
            self.showTips('网络错误，请重试', 'error');
            setTimeout(function () { self.loadCaptcha(); }, 1200);
        });
    };

    Captcha.prototype.resetSlider = function () {
        this.state.moveX = 0;
        this.state.trackList = [];
        if (this.sliderImg) this.sliderImg.style.transform = 'translate(0px, 0px)';
        if (this.sliderBtn) this.sliderBtn.style.transform = 'translate(0px, 0px)';
        if (this.maskEl) this.maskEl.style.width = '0px';
        if (this.sliderBtn) this.sliderBtn.classList.remove('success', 'error');
    };

    Captcha.prototype.getPoint = function (e) {
        if (e.touches && e.touches.length > 0) {
            return { x: e.touches[0].pageX || e.touches[0].clientX, y: e.touches[0].pageY || e.touches[0].clientY };
        }
        if (e.changedTouches && e.changedTouches.length > 0) {
            return { x: e.changedTouches[0].pageX || e.changedTouches[0].clientX, y: e.changedTouches[0].pageY || e.changedTouches[0].clientY };
        }
        return { x: e.pageX || e.clientX, y: e.pageY || e.clientY };
    };

    Captcha.prototype.showLoading = function (show) {
        if (this.loadingEl) this.loadingEl.style.display = show ? 'flex' : 'none';
    };

    Captcha.prototype.showTips = function (text, type) {
        if (!this.tipsEl) return;
        this.tipsEl.textContent = text;
        this.tipsEl.className = 'tac-tips ' + (type === 'error' ? 'error' : 'success');
        this.tipsEl.style.display = 'block';
    };

    Captcha.prototype.hideTips = function () {
        if (this.tipsEl) {
            this.tipsEl.style.display = 'none';
            this.tipsEl.className = 'tac-tips';
        }
    };

    Captcha.prototype.destroy = function () {
        document.removeEventListener('mousemove', this._boundMove);
        document.removeEventListener('touchmove', this._boundMove);
        document.removeEventListener('mouseup', this._boundUp);
        document.removeEventListener('touchend', this._boundUp);
        if (this.overlayEl && this.overlayEl.parentNode) {
            this.overlayEl.parentNode.removeChild(this.overlayEl);
        }
    };

    Captcha.prototype.ajax = function (opts) {
        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            xhr.open(opts.method || 'GET', opts.url);
            if (opts.headers) {
                for (var k in opts.headers) {
                    if (opts.headers.hasOwnProperty(k)) {
                        xhr.setRequestHeader(k, opts.headers[k]);
                    }
                }
            }
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        resolve(data);
                    } catch (e) {
                        reject(e);
                    }
                }
            };
            xhr.onerror = function () { reject(new Error('network error')); };
            xhr.send(opts.data || null);
        });
    };

    // ============= 对外 API =============
    function createInstance(opts) {
        var c = new Captcha(opts);
        c.init();
        return c;
    }

    global.TianaiCaptcha = {
        init: createInstance,
        create: createInstance,
    };
})(window);
