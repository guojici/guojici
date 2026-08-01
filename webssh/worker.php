<?php
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from CLI');
}

// 参数顺序: session_id, user, port, ip, password
$session_id = $argv[1] ?? '';
$user = $argv[2] ?? 'root';
$port = intval($argv[3] ?? 22);
$ip = $argv[4] ?? '';
$password = $argv[5] ?? '';

// 调试：记录接收到的参数
$debug_file = __DIR__ . '/../data/ssh_sessions/worker_debug.txt';
file_put_contents($debug_file, "Received args: " . json_encode($argv) . "\nsession_id: $session_id\nuser: $user\nport: $port\nip: $ip\npassword: $password\n");

if (empty($session_id) || empty($ip)) {
    file_put_contents($debug_file, "ERROR: Invalid parameters\n");
    die('Invalid parameters: session_id=' . $session_id . ', ip=' . $ip);
}

$base_dir = __DIR__ . '/../data/ssh_sessions';
$sess_file = $base_dir . '/' . $session_id . '.json';
$pid_file = $base_dir . '/' . $session_id . '.pid';
$input_file = $base_dir . '/' . $session_id . '.in';
$output_file = $base_dir . '/' . $session_id . '.out';

if (!is_dir($base_dir)) {
    @mkdir($base_dir, 0755, true);
}

file_put_contents($pid_file, getmypid());

$sess = json_decode(@file_get_contents($sess_file), true);
if (!$sess) {
    $sess = [];
}
$sess['status'] = 'starting';
$sess['pid'] = getmypid();
file_put_contents($sess_file, json_encode($sess));

// 优先使用已知路径，避免 PATH 环境变量问题
$sshpass_candidates = [
    '/usr/bin/sshpass',
    '/usr/local/bin/sshpass',
    '/bin/sshpass',
];
$sshpass = '';
foreach ($sshpass_candidates as $candidate) {
    if (@is_executable($candidate)) {
        $sshpass = $candidate;
        break;
    }
}
if (empty($sshpass)) {
    $sshpass = trim(@shell_exec('which sshpass 2>/dev/null'));
}

// 调试：记录 sshpass 和命令
$debug_content = "sshpass path: $sshpass\npassword: $password\n";
file_put_contents($debug_file, $debug_content, FILE_APPEND);

if (!empty($password) && !empty($sshpass)) {
    $cmd = sprintf(
        'sshpass -p %s ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -o ConnectTimeout=15 -p %d %s@%s -tt 2>&1',
        escapeshellarg($password),
        $port,
        escapeshellarg($user),
        escapeshellarg($ip)
    );
    file_put_contents($debug_file, "Using sshpass mode\ncmd: $cmd\n", FILE_APPEND);
} else {
    $cmd = sprintf(
        'ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -o ConnectTimeout=15 -p %d %s@%s -tt 2>&1',
        $port,
        escapeshellarg($user),
        escapeshellarg($ip)
    );
    file_put_contents($debug_file, "Using passwordless mode (or no sshpass)\ncmd: $cmd\n", FILE_APPEND);
}

$descriptorspec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open($cmd, $descriptorspec, $pipes);

if (!is_resource($process)) {
    $sess['status'] = 'error';
    $sess['error'] = '无法启动SSH进程（proc_open失败）';
    file_put_contents($sess_file, json_encode($sess));
    file_put_contents($debug_file, "ERROR: proc_open failed\n", FILE_APPEND);
    @unlink($pid_file);
    exit(1);
}

file_put_contents($debug_file, "proc_open succeeded, process running\n", FILE_APPEND);

stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
stream_set_blocking($pipes[0], false);

$sess['status'] = 'connecting';
file_put_contents($sess_file, json_encode($sess));

$connect_timeout = 20;
$connect_start = time();
$connected = false;
$error_buffer = '';

while (!$connected && (time() - $connect_start) < $connect_timeout) {
    $status = proc_get_status($process);
    if (!$status['running']) {
        $stderr = stream_get_contents($pipes[2]);
        $stdout = stream_get_contents($pipes[1]);
        $error_msg = trim($stderr . $stdout);
        if (empty($error_msg)) {
            $error_msg = 'SSH进程意外退出（可能是密码错误或网络不可达）';
        } else {
            $error_msg = mb_substr($error_msg, 0, 300);
        }
        $sess['status'] = 'error';
        $sess['error'] = $error_msg;
        file_put_contents($sess_file, json_encode($sess));
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        @unlink($pid_file);
        exit(1);
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    if ($stdout) {
        $current = @file_get_contents($output_file);
        file_put_contents($output_file, $current . $stdout);
        if (strpos($stdout, '$') !== false || strpos($stdout, '#') !== false || strpos($stdout, 'Last login') !== false) {
            $connected = true;
        }
    }
    if ($stderr) {
        $error_buffer .= $stderr;
        $current = @file_get_contents($output_file);
        file_put_contents($output_file, $current . $stderr);
    }

    usleep(100000);
}

if (!$connected) {
    $sess['status'] = 'error';
    $sess['error'] = '连接超时，可能是网络不通或密码错误';
    if ($error_buffer) {
        $sess['error'] .= '：' . mb_substr(trim($error_buffer), 0, 200);
    }
    file_put_contents($sess_file, json_encode($sess));
    @proc_terminate($process);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    @unlink($pid_file);
    exit(1);
}

$sess['status'] = 'running';
file_put_contents($sess_file, json_encode($sess));

file_put_contents($input_file, '');
$input_pos = 0;
$last_check = time();

while (true) {
    $status = proc_get_status($process);
    if (!$status['running']) {
        $remaining = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        if ($remaining || $stderr) {
            $current = @file_get_contents($output_file);
            file_put_contents($output_file, $current . $remaining . $stderr);
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        break;
    }

    $stdout = stream_get_contents($pipes[1], 8192);
    $stderr = stream_get_contents($pipes[2], 4096);

    if ($stdout !== false && $stdout !== '') {
        $current = @file_get_contents($output_file);
        file_put_contents($output_file, $current . $stdout);
    }
    if ($stderr !== false && $stderr !== '') {
        $current = @file_get_contents($output_file);
        file_put_contents($output_file, $current . $stderr);
    }

    if (file_exists($input_file)) {
        $content = @file_get_contents($input_file);
        if ($content && strlen($content) > $input_pos) {
            $new_data = substr($content, $input_pos);
            fwrite($pipes[0], $new_data);
            $input_pos = strlen($content);
        }
    }

    if (time() - $last_check > 10) {
        if (!file_exists($sess_file)) {
            proc_terminate($process);
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            break;
        }
        $last_check = time();
    }

    usleep(50000);
}

@unlink($pid_file);
@unlink($input_file);
sleep(3);
@unlink($output_file);
@unlink($sess_file);
