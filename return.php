<?php
echo '<!-- RETURN_PHP_VERSION_MARKER: v3-debug -->';
/**
 * return.php
 * -----------
 * This single URL is visited TWICE by the customer's browser during a
 * payment, and we have to tell the two visits apart:
 *
 * VISIT 1 (handshake callback): right after create_order.php redirects the
 * browser to Bank Alfalah's HS/HS/HS endpoint, Bank Alfalah immediately
 * redirects back here with ?success=...&AuthToken=...&ReturnURL=...
 * We use that AuthToken to build the SSO form and send the browser onward
 * to the actual secure payment page.
 *
 * VISIT 2 (payment result callback): after the customer actually pays (or
 * cancels) on Bank Alfalah's secure page, they land back here again, this
 * time with the order id appended as "O" (e.g. ?O=A10). We take that and
 * call the IPN "Inquire Transaction" endpoint ourselves to get the
 * authoritative TransactionStatus — never trust the redirect alone.
 */

// Session cookie must work on both www.primevortex.co and primevortex.co,
// since this page is landed on from Bank Alfalah's redirect.
session_set_cookie_params(['domain' => '.primevortex.co', 'path' => '/', 'secure' => false, 'samesite' => 'Lax']);
session_start();

require __DIR__ . '/apg_helpers.php';
require __DIR__ . '/apg_client.php';
$cfg = apg_config();
$rawConfig = require __DIR__ . '/config.php';

function render_result($status, $orderRef, $orderData, $extra = '') {
    $isPaid = strtolower($status) === 'paid';
    $color = $isPaid ? '#38d67e' : '#FF1A1A';
    $title = $isPaid ? 'Payment Successful' : 'Payment Not Completed';
    $sub = $isPaid
        ? 'Thank you — your project has been started. A confirmation has been sent to your email.'
        : 'Your transaction was not completed. No charge was made, or it was declined by your bank.';

    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . apg_safe_html($title) . '</title>';
    echo '<style>body{background:#0a0a0a;color:#fff;font-family:Arial,sans-serif;display:flex;
      align-items:center;justify-content:center;min-height:100vh;margin:0;}
      .card{max-width:480px;padding:44px;border:1px solid #262626;border-radius:16px;text-align:center;background:#121212;}
      h2{color:' . $color . ';margin-bottom:10px;} .row{display:flex;justify-content:space-between;
      font-size:13px;color:#a6a6a6;padding:8px 0;border-bottom:1px solid #262626;text-align:left;}
      .row span.v{color:#fff;font-weight:600;} a.btn{display:inline-block;margin-top:20px;background:#FF1A1A;
      color:#fff;padding:12px 22px;border-radius:10px;text-decoration:none;font-weight:700;}</style></head><body>';
    echo '<div class="card"><h2>' . apg_safe_html($title) . '</h2><p style="color:#a6a6a6;font-size:14px;">' . apg_safe_html($sub) . '</p>';
    echo '<div style="margin-top:22px;">';
    echo '<div class="row"><span>Order Reference</span><span class="v">' . apg_safe_html($orderRef) . '</span></div>';
    if ($orderData) {
        echo '<div class="row"><span>Service</span><span class="v">' . apg_safe_html($orderData['service'] ?? '—') . '</span></div>';
        echo '<div class="row"><span>Package</span><span class="v">' . apg_safe_html($orderData['package'] ?? '—') . '</span></div>';
        echo '<div class="row"><span>Amount</span><span class="v">PKR ' . apg_safe_html(number_format($orderData['total'] ?? 0)) . '</span></div>';
    }
    echo '<div class="row"><span>Status</span><span class="v">' . apg_safe_html($status) . '</span></div>';
    echo '</div>';
    echo '<a class="btn" href="checkout.html">Back to PrimeVortex</a>';
    if ($extra) echo '<p style="color:#a6a6a6;font-size:11px;margin-top:16px;">' . apg_safe_html($extra) . '</p>';
    echo '</div></body></html>';
}

// ==================== VISIT 1: handshake callback ====================
if (isset($_GET['success']) || isset($_GET['AuthToken'])) {
    error_log("HANDSHAKE DEBUG: " . print_r($_GET, true));
    $handshakeSuccess = ($_GET['success'] ?? '') === 'true';
    $authToken = $_GET['AuthToken'] ?? '';
    $orderRef = $_SESSION['pv_pending_order'] ?? null;

    if (!$orderRef) {
        render_result('Unknown', '—', null, 'Your session expired before payment could continue. Please start again.');
        exit;
    }

    $orderData = apg_get_order($orderRef);

    if (!$handshakeSuccess || !$authToken) {
        $err = $_GET['ErrorMessage'] ?? 'Bank Alfalah rejected the handshake request.';
        apg_update_order($orderRef, ['status' => 'Failed', 'error' => $err]);
        render_result('Unknown', $orderRef, $orderData, $err);
        exit;
    }

    if (!$orderData) {
        render_result('Unknown', $orderRef, null, 'Order not found on our server.');
        exit;
    }

    // Handshake succeeded — send the browser onward to the actual secure payment page.
    $client = new ApgClient($rawConfig);
    echo $client->buildSsoRedirectForm(
        $authToken,
        $orderRef,
        number_format($orderData['total'], 2, '.', '')
    );
    exit;
}

// ==================== VISIT 2: payment result callback ====================

/* Bank Alfalah may send the order id as a normal query param (?O=A10)
   or, per one example in the guide, appended in a TS=.../RC=.../O=...
   style. Handle both. */
$orderRef = $_GET['O'] ?? null;

if (!$orderRef) {
    // Fallback: scan the raw query string for an O= token
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    if (preg_match('/(?:^|[&\/])O=([^&\/]+)/', $qs, $m)) {
        $orderRef = $m[1];
    }
}

if (!$orderRef) {
    render_result('Unknown', '—', null, 'No order reference was found in the return URL.');
    exit;
}

$orderData = apg_get_order($orderRef);

try {
    $ipnUrl = rtrim($cfg['ipn_url'], '/') . '/' . rawurlencode($cfg['merchant_id']) . '/' . rawurlencode($cfg['store_id']) . '/' . rawurlencode($orderRef);
    $raw = apg_curl_get($ipnUrl);
    $data = json_decode($raw, true);

    if (is_array($data) && isset($data['TransactionStatus'])) {
        $status = $data['TransactionStatus'];
        apg_update_order($orderRef, [
            'status'              => $status,
            'transaction_id'      => $data['TransactionId'] ?? null,
            'transaction_datetime'=> $data['TransactionDateTime'] ?? null,
            'response_code'       => $data['ResponseCode'] ?? null,
        ]);
        render_result($status, $orderRef, apg_get_order($orderRef), 'DEBUG raw IPN response: ' . $raw);
    } else {
        render_result('Unknown', $orderRef, $orderData, 'Could not parse the status response from Bank Alfalah (raw: ' . $raw . ')');
    }
} catch (Throwable $e) {
    render_result('Unknown', $orderRef, $orderData, 'Could not reach Bank Alfalah to confirm status: ' . $e->getMessage());
}