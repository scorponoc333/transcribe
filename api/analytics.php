<?php
/**
 * Analytics API - Returns aggregated usage data for dashboard
 * GET → returns totals, monthly breakdowns, model usage
 */
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_middleware.php';
requireRole(['admin','manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $db = getDB();

    // 1. Totals
    $totals = $db->query("SELECT
        COUNT(*) as total_transcriptions,
        COALESCE(SUM(word_count), 0) as total_words,
        COALESCE(AVG(timer_seconds), 0) as avg_timer_seconds,
        COALESCE(SUM(char_count), 0) as total_chars
    FROM transcriptions")->fetch(PDO::FETCH_ASSOC);

    $emailCount = $db->query("SELECT COUNT(*) as cnt FROM email_log WHERE status = 'sent'")->fetch(PDO::FETCH_ASSOC);
    $totals['total_emails'] = (int) $emailCount['cnt'];
    $totals['total_transcriptions'] = (int) $totals['total_transcriptions'];
    $totals['total_words'] = (int) $totals['total_words'];
    $totals['avg_timer_seconds'] = round((float) $totals['avg_timer_seconds'], 1);

    // 2. Monthly transcriptions (last 12 months), split by mode
    $monthly = $db->query("SELECT
        DATE_FORMAT(created_at, '%Y-%m') as month,
        SUM(CASE WHEN mode = 'recording' THEN 1 ELSE 0 END) as recordings,
        SUM(CASE WHEN mode = 'meeting' THEN 1 ELSE 0 END) as meetings
    FROM transcriptions
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($monthly as &$row) {
        $row['recordings'] = (int) $row['recordings'];
        $row['meetings'] = (int) $row['meetings'];
    }

    // 3. Monthly emails (last 12 months)
    $monthlyEmails = $db->query("SELECT
        DATE_FORMAT(sent_at, '%Y-%m') as month,
        COUNT(*) as count
    FROM email_log
    WHERE status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(sent_at, '%Y-%m')
    ORDER BY month ASC")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($monthlyEmails as &$row) {
        $row['count'] = (int) $row['count'];
    }

    // 4. Average transcription time per month
    $avgTime = $db->query("SELECT
        DATE_FORMAT(created_at, '%Y-%m') as month,
        AVG(timer_seconds) as avg_seconds
    FROM transcriptions
    WHERE timer_seconds IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($avgTime as &$row) {
        $row['avg_seconds'] = round((float) $row['avg_seconds'], 1);
    }

    // 5. Model usage
    $modelUsage = $db->query("SELECT
        whisper_model as model,
        COUNT(*) as count
    FROM transcriptions
    GROUP BY whisper_model
    ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($modelUsage as &$row) {
        $row['count'] = (int) $row['count'];
    }

    // 6. AI cost totals
    $costTotals = $db->query("SELECT
        COUNT(*) as total_operations,
        COALESCE(SUM(cost_usd), 0) as total_cost_usd,
        COALESCE(AVG(cost_usd), 0) as avg_cost_per_op,
        COALESCE(SUM(prompt_tokens), 0) as total_prompt_tokens,
        COALESCE(SUM(completion_tokens), 0) as total_completion_tokens,
        COALESCE(SUM(total_tokens), 0) as total_tokens
    FROM ai_costs")->fetch(PDO::FETCH_ASSOC);

    $costTotals['total_operations'] = (int) $costTotals['total_operations'];
    $costTotals['total_cost_usd'] = round((float) $costTotals['total_cost_usd'], 6);
    $costTotals['avg_cost_per_op'] = round((float) $costTotals['avg_cost_per_op'], 6);
    $costTotals['total_prompt_tokens'] = (int) $costTotals['total_prompt_tokens'];
    $costTotals['total_completion_tokens'] = (int) $costTotals['total_completion_tokens'];
    $costTotals['total_tokens'] = (int) $costTotals['total_tokens'];

    // 7. Cost by operation type
    $costByOp = $db->query("SELECT
        operation,
        COUNT(*) as count,
        COALESCE(SUM(cost_usd), 0) as total_cost,
        COALESCE(AVG(cost_usd), 0) as avg_cost,
        COALESCE(SUM(total_tokens), 0) as total_tokens
    FROM ai_costs
    GROUP BY operation
    ORDER BY total_cost DESC")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($costByOp as &$row) {
        $row['count'] = (int) $row['count'];
        $row['total_cost'] = round((float) $row['total_cost'], 6);
        $row['avg_cost'] = round((float) $row['avg_cost'], 6);
        $row['total_tokens'] = (int) $row['total_tokens'];
    }

    // 8. Monthly costs
    $monthlyCosts = $db->query("SELECT
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as operations,
        COALESCE(SUM(cost_usd), 0) as total_cost,
        COALESCE(SUM(total_tokens), 0) as total_tokens
    FROM ai_costs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($monthlyCosts as &$row) {
        $row['operations'] = (int) $row['operations'];
        $row['total_cost'] = round((float) $row['total_cost'], 6);
        $row['total_tokens'] = (int) $row['total_tokens'];
    }

    // 9. Cost per AI model
    $costByModel = $db->query("SELECT
        model,
        COUNT(*) as count,
        COALESCE(SUM(cost_usd), 0) as total_cost,
        COALESCE(AVG(cost_usd), 0) as avg_cost
    FROM ai_costs
    GROUP BY model
    ORDER BY total_cost DESC")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($costByModel as &$row) {
        $row['count'] = (int) $row['count'];
        $row['total_cost'] = round((float) $row['total_cost'], 6);
        $row['avg_cost'] = round((float) $row['avg_cost'], 6);
    }

    // 10. Daily costs (last 30 days)
    $dailyCosts = $db->query("SELECT
        DATE(created_at) as day,
        COUNT(*) as operations,
        COALESCE(SUM(cost_usd), 0) as total_cost
    FROM ai_costs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at)
    ORDER BY day ASC")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($dailyCosts as &$row) {
        $row['operations'] = (int) $row['operations'];
        $row['total_cost'] = round((float) $row['total_cost'], 6);
    }

    // 11. User usage (transcriptions per user)
    $userUsage = $db->query("SELECT
        u.name as user_name,
        COUNT(t.id) as transcription_count,
        MAX(t.created_at) as last_activity
    FROM users u
    LEFT JOIN transcriptions t ON u.id = t.user_id
    WHERE u.is_active = 1
    GROUP BY u.id, u.name
    ORDER BY transcription_count DESC
    LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($userUsage as &$row) {
        $row['transcription_count'] = (int) $row['transcription_count'];
    }

    // 12. ROI / Time Saved Metrics
    $roiData = $db->query("SELECT
        COALESCE(SUM(word_count), 0) as total_words,
        COUNT(*) as total_transcriptions,
        COALESCE(SUM(timer_seconds), 0) as total_ai_seconds
    FROM transcriptions")->fetch(PDO::FETCH_ASSOC);

    $emailTotal = (int) $emailCount['cnt'];
    $totalWords = (int) $roiData['total_words'];
    $totalTranscriptions = (int) $roiData['total_transcriptions'];
    $totalAiSeconds = (int) $roiData['total_ai_seconds'];

    $manualTranscriptionMin = $totalWords / 30;
    $manualReportMin = $totalTranscriptions * 15;
    $manualEmailMin = $emailTotal * 5;
    $manualTotalMin = $manualTranscriptionMin + $manualReportMin + $manualEmailMin;
    $aiTotalMin = $totalAiSeconds / 60;
    $timeSavedMin = max(0, $manualTotalMin - $aiTotalMin);
    $timeSavedHrs = $timeSavedMin / 60;

    $roi = [
        'total_time_saved_hours' => round($timeSavedHrs, 1),
        'avg_saved_per_transcription' => $totalTranscriptions > 0 ? round($timeSavedMin / $totalTranscriptions, 1) : 0,
        'estimated_value' => round($timeSavedHrs * 50, 2),
        'active_users' => 0,
        'methodology' => 'Manual transcription estimated at 30 words/minute. Report generation estimated at 15 minutes per report. Email composition estimated at 5 minutes per email. AI processing time is actual recorded processing time.'
    ];

    $activeCount = $db->query("SELECT COUNT(*) as cnt FROM users WHERE is_active = 1 AND last_login_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch(PDO::FETCH_ASSOC);
    $roi['active_users'] = (int) $activeCount['cnt'];

    // Updated monthly transcriptions (includes learning mode)
    $monthlyWithLearning = $db->query("SELECT
        DATE_FORMAT(created_at, '%Y-%m') as month,
        SUM(CASE WHEN mode = 'recording' THEN 1 ELSE 0 END) as recordings,
        SUM(CASE WHEN mode = 'meeting' THEN 1 ELSE 0 END) as meetings,
        SUM(CASE WHEN mode = 'learning' THEN 1 ELSE 0 END) as learning
    FROM transcriptions
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($monthlyWithLearning as &$row) {
        $row['recordings'] = (int) $row['recordings'];
        $row['meetings'] = (int) $row['meetings'];
        $row['learning'] = (int) $row['learning'];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'totals' => $totals,
            'monthly_transcriptions' => $monthlyWithLearning,
            'monthly_emails' => $monthlyEmails,
            'avg_time_monthly' => $avgTime,
            'model_usage' => $modelUsage,
            'cost_totals' => $costTotals,
            'cost_by_operation' => $costByOp,
            'monthly_costs' => $monthlyCosts,
            'cost_by_model' => $costByModel,
            'daily_costs' => $dailyCosts,
            'user_usage' => $userUsage,
            'roi' => $roi,
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
