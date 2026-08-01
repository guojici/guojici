<?php
function handle_billing_api($method, $resource_id, $action) {
    global $api_auth;
    $uid = $api_auth['user_id'];
    migrate_new_tables();
    
    if ($method === 'GET' && $resource_id === 'records' && !$action) {
        $page = max(1, intval(api_param('page', 1)));
        $page_size = min(100, max(1, intval(api_param('page_size', 20))));
        $bill_type = api_param('bill_type', '');
        $period = api_param('period', '');
        $start_date = api_param('start_date', '');
        $end_date = api_param('end_date', '');
        
        $where = "WHERE user_id = ?";
        $params = [$uid];
        
        if ($bill_type) {
            $where .= " AND bill_type = ?";
            $params[] = $bill_type;
        }
        if ($period) {
            $where .= " AND billing_period = ?";
            $params[] = $period;
        }
        if ($start_date) {
            $where .= " AND created_at >= ?";
            $params[] = $start_date . ' 00:00:00';
        }
        if ($end_date) {
            $where .= " AND created_at <= ?";
            $params[] = $end_date . ' 23:59:59';
        }
        
        $total = Database::fetch("SELECT COUNT(*) as cnt FROM billing_records $where", $params);
        $offset = ($page - 1) * $page_size;
        $records = Database::fetchAll("SELECT * FROM billing_records $where ORDER BY id DESC LIMIT ? OFFSET ?", array_merge($params, [$page_size, $offset]));
        
        $summary = Database::fetch("SELECT 
            SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as total_income,
            SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as total_refund
            FROM billing_records $where", $params);
        
        api_success([
            'list' => $records,
            'total' => intval($total['cnt']),
            'page' => $page,
            'page_size' => $page_size,
            'summary' => [
                'total_income' => floatval($summary['total_income'] ?? 0),
                'total_refund' => floatval($summary['total_refund'] ?? 0),
                'net_income' => floatval(($summary['total_income'] ?? 0) - ($summary['total_refund'] ?? 0)),
            ],
        ]);
    }
    
    if ($method === 'GET' && $resource_id === 'summary') {
        $month = api_param('month', date('Y-m'));
        
        $month_income = Database::fetch("SELECT SUM(amount) as total FROM billing_records 
            WHERE user_id = ? AND bill_type IN ('package','renew','upgrade','hourly') AND billing_period LIKE ?", 
            [$uid, $month . '%']);
        
        $month_refund = Database::fetch("SELECT SUM(ABS(amount)) as total FROM billing_records 
            WHERE user_id = ? AND bill_type IN ('refund') AND billing_period LIKE ?", 
            [$uid, $month . '%']);
        
        $balance = Database::fetch("SELECT balance FROM users WHERE id = ?", [$uid]);
        
        api_success([
            'month' => $month,
            'month_income' => floatval($month_income['total'] ?? 0),
            'month_refund' => floatval($month_refund['total'] ?? 0),
            'current_balance' => floatval($balance['balance'] ?? 0),
        ]);
    }
    
    if ($method === 'GET' && $resource_id === 'balance') {
        $user = Database::fetch("SELECT balance FROM users WHERE id = ?", [$uid]);
        api_success([
            'balance' => floatval($user['balance'] ?? 0),
            'currency' => 'CNY',
        ]);
    }
    
    api_error(40404, '接口不存在', 404);
}
