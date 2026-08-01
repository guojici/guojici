<?php
require_once __DIR__ . '/helper.php';

class MNBT_API {
    private $timeout = 30;

    public function __construct($config = null) {
    }

    private function get_config() {
        $cfg = config('mnbt');
        return [
            'base_url' => rtrim($cfg['base_url'], '/'),
            'mn_bh' => $cfg['mn_bh'],
            'mn_key' => $cfg['mn_key'],
            'mn_keye' => $cfg['mn_keye'],
            'mn_vs' => $cfg['mn_vs'],
        ];
    }

    private function required_params() {
        $c = $this->get_config();
        return [
            'mn_bh' => $c['mn_bh'],
            'mn_key' => $c['mn_key'],
            'mn_keye' => $c['mn_keye'],
            'mn_vs' => $c['mn_vs'],
        ];
    }

    private function post($endpoint, $params = []) {
        $c = $this->get_config();
        $url = $c['base_url'] . $endpoint;
        $post_data = array_merge($this->required_params(), $params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['code' => 500, 'msg' => 'API连接失败: ' . $error, 'raw' => $response];
        }

        $data = json_decode($response, true);
        if ($data === null) {
            return ['code' => 500, 'msg' => 'API响应格式错误', 'raw' => $response];
        }

        return $data;
    }

    public function test_connection($username = 'test') {
        return $this->post('/api/api.php?gn=cfif', ['username' => $username]);
    }

    public function create_host($username, $password, $webdx, $sqldx, $sizemax, $type, $ymbds, $dqtime) {
        $params = [
            'username' => $username,
            'password' => $password,
            'webdx' => $webdx,
            'sqldx' => $sqldx,
            'sizemax' => $sizemax,
            'type' => $type,
            'ymbds' => $ymbds,
            'dqtime' => $dqtime,
        ];
        return $this->post('/api/api.php?gn=kt', $params);
    }

    public function renew_host($username, $setdate) {
        $params = [
            'username' => $username,
            'setdate' => $setdate,
        ];
        return $this->post('/api/api.php?gn=xf', $params);
    }

    public function delete_host($username) {
        return $this->post('/api/api.php?gn=tz', ['username' => $username]);
    }

    public function suspend_host($username) {
        return $this->post('/api/api.php?gn=zt', ['username' => $username]);
    }

    public function unsuspend_host($username) {
        return $this->post('/api/api.php?gn=jc', ['username' => $username]);
    }

    public function reset_password($username, $password) {
        return $this->post('/api/api.php?gn=czmm', [
            'username' => $username,
            'password' => $password,
        ]);
    }

    public function get_login_url($username, $password) {
        $c = $this->get_config();
        $query = http_build_query([
            'username' => $username,
            'password' => $password,
        ]);
        return $c['base_url'] . '/user/idcdl.php?gn=logine&' . $query;
    }

    public function get_logout_url() {
        $c = $this->get_config();
        return $c['base_url'] . '/user/idcdl.php?gn=xz';
    }
}

function mnbt_api() {
    static $api = null;
    if ($api === null) {
        $api = new MNBT_API();
    }
    return $api;
}