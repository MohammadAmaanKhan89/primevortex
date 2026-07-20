<?php
/**
 * create_order.php - FINAL FIXED VERSION (Sandbox Ready)
 */

require __DIR__ . '/apg_helpers.php';
require __DIR__ . '/apg_client.php';

$catalog = require __DIR__ . '/catalog.php';
$cfg = apg_config();                          // flattened config, used by apg_store_order()/apg_update_order()
$rawConfig = require __DIR__ . '/config.php';  // raw config, used by ApgClient (needs 'environment' + 'sandbox'/'production' keys)

function fail_page($message) {
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Payment Error</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#0a0a0a;color:#fff;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
    .box{max-width:520px;padding:40px;border:1px solid #444;border-radius:12px;text-align:center;}</style></head><body>';
    echo '<div class="box"><h2>Payment Could Not Start</h2><p>' . htmlspecialchars($message) . '</p>';
    echo '<p><a href="javascript:history.back()" style="color:#FF1A1A">← Go Back</a></p></div></body></html>';
    exit;
}

// ==================== 1. Get Form Data ====================
$service    = $_POST['service'] ?? '';
$packageId  = $_POST['package'] ?? '';
$extrasIn   = $_POST['extras'] ?? [];
$promo      = strtoupper(trim($_POST['promo'] ?? ''));

$firstName = trim($_POST['first_name'] ?? '');
$lastName  = trim($_POST['last_name'] ?? '');
$email     = trim($_POST['email'] ?? '');
$phone     = trim($_POST['phone'] ?? '');

if (empty($service) || empty($packageId) || empty($firstName) || empty($lastName) || empty($email) || empty($phone)) {
    fail_page('Please fill all required fields.');
}

// ==================== 2. Calculate Total ====================
$package = $catalog['packages'][$service][$packageId] ?? null;
if (!$package) fail_page('Invalid package selected.');

$subtotal = $package['price'];
foreach ($extrasIn as $id) {
    if (isset($catalog['extras'][$id])) {
        $subtotal += $catalog['extras'][$id]['price'];
    }
}

$discount = 0;
if ($promo && isset($catalog['promo_codes'][$promo])) {
    $discount = (int)($subtotal * $catalog['promo_codes'][$promo]);
}

$total = $subtotal - $discount;
if ($total <= 0) fail_page('Invalid order amount.');

// ==================== 3. Save Order ====================
$orderRef = 'PV' . date('ymdHi') . rand(100, 999);

apg_store_order($orderRef, [
    'service'    => $catalog['services'][$service],
    'package'    => $package['name'],
    'extras'     => array_map(fn($id) => $catalog['extras'][$id]['name'] ?? $id, $extrasIn),
    'subtotal'   => $subtotal,
    'discount'   => $discount,
    'total'      => $total,
    'first_name' => $firstName,
    'last_name'  => $lastName,
    'email'      => $email,
    'phone'      => $phone,
    'status'     => 'Pending',
]);

// ==================== 4. Bank Alfalah Payment ====================
try {
    $client = new ApgClient($rawConfig);   // ApgClient needs the raw config.php structure, not the flattened apg_config()

    $handshake = $client->initiateHandshake($orderRef);

    if (!$handshake['success'] || empty($handshake['auth_token'])) {
        throw new Exception($handshake['error'] ?? 'Handshake failed');
    }

    echo $client->buildSsoRedirectForm(
        $handshake['auth_token'],
        $orderRef,
        number_format($total, 2, '.', '')
    );

} catch (Throwable $e) {
    apg_update_order($orderRef, ['status' => 'Failed', 'error' => $e->getMessage()]);
    fail_page('Payment Error: ' . $e->getMessage());
}