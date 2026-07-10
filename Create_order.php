<?php
/**
 * create_order.php
 * -----------------
 * Receives the checkout form POST from checkout.html, revalidates the
 * order server-side against catalog.php (never trusts a client-sent
 * price), then performs Step 1 + Step 2 of the Bank Alfalah APG
 * handshake and auto-redirects the customer to the secure APG
 * checkout/payment page.
 */

require __DIR__ . '/apg_helpers.php';
$catalog = require __DIR__ . '/catalog.php';
$cfg = apg_config();

header('Content-Type: text/html; charset=utf-8');

function fail_page($message) {
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Order Error</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#0a0a0a;color:#fff;display:flex;
    align-items:center;justify-content:center;height:100vh;margin:0;}
    .box{max-width:480px;text-align:center;padding:40px;border:1px solid #262626;border-radius:14px;}
    a{color:#FF1A1A;}</style></head><body><div class="box">';
    echo '<h2>We couldn\'t start your payment</h2><p>' . apg_safe_html($message) . '</p>';
    echo '<p><a href="javascript:history.back()">Go back and try again</a></p>';
    echo '</div></body></html>';
    exit;
}

/* ---------------------------------------------------------------
 * 1. Read + validate input
 * ------------------------------------------------------------- */
$service   = $_POST['service']   ?? '';
$packageId = $_POST['package']   ?? '';
$extrasIn  = $_POST['extras']    ?? []; // array of extra ids
$promo     = strtoupper(trim($_POST['promo'] ?? ''));

$firstName = trim($_POST['first_name'] ?? '');
$lastName  = trim($_POST['last_name']  ?? '');
$email     = trim($_POST['email']      ?? '');
$phone     = trim($_POST['phone']      ?? '');
$termsOk   = ($_POST['terms'] ?? '') === '1';

if (!$termsOk) {
    fail_page('You must accept the Terms & Conditions, Privacy Policy, and Refund Policy before paying.');
}
if (!isset($catalog['services'][$service])) {
    fail_page('Please choose a valid service.');
}
if (!isset($catalog['packages'][$service][$packageId])) {
    fail_page('Please choose a valid package.');
}
if ($firstName === '' || $lastName === '' || $email === '' || $phone === '') {
    fail_page('Please complete your name, email and phone number.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail_page('Please provide a valid email address.');
}

$package = $catalog['packages'][$service][$packageId];
$subtotal = $package['price'];

$extraNames = [];
if (is_array($extrasIn)) {
    foreach ($extrasIn as $exId) {
        if (isset($catalog['extras'][$exId])) {
            $subtotal += $catalog['extras'][$exId]['price'];
            $extraNames[] = $catalog['extras'][$exId]['name'];
        }
    }
}

$discountRate = 0;
if ($promo !== '' && isset($catalog['promo_codes'][$promo])) {
    $discountRate = $catalog['promo_codes'][$promo];
}
$discount = round($subtotal * $discountRate);
$total = $subtotal - $discount;

if ($total <= 0) {
    fail_page('The order total must be greater than zero.');
}

/* ---------------------------------------------------------------
 * 2. Create + store the order
 * ------------------------------------------------------------- */
$orderRef = 'PV' . date('ymd') . rand(1000, 9999) . substr((string) time(), -4);

apg_store_order($orderRef, [
    'service'        => $catalog['services'][$service],
    'package'        => $package['name'],
    'extras'         => $extraNames,
    'subtotal'       => $subtotal,
    'discount'       => $discount,
    'total'          => $total,
    'currency'       => 'PKR',
    'first_name'     => $firstName,
    'last_name'      => $lastName,
    'email'          => $email,
    'phone'          => $phone,
    'status'         => 'Pending',
]);

/* ---------------------------------------------------------------
 * 3. STEP 1 — Handshake: POST to HS endpoint, get AuthToken back
 * ------------------------------------------------------------- */
$hsIsRedirect = '1'; // handle the auth token server-side, no extra hop for the customer

$mapString =
    'HS_ChannelId=' . $cfg['channel_id'] .
    '&HS_IsRedirectionRequest=' . $hsIsRedirect .
    '&HS_MerchantId=' . $cfg['merchant_id'] .
    '&HS_StoreId=' . $cfg['store_id'] .
    '&HS_ReturnURL=' . $cfg['return_url'] .
    '&HS_MerchantHash=' . $cfg['merchant_hash'] .
    '&HS_MerchantUsername=' . $cfg['merchant_username'] .
    '&HS_MerchantPassword=' . $cfg['merchant_password'] .
    '&HS_TransactionReferenceNumber=' . $orderRef;

try {
    $hsRequestHash = apg_encrypt_hash($mapString, $cfg['key1'], $cfg['key2']);

    $hsFields = [
        'HS_ChannelId'                  => $cfg['channel_id'],
        'HS_IsRedirectionRequest'       => $hsIsRedirect,
        'HS_MerchantId'                 => $cfg['merchant_id'],
        'HS_StoreId'                    => $cfg['store_id'],
        'HS_ReturnURL'                  => $cfg['return_url'],
        'HS_MerchantHash'               => $cfg['merchant_hash'],
        'HS_MerchantUsername'           => $cfg['merchant_username'],
        'HS_MerchantPassword'           => $cfg['merchant_password'],
        'HS_TransactionReferenceNumber' => $orderRef,
        'HS_RequestHash'                => $hsRequestHash,
    ];

    $hsResponseRaw = apg_curl_post($cfg['hs_url'], $hsFields);
    $hsResponse = json_decode($hsResponseRaw, true);

    if (!is_array($hsResponse) || empty($hsResponse['success']) || $hsResponse['success'] !== 'true' || empty($hsResponse['AuthToken'])) {
        $errMsg = $hsResponse['ErrorMessage'] ?? 'Bank Alfalah rejected the handshake request.';
        apg_update_order($orderRef, ['status' => 'HandshakeFailed', 'error' => $errMsg]);
        fail_page($errMsg);
    }

    $authToken = $hsResponse['AuthToken'];

} catch (Throwable $e) {
    apg_update_order($orderRef, ['status' => 'HandshakeError', 'error' => $e->getMessage()]);
    fail_page('A connection error occurred while contacting the payment gateway. Please try again shortly.');
}

/* ---------------------------------------------------------------
 * 4. STEP 2 — SSO: build the RequestHash for the payment page and
 *    render an auto-submitting form that POSTs the customer to it.
 * ------------------------------------------------------------- */
$currency = 'PKR';
$isBin = '0';
$transactionTypeId = '3'; // Debit/Credit Card per the integration guide
$transactionAmount = number_format($total, 2, '.', '');

$mapStringSso =
    'AuthToken=' . $authToken .
    '&RequestHash=' .            // left blank before hashing, per Bank Alfalah's sample
    '&ChannelId=' . $cfg['channel_id'] .
    '&Currency=' . $currency .
    '&IsBIN=' . $isBin .
    '&ReturnURL=' . $cfg['return_url'] .
    '&MerchantId=' . $cfg['merchant_id'] .
    '&StoreId=' . $cfg['store_id'] .
    '&MerchantHash=' . $cfg['merchant_hash'] .
    '&MerchantUsername=' . $cfg['merchant_username'] .
    '&MerchantPassword=' . $cfg['merchant_password'] .
    '&TransactionTypeId=' . $transactionTypeId .
    '&TransactionReferenceNumber=' . $orderRef .
    '&TransactionAmount=' . $transactionAmount;

try {
    $ssoRequestHash = apg_encrypt_hash($mapStringSso, $cfg['key1'], $cfg['key2']);
} catch (Throwable $e) {
    apg_update_order($orderRef, ['status' => 'SsoHashError', 'error' => $e->getMessage()]);
    fail_page('Could not prepare a secure payment session. Please try again shortly.');
}

apg_update_order($orderRef, ['status' => 'AwaitingPayment', 'auth_token_issued' => true]);

$ssoFieldsHtml = [
    'AuthToken'                  => $authToken,
    'RequestHash'                => $ssoRequestHash,
    'ChannelId'                  => $cfg['channel_id'],
    'Currency'                   => $currency,
    'IsBIN'                      => $isBin,
    'ReturnURL'                  => $cfg['return_url'],
    'MerchantId'                 => $cfg['merchant_id'],
    'StoreId'                    => $cfg['store_id'],
    'MerchantHash'               => $cfg['merchant_hash'],
    'MerchantUsername'           => $cfg['merchant_username'],
    'MerchantPassword'           => $cfg['merchant_password'],
    'TransactionTypeId'          => $transactionTypeId,
    'TransactionReferenceNumber' => $orderRef,
    'TransactionAmount'          => $transactionAmount,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Redirecting to secure payment…</title>
<style>
  body{background:#0a0a0a;color:#fff;font-family:Arial,sans-serif;display:flex;align-items:center;
    justify-content:center;height:100vh;margin:0;}
  .box{text-align:center;}
  .spin{width:46px;height:46px;border-radius:50%;border:3px solid #262626;border-top-color:#FF1A1A;
    animation:spin .8s linear infinite;margin:0 auto 18px;}
  @keyframes spin{to{transform:rotate(360deg);}}
</style>
</head>
<body>
  <div class="box">
    <div class="spin"></div>
    <p>Redirecting you to Bank Alfalah's secure checkout…</p>
  </div>
  <form id="ssoForm" action="<?php echo apg_safe_html($cfg['sso_url']); ?>" method="post">
    <?php foreach ($ssoFieldsHtml as $name => $value): ?>
      <input type="hidden" name="<?php echo apg_safe_html($name); ?>" value="<?php echo apg_safe_html($value); ?>">
    <?php endforeach; ?>
  </form>
  <script>document.getElementById('ssoForm').submit();</script>
</body>
</html>