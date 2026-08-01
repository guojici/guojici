<?php
require_once __DIR__ . '/../config/helper.php';
header('Content-Type: application/json; charset=utf-8');

// check_env 动作不需要登录验证
$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action === 'check_env') {
    $errors = check_ssh_dependencies();
    $php_bin = PHP_BINARY;
    $worker = __DIR__ . '/worker.php';
    $ssh_dir = __DIR__ . '/../data/ssh_sessions';
    echo json_encode([
        'code' => 0,
        'deps_ok' => empty($errors),
        'errors' => $errors,
        'php_bin' => $php_bin,
        'php_bin_exists' => file_exists($php_bin),
        'php_bin_executable' => is_executable($php_bin),
        'worker' => $worker,
        'worker_exists' => file_exists($worker),
        'ssh_dir' => $ssh_dir,
        'ssh_dir_exists' => is_dir($ssh_dir),
        'ssh_dir_writable' => is_writable($ssh_dir),
        'www_user' => get_current_user(),
        'sapi' => php_sapi_name(),
        'functions' => [
            'proc_open' => function_exists('proc_open'),
            'exec' => function_exists('exec'),
            'shell_exec' => function_exists('shell_exec'),
        ],
    ]);
    exit;
}

if (!auth_check()) {
    echo json_encode(['code' => 401, 'msg' => '请先登录']);
    exit;
}

// 释放Session锁，WebSSH长连接会阻塞同用户其他请求
session_write_close();

$uid = auth_id();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

$ssh_dir = __DIR__ . '/../data/ssh_sessions';
if (!is_dir($ssh_dir)) {
    @mkdir($ssh_dir, 0755, true);
}

function ssh_session_file($session_id) {
    global $ssh_dir;
    return $ssh_dir . '/' . $session_id . '.json';
}

function ssh_pid_file($session_id) {
    global $ssh_dir;
    return $ssh_dir . '/' . $session_id . '.pid';
}

function ssh_input_pipe($session_id) {
    global $ssh_dir;
    return $ssh_dir . '/' . $session_id . '.in';
}

function ssh_output_file($session_id) {
    global $ssh_dir;
    return $ssh_dir . '/' . $session_id . '.out';
}

function ssh_error_file($session_id) {
    global $ssh_dir;
    return $ssh_dir . '/' . $session_id . '.err';
}

function check_ssh_dependencies() {
    $errors = [];
    
    if (!function_exists('proc_open')) {
        $errors[] = 'proc_open函数被禁用';
    }
    
    if (!function_exists('exec')) {
        $errors[] = 'exec函数被禁用';
    }
    
    $sshpass = @exec('which sshpass 2>&1');
    if (empty($sshpass)) {
        $errors[] = 'sshpass未安装';
    }
    
    $ssh = @exec('which ssh 2>&1');
    if (empty($ssh)) {
        $errors[] = 'ssh客户端未安装';
    }
    
    return $errors;
}

if ($action === 'check_env') {
    $errors = check_ssh_dependencies();
    $php_bin = PHP_BINARY;
    $worker = __DIR__ . '/worker.php';
    echo json_encode([
        'code' => 0,
        'deps_ok' => empty($errors),
        'errors' => $errors,
        'php_bin' => $php_bin,
        'worker' => $worker,
        'ssh_dir' => $ssh_dir,
        'ssh_dir_writable' => is_writable($ssh_dir),
    ]);
    exit;
}

if ($action === 'start') {
    $token = $_POST['token'] ?? '';
    $ssh_info = validate_ssh_token_db($token);
    if (!$ssh_info) {
        echo json_encode(['code' => 1, 'msg' => '连接凭证无效或已过期，请刷新页面重试']);
        exit;
    }

    if ($ssh_info['user_id'] != $uid) {
        echo json_encode(['code' => 1, 'msg' => '用户验证失败']);
        exit;
    }

    $deps = check_ssh_dependencies();
    if (!empty($deps)) {
        echo json_encode(['code' => 1, 'msg' => '服务器环境不支持SSH：' . implode('、', $deps)]);
        exit;
    }

    $session_id = 'ssh_' . md5($uid . '_' . $ssh_info['host_id'] . '_' . time() . '_' . rand(1000, 9999));
    $ip = $ssh_info['ip'];
    $port = intval($ssh_info['port'] ?? 22);
    $user = $ssh_info['user'] ?? 'root';
    $password = $ssh_info['password'] ?? '';

    if (empty($password)) {
        echo json_encode(['code' => 1, 'msg' => '密码为空，无法自动登录，请先设置root密码']);
        exit;
    }

    $php_bin = PHP_BINARY;
    if (!$php_bin || !is_executable($php_bin)) {
        $php_bin = 'php';
    }
    $worker_script = __DIR__ . '/worker.php';

    @file_put_contents(ssh_session_file($session_id), json_encode([
        'session_id' => $session_id,
        'user_id' => $uid,
        'host_id' => $ssh_info['host_id'],
        'ip' => $ip,
        'port' => $port,
        'user' => $user,
        'started_at' => time(),
        'status' => 'starting',
    ]));

    @file_put_contents(ssh_output_file($session_id), '');
    @file_put_contents(ssh_input_pipe($session_id), '');
    @file_put_contents(ssh_error_file($session_id), '');

    // 获取正确的 PHP CLI 路径（PHP_BINARY 在 FPM 模式下返回 php-fpm，不是 php-cli）
    $php_paths = [
        '/www/server/php/83/bin/php',  // 宝塔 PHP 8.3
        '/www/server/php/74/bin/php',  // 宝塔 PHP 7.4
        '/usr/bin/php',
        '/usr/local/bin/php',
    ];
    $php_bin = '';
    foreach ($php_paths as $p) {
        if (file_exists($p) && is_executable($p)) {
            // 确保是 CLI 版本（不是 php-fpm）
            if (strpos($p, 'php-fpm') === false) {
                $php_bin = $p;
                break;
            }
        }
    }
    if (empty($php_bin)) {
        echo json_encode(['code' => 1, 'msg' => '找不到 PHP CLI，请安装 PHP CLI 版本']);
        exit;
    }

    $worker_script = __DIR__ . '/worker.php';
    
    // 检查 worker.php 是否存在
    if (!file_exists($worker_script)) {
        echo json_encode(['code' => 1, 'msg' => 'worker.php 不存在']);
        exit;
    }

    $worker_args = sprintf('%s %s %d %s %s',
        escapeshellarg($session_id),
        escapeshellarg($user),
        $port,
        escapeshellarg($ip),
        escapeshellarg($password)
    );

    // 记录密码调试信息（仅调试用，生产环境应删除）
    $sess_data = json_decode(@file_get_contents(ssh_session_file($session_id)), true) ?: [];
    $sess_data['debug_password'] = $password;
    $sess_data['debug_user'] = $user;
    $sess_data['debug_port'] = $port;
    $sess_data['debug_ip'] = $ip;
    $sess_data['debug_args'] = $worker_args;
    @file_put_contents(ssh_session_file($session_id), json_encode($sess_data));

    $started = false;
    $pid = 0;
    $debug_info = [];

    // 方式1: 使用 nohup 后台启动
    $cmd1 = sprintf(
        'nohup %s %s %s > %s 2>&1 & echo $!',
        escapeshellarg($php_bin),
        escapeshellarg($worker_script),
        $worker_args,
        escapeshellarg(ssh_error_file($session_id))
    );
    $output1 = [];
    $retval1 = 0;
    @exec($cmd1, $output1, $retval1);
    $debug_info['cmd1'] = $cmd1;
    $debug_info['output1'] = $output1;
    $debug_info['retval1'] = $retval1;
    if (!empty($output1)) {
        $pid_candidate = trim(end($output1));
        if (is_numeric($pid_candidate) && intval($pid_candidate) > 0) {
            $pid = intval($pid_candidate);
            $started = true;
        }
    }

    // 方式2: 直接后台启动
    if (!$started) {
        $cmd2 = sprintf(
            '%s %s %s > %s 2>&1 & echo $!',
            $php_bin,
            $worker_script,
            $worker_args,
            ssh_error_file($session_id)
        );
        $output2 = [];
        $retval2 = 0;
        @exec($cmd2, $output2, $retval2);
        $debug_info['cmd2'] = $cmd2;
        $debug_info['output2'] = $output2;
        if (!empty($output2)) {
            $pid_candidate = trim(end($output2));
            if (is_numeric($pid_candidate) && intval($pid_candidate) > 0) {
                $pid = intval($pid_candidate);
                $started = true;
            }
        }
    }

    // 方式3: 使用 shell_exec
    if (!$started) {
        $cmd3 = sprintf(
            '%s %s %s > %s 2>&1 & echo $!',
            $php_bin,
            $worker_script,
            $worker_args,
            ssh_error_file($session_id)
        );
        $output3 = @shell_exec($cmd3);
        $debug_info['cmd3'] = $cmd3;
        $debug_info['output3'] = $output3;
        if ($output3) {
            $output3 = trim($output3);
            if (is_numeric($output3) && intval($output3) > 0) {
                $pid = intval($output3);
                $started = true;
            }
        }
    }

    // 方式4: 使用 proc_open 直接启动（如果允许）
    if (!$started && function_exists('proc_open')) {
        $cmd4 = sprintf('%s %s %s', $php_bin, $worker_script, $worker_args);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', ssh_error_file($session_id), 'a'],
            2 => ['file', ssh_error_file($session_id), 'a'],
        ];
        $proc = @proc_open($cmd4, $descriptors, $pipes);
        if (is_resource($proc)) {
            $status = proc_get_status($proc);
            if ($status['running']) {
                $pid = $status['pid'];
                $started = true;
                $debug_info['proc_open'] = 'started with pid ' . $pid;
            }
            // 不关闭进程，让它继续运行
        }
    }

    // 记录调试信息
    $sess_data = json_decode(@file_get_contents(ssh_session_file($session_id)), true) ?: [];
    $sess_data['debug'] = $debug_info;
    $sess_data['php_bin'] = $php_bin;
    $sess_data['worker_script'] = $worker_script;
    $sess_data['started_method'] = $started ? 'success' : 'failed';
    @file_put_contents(ssh_session_file($session_id), json_encode($sess_data));

    if ($pid > 0) {
        @file_put_contents(ssh_pid_file($session_id), $pid);
    }

    $max_wait = 25;
    $waited = 0;
    $interval = 0.5;
    $final_status = 'starting';
    $final_error = '';

    while ($waited < $max_wait) {
        usleep(intval($interval * 1000000));
        $waited += $interval;

        $sess_file = ssh_session_file($session_id);
        if (!file_exists($sess_file)) {
            $final_status = 'error';
            $final_error = 'worker 进程未启动（会话文件不存在）';
            break;
        }

        $sess = json_decode(@file_get_contents($sess_file), true);
        $status = $sess['status'] ?? 'starting';

        if ($status === 'running') {
            $final_status = 'running';
            break;
        } elseif ($status === 'error') {
            $final_status = 'error';
            $final_error = $sess['error'] ?? 'SSH连接失败';
            break;
        }
    }

    if ($final_status === 'running') {
        echo json_encode([
            'code' => 0,
            'msg' => 'ok',
            'session_id' => $session_id,
        ]);
    } else {
        $err_output = '';
        if (file_exists(ssh_error_file($session_id))) {
            $err_content = @file_get_contents(ssh_error_file($session_id));
            if ($err_content && strlen($err_content) > 5) {
                $err_output = mb_substr(trim($err_content), 0, 300);
            }
        }

        if (empty($final_error) && !empty($err_output)) {
            $final_error = $err_output;
        }

        if ($waited >= $max_wait && empty($final_error)) {
            $final_error = '连接超时（' . $max_wait . '秒），可能是SSH端口不通、密码错误或网络不可达';
        }
        if (empty($final_error)) {
            $final_error = 'SSH连接失败，请检查虚拟机是否开机、SSH端口是否可达、root密码是否正确';
        }

        @unlink(ssh_session_file($session_id));
        @unlink(ssh_output_file($session_id));
        @unlink(ssh_input_pipe($session_id));
        @unlink(ssh_error_file($session_id));
        @unlink(ssh_pid_file($session_id));

        echo json_encode([
            'code' => 1,
            'msg' => $final_error,
        ]);
    }

} elseif ($action === 'write') {
    $session_id = $_POST['session_id'] ?? '';
    $data = $_POST['data'] ?? '';
    $sess_file = ssh_session_file($session_id);

    if (!file_exists($sess_file)) {
        echo json_encode(['code' => 1, 'msg' => '会话不存在']);
        exit;
    }

    $sess = json_decode(file_get_contents($sess_file), true);
    if ($sess['user_id'] != $uid) {
        echo json_encode(['code' => 1, 'msg' => '无权限']);
        exit;
    }

    $input_file = ssh_input_pipe($session_id);
    $current = @file_get_contents($input_file);
    file_put_contents($input_file, $current . $data);

    echo json_encode(['code' => 0, 'msg' => 'ok']);

} elseif ($action === 'read') {
    $session_id = $_GET['session_id'] ?? '';
    $sess_file = ssh_session_file($session_id);

    if (!file_exists($sess_file)) {
        echo json_encode(['code' => 1, 'msg' => '会话不存在', 'closed' => true]);
        exit;
    }

    $sess = json_decode(file_get_contents($sess_file), true);
    if ($sess['user_id'] != $uid) {
        echo json_encode(['code' => 1, 'msg' => '无权限']);
        exit;
    }

    $output_file = ssh_output_file($session_id);
    $output = '';
    $is_closed = false;

    if (file_exists($output_file)) {
        $output = @file_get_contents($output_file);
        if ($output) {
            @file_put_contents($output_file, '');
        }
    }

    $pid_file = ssh_pid_file($session_id);
    if (!file_exists($pid_file) && ($sess['status'] ?? '') === 'running') {
        $is_closed = true;
        @unlink($sess_file);
        @unlink($output_file);
        @unlink(ssh_input_pipe($session_id));
        @unlink(ssh_error_file($session_id));
    }

    if (($sess['status'] ?? '') === 'error') {
        $is_closed = true;
        $error_msg = $sess['error'] ?? '连接失败';
        @unlink($sess_file);
        @unlink($output_file);
        @unlink(ssh_input_pipe($session_id));
        @unlink(ssh_error_file($session_id));
        echo json_encode([
            'code' => 1,
            'msg' => $error_msg,
            'output' => $output,
            'closed' => true,
        ]);
        exit;
    }

    echo json_encode([
        'code' => 0,
        'msg' => 'ok',
        'output' => $output,
        'closed' => $is_closed,
    ]);

} elseif ($action === 'resize') {
    $session_id = $_POST['session_id'] ?? '';
    $cols = intval($_POST['cols'] ?? 80);
    $rows = intval($_POST['rows'] ?? 24);

    $sess_file = ssh_session_file($session_id);
    if (!file_exists($sess_file)) {
        echo json_encode(['code' => 1, 'msg' => '会话不存在']);
        exit;
    }

    $sess = json_decode(file_get_contents($sess_file), true);
    if ($sess['user_id'] != $uid) {
        echo json_encode(['code' => 1, 'msg' => '无权限']);
        exit;
    }

    $resize_cmd = "\x1b[8;{$rows};{$cols}t";
    $input_file = ssh_input_pipe($session_id);
    $current = @file_get_contents($input_file);
    file_put_contents($input_file, $current . $resize_cmd);

    echo json_encode(['code' => 0, 'msg' => 'ok']);

} elseif ($action === 'close') {
    $session_id = $_POST['session_id'] ?? '';
    $sess_file = ssh_session_file($session_id);

    if (file_exists($sess_file)) {
        $sess = json_decode(file_get_contents($sess_file), true);
        if ($sess['user_id'] == $uid) {
            $pid_file = ssh_pid_file($session_id);
            if (file_exists($pid_file)) {
                $pid = intval(trim(file_get_contents($pid_file)));
                if ($pid > 0) {
                    @posix_kill($pid, 9);
                    @exec('kill -9 ' . intval($pid) . ' 2>/dev/null');
                }
                @unlink($pid_file);
            }
            @unlink($sess_file);
            @unlink(ssh_output_file($session_id));
            @unlink(ssh_input_pipe($session_id));
            @unlink(ssh_error_file($session_id));
        }
    }

    echo json_encode(['code' => 0, 'msg' => 'ok']);

} else {
    echo json_encode(['code' => 1, 'msg' => '未知操作']);
}
