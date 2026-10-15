<?php
/**
 * Zakara International - Newsletter Subscription Backend Handler
 * Handles real user subscriptions, sends instant email notifications to admin,
 * and logs subscriber data to CSV/database.
 */

// Set CORS and Header for JSON Response
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=UTF-8');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method. Only POST is allowed.'
    ]);
    exit();
}

// Get JSON or Form POST Data
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data) {
    $data = $_POST;
}

// Extract and Sanitize Inputs
$name     = isset($data['name']) ? trim(strip_tags($data['name'])) : '';
$email    = isset($data['email']) ? filter_var(trim($data['email']), FILTER_SANITIZE_EMAIL) : '';
$location = isset($data['location']) ? trim(strip_tags($data['location'])) : '';
$source   = isset($data['source']) ? trim(strip_tags($data['source'])) : 'Zakara Website';

// Validate Required Fields
if (empty($name) || empty($email) || empty($location)) {
    echo json_encode([
        'success' => false,
        'message' => 'Please fill in all required fields (Name, Email, Location).'
    ]);
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'success' => false,
        'message' => 'Please enter a valid email address.'
    ]);
    exit();
}

// Admin Notification Email Configuration
$adminEmail   = 'enquiry@zakarabags.com'; // Admin notification email address
$subject      = '🔔 New Inner Circle Newsletter Subscription - ' . $name;
$userIp       = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
$timestamp    = date('F j, Y, g:i a T');

// Construct HTML Email Notification Body
$emailBody = "
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f5f0ea; padding: 20px; color: #3b302c; }
        .card { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #c28a5a; padding: 24px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .header { background: #6b4537; color: #ffffff; padding: 16px 20px; border-radius: 8px; text-align: center; margin-bottom: 20px; }
        .header h2 { margin: 0; font-size: 20px; font-family: Georgia, serif; }
        .detail-row { padding: 10px 0; border-bottom: 1px solid #eee; font-size: 14px; }
        .detail-row strong { color: #6b4537; width: 140px; display: inline-block; }
        .footer-note { font-size: 12px; color: #888; text-align: center; margin-top: 20px; }
    </style>
</head>
<body>
    <div class='card'>
        <div class='header'>
            <h2>Zakara International</h2>
            <p style='margin: 4px 0 0 0; font-size: 13px; color: #eadcc8;'>New Newsletter Subscription Alert</p>
        </div>
        <div class='detail-row'><strong>Full Name:</strong> " . htmlspecialchars($name) . "</div>
        <div class='detail-row'><strong>Email Address:</strong> <a href='mailto:" . htmlspecialchars($email) . "'>" . htmlspecialchars($email) . "</a></div>
        <div class='detail-row'><strong>City / Country:</strong> " . htmlspecialchars($location) . "</div>
        <div class='detail-row'><strong>Subscribed On:</strong> " . $timestamp . "</div>
        <div class='detail-row'><strong>IP Address:</strong> " . htmlspecialchars($userIp) . "</div>
        <div class='detail-row'><strong>Source Page:</strong> " . htmlspecialchars($source) . "</div>
        <div class='footer-note'>
            This notification was generated automatically by Zakara International Website.
        </div>
    </div>
</body>
</html>
";

// Headers for HTML Mail
$headers  = "MIME-Version: 1.0" . "\r\n";
$headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
$headers .= "From: Zakara Subscriptions <no-reply@zakarabags.com>" . "\r\n";
$headers .= "Reply-To: " . $email . "\r\n";

// 1. Send Email Notification to Admin
$mailSent = @mail($adminEmail, $subject, $emailBody, $headers);

// 2. Log Subscription Data to local CSV file (subscribers.csv)
$logFile = __DIR__ . '/subscribers.csv';
$isNewFile = !file_exists($logFile);

$fileHandler = fopen($logFile, 'a');
if ($fileHandler) {
    if ($isNewFile) {
        fputcsv($fileHandler, ['Date & Time', 'Full Name', 'Email Address', 'Location', 'IP Address', 'Source']);
    }
    fputcsv($fileHandler, [$timestamp, $name, $email, $location, $userIp, $source]);
    fclose($fileHandler);
}

// 3. Optional: Trigger Webhook (Telegram / Slack / Make.com) if WEBHOOK_URL defined
$webhookUrl = ''; // Add your Slack/Telegram/Webhook URL here if desired
if (!empty($webhookUrl)) {
    $webhookData = json_encode([
        'text' => "🔔 *New Zakara Newsletter Subscriber*\n*Name:* {$name}\n*Email:* {$email}\n*Location:* {$location}"
    ]);
    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $webhookData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_exec($ch);
    curl_close($ch);
}

// Return JSON Response
echo json_encode([
    'success' => true,
    'message' => 'Thank you for subscribing! Your details have been received.',
    'mail_sent' => $mailSent
]);
exit();
