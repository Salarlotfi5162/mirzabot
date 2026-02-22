<?php
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';

$ManagePanel = new ManagePanel();

// Get active invoices for X-UI and Sanaei panels
$stmt = $pdo->prepare("SELECT * FROM invoice WHERE (Status = 'active' OR Status = 'sendedwarn' OR Status = 'send_on_hold') ORDER BY RAND() LIMIT 50");
$stmt->execute();
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($invoices as $invoice) {
    if (empty($invoice['username']) || empty($invoice['Service_location'])) continue;
    
    // Check panel type
    $panelInfo = select("marzban_panel", "*", "name_panel", $invoice['Service_location'], "select");
    if (!$panelInfo || !in_array($panelInfo['type'], ['x-ui_single', 'alireza_single'])) continue;
    
    $userData = $ManagePanel->DataUser($invoice['Service_location'], $invoice['username']);
    
    if ($userData && $userData['status'] != 'Unsuccessful') {
        // If DataUser says it's limited or expired, but the panel might still have it enabled (since panel checks per inbound)
        if (in_array($userData['status'], ['limited', 'expired'])) {
            // We need to actively disable it on the panel
            // The Change_status function refuses 'limited' status users by default, so we'll do it manually here.
            
$config_to_disable = [
                'settings' => json_encode([
                    'clients' => [
                        [
                            'enable' => false
                        ]
                    ]
                ])
            ];
            
            $ManagePanel->Modifyuser($invoice['username'], $invoice['Service_location'], $config_to_disable);
            
            // Mark invoice in bot DB
            update("invoice", "Status", "end_of_volume", "id_invoice", $invoice['id_invoice']);
            
            // Send warning to user
            $formattedVolume = formatBytes($userData['data_limit'] - $userData['used_traffic']);
            $reportMessage = "📌 اطلاعیه قطع سرویس سنایی/X-UI\n\nنام کاربری سرویس :‌ <code>{$invoice['username']}</code>\nوضعیت سرویس : 🚫 پایان حجم/زمان\nترافیک باقی‌مانده: {$formattedVolume}\n\nسرویس به دلیل اتمام حجم/زمان با موفقیت روی تمام اینباندها غیرفعال شد.";
            
            $reportCron = select("topicid", "idreport", "report", "reportcron", "select");
            $setting = select("setting", "*");
            
            if (!empty($setting['Channel_Report']) && !empty($reportCron['idreport'])) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $reportCron['idreport'],
                    'text' => $reportMessage,
                    'parse_mode' => "HTML"
                ]);
            }
        }
    }
}
echo "Sync Completed.";
?>
