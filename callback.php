<?php
require 'db.php';
require __DIR__ . '/helpers/sms.php';
header('Content-Type: application/json');

/*
|-------------------------------------------------------------------------- 
| CONFIG
|-------------------------------------------------------------------------- 
*/

$adminPhone = '254769112320'; // admin number (E.164 format)

/*
|--------------------------------------------------------------------------
| 1. READ RAW PAYLOAD + LOG
|--------------------------------------------------------------------------
*/

$raw = file_get_contents("php://input");

if (!$raw) {
    http_response_code(400);
    echo json_encode(["error" => "No payload"]);
    exit;
}

$payload = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON"]);
    exit;
}

/*
|--------------------------------------------------------------------------
| 2. NORMALIZE LIPWA FIELDS
|--------------------------------------------------------------------------
*/

$mpesa_code =
    $payload['transaction_id']
    ?? $payload['mpesa_code']
    ?? null;

$api_ref =
    $payload['api_ref']
    ?? $payload['merchant_reference']
    ?? $payload['reference']
    ?? $payload['order_id']
    ?? null;

// Checkout ID (STK push ID)
$checkout_id =
    $payload['CheckoutRequestID']
    ?? $payload['checkout_request_id']
    ?? $payload['checkout_id']
    ?? null;

$phone =
    $payload['phone']
    ?? $payload['phone_number']
    ?? null;

$amount =
    $payload['amount']
    ?? $payload['value']
    ?? 0;

$status_raw = strtolower($payload['status'] ?? '');
$mpesa = trim((string)($payload['mpesa_code'] ?? ''));

/*
|--------------------------------------------------------------------------
| 3. STATUS RESOLUTION (FINAL – CANCEL SAFE)
|--------------------------------------------------------------------------
*/

$status_raw = strtolower(trim($payload['status'] ?? ''));

$resultCode = isset($payload['ResultCode'])
    ? (int)$payload['ResultCode']
    : null;

/*
 Rules:
 - ResultCode = 1032 → cancelled
 - ResultCode = 0 → completed
 - payment.success → completed
 - payment.failed + no mpesa_code → cancelled
 - otherwise → failed
*/

if ($resultCode === 0) {
    $newStatus = 'completed';

} elseif ($resultCode === 1032) {
    $newStatus = 'cancelled';

} elseif ($status_raw === 'payment.success') {
    $newStatus = 'completed';

} elseif ($status_raw === 'payment.failed' && empty($mpesa_code)) {
    // 👈 THIS is user cancelling the prompt
    $newStatus = 'cancelled';

} elseif ($status_raw === 'payment.failed') {
    $newStatus = 'failed';

} else {
    $newStatus = 'pending';
}



/*
|--------------------------------------------------------------------------
| 4. VALIDATE PAYMENT RECORD (dlink_network.payments)
|--------------------------------------------------------------------------
*/

if (!$api_ref) {
    http_response_code(400);
    echo json_encode(["error" => "Missing api_ref"]);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM payments WHERE api_ref = ?");
$stmt->execute([$api_ref]);
$payment = $stmt->fetch();

if (!$payment) {
    http_response_code(404);
    echo json_encode([
        "error" => "Payment not found",
        "api_ref" => $api_ref
    ]);
    exit;
}


/*
|-------------------------------------------------------------------------- 
| 4.1 PREVENT DOWNGRADING A COMPLETED PAYMENT  ✅ HERE
|-------------------------------------------------------------------------- 
*/

if ($payment['status'] === 'completed') {
    // Payment already finalized – ignore duplicate callbacks
    http_response_code(200);
    echo json_encode([
        "received" => true,
        "message" => "Payment already completed"
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| 5. UPDATE PAYMENT
|--------------------------------------------------------------------------
*/

$update = $pdo->prepare("
    UPDATE payments
    SET 
        status = ?,
        checkout_id = ?, 
        transaction_id = ?,
        amount = ?,
        phone_number= ?,
        payment_date = NOW()
    WHERE api_ref = ?
    LIMIT 1
");

$update->execute([
    $newStatus,
    $checkout_id,
    $mpesa_code,     // ONLY store mpesa code if provided
    $amount,
    $phone,
    $api_ref
]);

/*
|-------------------------------------------------------------------------- 
| 5.1 UPDATE USER CREATED_AT + EXPIRY AFTER SUCCESSFUL PAYMENT
|-------------------------------------------------------------------------- 
*/

if ($newStatus === 'completed') {

    $userId = (int)$payment['user_id'];

    // Use actual payment date (NOW, since payment just completed)
    $paymentDate = date('Y-m-d H:i:s');

    $updateUser = $pdo->prepare("
        UPDATE users
        SET
            created_at = ?,   -- 👈 set created_at to payment date
            Expiry = CASE
                WHEN Expiry IS NULL OR Expiry < ?
                    THEN DATE_ADD(?, INTERVAL 30 DAY)
                ELSE DATE_ADD(Expiry, INTERVAL 30 DAY)
            END,
            status = 'queued'
        WHERE id = ?
        LIMIT 1
    ");

    $updateUser->execute([
        $paymentDate, // created_at
        $paymentDate, // Expiry < payment_date check
        $paymentDate, // start date if expired
        $userId
    ]);

     // Get user details
    $stmt = $pdo->prepare("
        SELECT username, first_name, phone_number 
        FROM users 
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if ($user) {

        $userPhone = $user['phone_number'];
        $userName  = $user['first_name'];
        $userNameAcc  = $user['username'];
        $amountPaid = number_format($payment['amount'], 2);
        $mpesaCode = $mpesa_code ?: 'N/A';

        // USER SMS
        $userMessage = "Dear $userName, your payment of KES $amountPaid was successful. From D-LINK NETWORK INC."
                     . "Transaction Code: $mpesaCode. "
                     . "Your internet service is being reconnected. Thank you.";

        sendTalkSasaSMS($userPhone, $userMessage);

        // ADMIN SMS
        $adminMessage = "PAYMENT RECEIVED: $userName paid KES $amountPaid. "
                      . "Phone: $userPhone | Code: $mpesaCode"
                      ."USERNAME: $userNameAcc";

        sendTalkSasaSMS($adminPhone, $adminMessage);
    }
}


/*
|-------------------------------------------------------------------------- 
| 5.1 UPDATE USER STATUS → QUEUED (ON SUCCESS ONLY)
|-------------------------------------------------------------------------- 
*/

if ($newStatus === 'completed') {

    // Move user to queued ONLY if currently inactive
    $userUpdate = $pdo->prepare("
        UPDATE users
        SET status = 'queued'
        WHERE id = ?
        AND status = 'inactive'
        LIMIT 1
    ");

    $userUpdate->execute([
        $payment['user_id']
    ]);
}


/*
|--------------------------------------------------------------------------
| 6. SUCCESS RESPONSE
|--------------------------------------------------------------------------
*/

http_response_code(200);
echo json_encode([
    "received" => true,
    "api_ref"  => $api_ref,
    "status"   => $newStatus,
    "transaction_id" => $mpesa_code
]);

 
