<?php
/**
 * WebSSH WebSocket 服务器
 * 使用方法: php webssh/server.php
 * 需要 PHP sockets 扩展和 pcntl 扩展
 * 
 * 也可以使用 Python 版本:
 * pip install webssh
 * wssh --port=8888
 */

require_once __DIR__ . '/../config/helper.php';

// 配置
$ws_host = '0.0.0.0';
$ws_port = intval(config('webssh.port') ?: 8888);

// 检查扩展
if (!extension_loaded('sockets')) {
    die("需要 sockets 扩展支持\n");
}

echo "Starting WebSSH server on {$ws_host}:{$ws_port}...\n";

// 创建WebSocket服务器
$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($server, $ws_host, $ws_port);
socket_listen($server);

$clients = [];
$ssh_procs = [];

echo "Server started. Waiting for connections...\n";

while (true) {
    $read = [$server];
    foreach ($clients as $client) {
        $read[] = $client['socket'];
    }
    
    $write = null;
    $except = null;
    
    if (socket_select($read, $write, $except, 0, 200000) < 1) {
        // 检查SSH进程输出
        foreach ($ssh_procs as $client_id => &$proc) {
            if (!is_resource($proc['process'])) continue;
            
            $status = proc_get_status($proc['process']);
            if (!$status['running']) {
                continue;
            }
            
            // 读取stdout
            $output = stream_get_contents($proc['pipes'][1], 4096);
            if ($output !== false && $output !== '') {
                if (isset($clients[$client_id])) {
                    ws_send($clients[$client_id]['socket'], $output);
                }
            }
            
            // 读取stderr
            $error = stream_get_contents($proc['pipes'][2], 4096);
            if ($error !== false && $error !== '') {
                if (isset($clients[$client_id])) {
                    ws_send($clients[$client_id]['socket'], $error);
                }
            }
        }
        continue;
    }
    
    foreach ($read as $sock) {
        if ($sock === $server) {
            // 新连接
            $client = socket_accept($server);
            if ($client) {
                $id = uniqid('client_');
                $clients[$id] = [
                    'socket' => $client,
                    'id' => $id,
                    'handshake' => false,
                    'authenticated' => false,
                ];
                echo "New client connected: {$id}\n";
            }
        } else {
            // 已有连接的数据
            $client_id = null;
            foreach ($clients as $cid => $c) {
                if ($c['socket'] === $sock) {
                    $client_id = $cid;
                    break;
                }
            }
            
            if (!$client_id) continue;
            
            $data = @socket_read($sock, 8192, PHP_BINARY_READ);
            
            if ($data === false || strlen($data) === 0) {
                // 连接关闭
                echo "Client disconnected: {$client_id}\n";
                if (isset($ssh_procs[$client_id])) {
                    if (is_resource($ssh_procs[$client_id]['process'])) {
                        proc_terminate($ssh_procs[$client_id]['process']);
                    }
                    unset($ssh_procs[$client_id]);
                }
                socket_close($sock);
                unset($clients[$client_id]);
                continue;
            }
            
            if (!$clients[$client_id]['handshake']) {
                // WebSocket握手
                if (do_handshake($sock, $data)) {
                    $clients[$client_id]['handshake'] = true;
                    echo "WebSocket handshake complete: {$client_id}\n";
                }
                continue;
            }
            
            // 解析WebSocket消息
            $message = ws_decode($data);
            if ($message === null) continue;
            
            if ($message['type'] === 'close') {
                echo "Client closed: {$client_id}\n";
                if (isset($ssh_procs[$client_id])) {
                    if (is_resource($ssh_procs[$client_id]['process'])) {
                        proc_terminate($ssh_procs[$client_id]['process']);
                    }
                    unset($ssh_procs[$client_id]);
                }
                socket_close($sock);
                unset($clients[$client_id]);
                continue;
            }
            
            if ($message['type'] === 'text') {
                $payload = $message['payload'];
                
                // 解析JSON消息
                $json = json_decode($payload, true);
                if ($json && isset($json['type'])) {
                    if ($json['type'] === 'auth') {
                        // 认证
                        $token = $json['token'] ?? '';
                        $ssh_info = validate_ssh_token($token);
                        if ($ssh_info) {
                            $clients[$client_id]['authenticated'] = true;
                            $clients[$client_id]['ssh_info'] = $ssh_info;
                            
                            // 启动SSH连接
                            $ssh_result = start_ssh_session($ssh_info, $client_id);
                            if ($ssh_result) {
                                $ssh_procs[$client_id] = $ssh_result;
                                ws_send_json($sock, ['type' => 'connected']);
                                echo "SSH session started for: {$client_id}\n";
                            } else {
                                ws_send_json($sock, ['type' => 'error', 'message' => 'SSH连接失败']);
                            }
                        } else {
                            ws_send_json($sock, ['type' => 'error', 'message' => '认证失败']);
                        }
                    } elseif ($json['type'] === 'resize' && isset($ssh_procs[$client_id])) {
                        // 调整终端大小
                        $cols = intval($json['cols'] ?? 80);
                        $rows = intval($json['rows'] ?? 24);
                        resize_terminal($ssh_procs[$client_id], $cols, $rows);
                    }
                } elseif ($clients[$client_id]['authenticated'] && isset($ssh_procs[$client_id])) {
                    // SSH输入
                    if (is_resource($ssh_procs[$client_id]['pipes'][0])) {
                        fwrite($ssh_procs[$client_id]['pipes'][0], $payload);
                    }
                }
            }
        }
    }
}

function do_handshake($socket, $data) {
    if (preg_match('/Sec-WebSocket-Key: (.*)\r\n/', $data, $matches)) {
        $key = trim($matches[1]);
        $accept = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        
        $response = "HTTP/1.1 101 Switching Protocols\r\n";
        $response .= "Upgrade: websocket\r\n";
        $response .= "Connection: Upgrade\r\n";
        $response .= "Sec-WebSocket-Accept: {$accept}\r\n";
        $response .= "\r\n";
        
        socket_write($socket, $response);
        return true;
    }
    return false;
}

function ws_decode($data) {
    if (strlen($data) < 2) return null;
    
    $bytes = $data;
    $opcode = ord($bytes[0]) & 0x0F;
    $is_masked = (ord($bytes[1]) & 0x80) != 0;
    $payload_length = ord($bytes[1]) & 0x7F;
    $offset = 2;
    
    if ($payload_length === 126) {
        $payload_length = unpack('n', substr($bytes, $offset, 2))[1];
        $offset += 2;
    } elseif ($payload_length === 127) {
        $payload_length = unpack('J', substr($bytes, $offset, 8))[1];
        $offset += 8;
    }
    
    $mask = null;
    if ($is_masked) {
        $mask = substr($bytes, $offset, 4);
        $offset += 4;
    }
    
    $payload = substr($bytes, $offset, $payload_length);
    
    if ($is_masked && $mask) {
        for ($i = 0; $i < $payload_length; $i++) {
            $payload[$i] = $payload[$i] ^ $mask[$i % 4];
        }
    }
    
    $types = [
        0x1 => 'text',
        0x2 => 'binary',
        0x8 => 'close',
        0x9 => 'ping',
        0xA => 'pong',
    ];
    
    return [
        'type' => $types[$opcode] ?? 'unknown',
        'payload' => $payload,
    ];
}

function ws_send($socket, $data) {
    $payload = $data;
    $length = strlen($payload);
    
    $response = chr(0x81); // text frame
    
    if ($length < 126) {
        $response .= chr($length);
    } elseif ($length < 65536) {
        $response .= chr(126) . pack('n', $length);
    } else {
        $response .= chr(127) . pack('J', $length);
    }
    
    $response .= $payload;
    @socket_write($socket, $response);
}

function ws_send_json($socket, $data) {
    ws_send($socket, json_encode($data, JSON_UNESCAPED_UNICODE));
}

function validate_ssh_token($token) {
    if (function_exists('validate_ssh_token_db')) {
        $result = validate_ssh_token_db($token);
        if ($result) {
            return $result;
        }
    }
    return false;
}

function start_ssh_session($ssh_info, $client_id) {
    $ip = $ssh_info['ip'];
    $port = intval($ssh_info['port'] ?? 22);
    $user = $ssh_info['user'] ?? 'root';
    $password = $ssh_info['password'] ?? '';
    
    // 使用sshpass + ssh
    $cmd = '';
    if (!empty($password)) {
        $cmd = sprintf('sshpass -p %s ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -p %d %s@%s -tt 2>&1',
            escapeshellarg($password),
            $port,
            escapeshellarg($user),
            escapeshellarg($ip)
        );
    } else {
        $cmd = sprintf('ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -p %d %s@%s -tt 2>&1',
            $port,
            escapeshellarg($user),
            escapeshellarg($ip)
        );
    }
    
    $descriptorspec = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];
    
    $process = proc_open($cmd, $descriptorspec, $pipes);
    
    if (is_resource($process)) {
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        
        return [
            'process' => $process,
            'pipes' => $pipes,
        ];
    }
    
    return false;
}

function resize_terminal($proc, $cols, $rows) {
    // 尝试使用stty调整大小
    if (is_resource($proc['pipes'][0])) {
        fwrite($proc['pipes'][0], "\x1b[8;{$rows};{$cols}t");
    }
}
