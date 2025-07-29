<?php
header('Content-Type: application/json');

// ✅ ตั้งค่า Webhook URL ของ Discord
define("DISCORD_WEBHOOK", "https://discordapp.com/api/webhooks/1397834045970190336/lKmc4lzCA35IbrArFbMNeByFSG4ZxyBjZHH1EWJXv1YP3c5SJER9_IHNhncNUfUfFeEJ");

// ✅ ไฟล์บันทึกสถานะล่าสุด
define("STATUS_FILE", 'device_status.json');

// ✅ โหลดสถานะล่าสุด (ถ้ามี)
$previousStatus = file_exists(STATUS_FILE) ? json_decode(file_get_contents(STATUS_FILE), true) : [];

// ✅ รายการอุปกรณ์แบบกลุ่ม
$devices = [
    "floor 1" => ["192.168.110.1", "192.168.110.6", "192.168.110.15", "192.168.110.20", "192.168.110.52"],
    "floor 2" => ["192.168.110.58", "192.168.110.62", "192.168.110.63"],
    "floor 3" => ["192.168.110.91", "192.168.110.93", "192.168.110.95", "192.168.110.97", "192.168.110.99"],
    "floor 4" => ["192.168.110.50", "192.168.110.51"],
    "floor 5" => ["192.168.110.56"],
    "floor 6 (THE-HUB DVR)" => ["192.168.110.31", "192.168.110.32", "192.168.110.33"],
    "floor 7 (ZEER DVR)" => ["192.168.110.215"],
    "floor 8 (ASIA DVR)" => ["192.168.110.101", "192.168.110.102", "192.168.110.107", "192.168.110.111"]
];

// ✅ ฟังก์ชัน ping พร้อมวัด latency
function isOnline($ip, &$latency = null) {
    $latency = null;
    if (stripos(PHP_OS, 'WIN') === 0) {
    $cmd = "ping -n 1 -w 1000 $ip";
    exec($cmd, $output, $result);
    if ($result === 0) {
        foreach ($output as $line) {
            // ✅ รองรับ Windows ภาษาอังกฤษและไทย
            if (preg_match('/(time|เวลา)[=<]?([0-9]+)ms/', $line, $matches)) {
                $latency = (int)$matches[2]; // ดึงค่า ms จาก group 2
                break;
            }
        }
    }
    } else {
        $cmd = "ping -c 1 -W 1 $ip";
        exec($cmd, $output, $result);
        if ($result === 0) {
            foreach ($output as $line) {
                if (preg_match('/time=([0-9.]+) ms/', $line, $matches)) {
                    $latency = round((float)$matches[1]);
                    break;
                }
            }
        }
    }
    return $result === 0;
}

function sendDiscordWebhook($message) {
    $payload = json_encode(["content" => $message]);
    $ch = curl_init(DISCORD_WEBHOOK);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // ✅ สำหรับ Debug
    file_put_contents("discord_debug.log", date('Y-m-d H:i:s') . " | HTTP $httpCode | $response | $error\n", FILE_APPEND);
}

// ✅ เช็คสถานะ
$currentStatus = [];
$messages = [];
$displayData = [];

foreach ($devices as $floor => $ips) {
    foreach ($ips as $ip) {
        $latency = null;
        $online = isOnline($ip, $latency);

        $currentStatus[$ip] = $online;

        $wasOnline = isset($previousStatus[$ip]) ? $previousStatus[$ip] : null;

        if ($wasOnline !== null && $wasOnline !== $online) {
            $statusText = $online ? "🟢 ONLINE" : "🔴 OFFLINE";
            $messages[] = "$ip ($floor) เปลี่ยนสถานะเป็น $statusText";
        }

        // ✅ เตรียมข้อมูลสำหรับ frontend
        $displayData[$floor][] = [
            "ip" => $ip,
            "status" => $online ? "online" : "offline",
            "latency_ms" => $online ? $latency : null
        ];
    }
}

// ✅ บันทึกสถานะใหม่
file_put_contents(STATUS_FILE, json_encode($currentStatus, JSON_PRETTY_PRINT));

// ✅ ส่งข้อความหากมีอุปกรณ์เปลี่ยนสถานะ
if (!empty($messages)) {
    sendDiscordWebhook("🔔 อุปกรณ์เปลี่ยนสถานะ:\n" . implode("\n", $messages));
}

// ✅ ส่งข้อมูลไปให้ dashboard ใช้งาน
echo json_encode($displayData);
