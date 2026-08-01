<?php
/**
 * 易支付核心类 - 生产级
 * 基于文档：https://pay.vansdesign.cn/
 *
 * 签名算法规则 (MD5)：
 * 1. 按参数名的 ASCII 码从小到大排序 (ksort)
 * 2. 过滤掉 sign、sign_type 和 空值 的参数
 * 3. 拼接成 URL 键值对格式：a=b&c=d&e=f（参数值不做 URL 编码）
 * 4. 在拼接好的字符串尾部直接拼接 商户密钥 key
 * 5. 取 md5 值作为签名（小写 32 位）
 *
 * 请求数据格式：application/x-www-form-urlencoded
 * 返回数据格式：JSON (接口) | HTML/重定向 (页面跳转)
 */

class EpayCore
{
    private $pid;
    private $key;
    private $submit_url; // 页面跳转支付：submit.php
    private $mapi_url;   // API 接口支付：mapi.php
    private $api_url;    // 商户查询/退款 API：api.php
    private $sign_type = 'MD5';
    private $timeout = 10;
    private $debug = false;

    function __construct($config)
    {
        $this->pid = trim($config['pid']);
        $this->key = trim($config['key']);
        $base = rtrim($config['apiurl'], '/') . '/';
        $this->submit_url = $base . 'submit.php';
        $this->mapi_url = $base . 'mapi.php';
        $this->api_url = $base . 'api.php';
        if (isset($config['timeout']) && intval($config['timeout']) > 0) {
            $this->timeout = intval($config['timeout']);
        }
        if (isset($config['debug'])) {
            $this->debug = (bool)$config['debug'];
        }
    }

    /**
     * 页面跳转支付（推荐，form POST 跳转）
     * @param array $param 请求参数：pid、type(可不传)、out_trade_no、notify_url、return_url、name、money、(param可选)
     * @return string HTML
     */
    public function pagePay($param)
    {
        $param = $this->buildRequestParam($param);
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>正在跳转支付...</title>';
        $html .= '<link rel="stylesheet" href="/assets/css/style.css"></head><body>';
        $html .= '<div style="max-width:520px; margin:80px auto; padding:40px 28px; background:#fff; border:1px solid #e5e6eb; border-radius:12px; text-align:center; box-shadow:0 4px 12px rgba(0,0,0,0.06);">';
        $html .= '<h2 style="font-size:20px; color:#1d2129; margin-bottom:12px;">正在为您跳转到支付页面</h2>';
        $html .= '<p style="color:#86909c; margin-bottom:24px;">如果长时间未跳转，请点击下方按钮手动提交</p>';
        $html .= '<form id="epayForm" action="' . htmlspecialchars($this->submit_url) . '" method="post">';
        foreach ($param as $k => $v) {
            $html .= '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '"/>';
        }
        $html .= '<button type="submit" style="display:inline-block; padding:10px 32px; background:#1677ff; color:#fff; border:none; border-radius:6px; font-size:15px; font-weight:600; cursor:pointer;">立即支付</button>';
        $html .= '</form></div>';
        $html .= '<script type="text/javascript">document.getElementById("epayForm").submit();</script>';
        $html .= '</body></html>';
        return $html;
    }

    /**
     * 仅获取支付跳转链接（GET）
     */
    public function getPayLink($param)
    {
        $param = $this->buildRequestParam($param);
        return $this->submit_url . '?' . http_build_query($param);
    }

    /**
     * API 接口支付 (mapi.php)，返回二维码/跳转URL
     * 文档必传：pid, type, out_trade_no, notify_url, name, money, clientip
     * 可选：return_url, device, param
     */
    public function apiPay($param)
    {
        $param = $this->buildRequestParam($param);
        $response = $this->post($this->mapi_url, $param);
        $arr = json_decode($response, true);
        if (!is_array($arr)) {
            $arr = ['code' => -1, 'msg' => '返回数据非JSON', 'raw' => $response];
        }
        return $arr;
    }

    /**
     * 异步回调签名验证（服务端调用 notify_url.php）
     * 文档：GET 请求，参数包含：pid、out_trade_no、trade_no、type、name、money、trade_status、param、sign、sign_type
     */
    public function verifyNotify($data = null)
    {
        if ($data === null) $data = $_GET;
        if (empty($data) || !isset($data['sign'])) return false;
        $sign = $this->getSign($data);
        return hash_equals($sign, (string)$data['sign']);
    }

    /**
     * 同步跳转验证（用户浏览器返回后调用 return_url.php）
     */
    public function verifyReturn($data = null)
    {
        if ($data === null) $data = $_GET;
        if (empty($data) || !isset($data['sign'])) return false;
        $sign = $this->getSign($data);
        return hash_equals($sign, (string)$data['sign']);
    }

    /**
     * 查询订单
     * API: api.php?act=order&pid=...&key=...&out_trade_no=...
     */
    public function queryOrder($out_trade_no)
    {
        $url = $this->api_url . '?act=order&pid=' . urlencode($this->pid)
            . '&key=' . urlencode($this->key) . '&out_trade_no=' . urlencode($out_trade_no);
        $response = $this->get($url);
        $arr = json_decode($response, true);
        if (!is_array($arr)) $arr = ['code' => -1, 'msg' => '返回数据非JSON', 'raw' => $response];
        return $arr;
    }

    /**
     * 退款 API（POST）
     * 文档：api.php?act=refund，参数 pid、key、out_trade_no(或trade_no)、money
     */
    public function refund($out_trade_no, $money)
    {
        $payload = [
            'act' => 'refund',
            'pid' => $this->pid,
            'key' => $this->key,
            'out_trade_no' => $out_trade_no,
            'money' => number_format(floatval($money), 2, '.', ''),
        ];
        $response = $this->post($this->api_url, $payload);
        $arr = json_decode($response, true);
        if (!is_array($arr)) $arr = ['code' => -1, 'msg' => '返回数据非JSON', 'raw' => $response];
        return $arr;
    }

    /**
     * 校验订单支付状态 (基于查询API)
     */
    public function orderStatus($out_trade_no)
    {
        $result = $this->queryOrder($out_trade_no);
        return (isset($result['status']) && $result['status'] == 1);
    }

    /**
     * 构造请求参数：追加 pid、sign_type，并生成签名
     */
    private function buildRequestParam($param)
    {
        if (!isset($param['pid']) || $param['pid'] === '') {
            $param['pid'] = $this->pid;
        }
        // 确保金额是 2 位小数的字符串
        if (isset($param['money']) && $param['money'] !== '' && is_numeric($param['money'])) {
            $param['money'] = number_format(floatval($param['money']), 2, '.', '');
        }
        $param['sign'] = $this->getSign($param);
        $param['sign_type'] = $this->sign_type;
        return $param;
    }

    /**
     * 计算签名（严格遵循文档）
     * 1. ksort 参数
     * 2. 过滤：sign, sign_type, 空值
     * 3. 拼接：a=b&c=d&e=f
     * 4. 尾部拼接 商户密钥
     * 5. md5(小写)
     */
    private function getSign($param)
    {
        ksort($param);
        reset($param);
        $signstr = '';
        foreach ($param as $k => $v) {
            if ($k === 'sign' || $k === 'sign_type') continue;
            if ($v === '' || $v === null) continue;
            // 文档要求参数值不要进行URL编码
            $signstr .= $k . '=' . $v . '&';
        }
        // 去掉最后一个 &
        if ($signstr !== '') {
            $signstr = substr($signstr, 0, -1);
        }
        // 追加商户密钥
        $signstr .= $this->key;
        if ($this->debug) {
            error_log('[EpayCore Sign String] ' . $signstr);
        }
        return md5($signstr);
    }

    /**
     * 发起 GET 请求
     */
    private function get($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 EpayClient/1.0');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($this->debug) {
            error_log('[EpayCore GET] ' . $url . ' -> HTTP ' . $httpCode);
        }
        curl_close($ch);
        return $response;
    }

    /**
     * 发起 POST 请求 (application/x-www-form-urlencoded)
     */
    private function post($url, $data = [])
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded;charset=UTF-8',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 EpayClient/1.0');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($this->debug) {
            error_log('[EpayCore POST] ' . $url . ' -> HTTP ' . $httpCode);
        }
        curl_close($ch);
        return $response;
    }
}
