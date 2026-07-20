<?php
/**
 * ipn_listener.php
 * -----------------
 * Register this exact URL as the "Listener URL" in the Merchant Portal
 * (Go Live > Access Sandbox > Credentials Generator > Listener URL) and
 * ask Bank Alfalah to whitelist it (required before IPN calls will
 * actually be delivered — see the integration guide's note on this).
 *
 * Bank Alfalah POSTs here with a "url" field/parameter pointing at the
 * IPN OrderStatus endpoint for the transaction that just completed. We
 * fetch that URL ourselves and update our order record from the result.
 */

require __DIR__ . '/apg_helpers.php';

header('Content-Type: application/json');

$inquiryUrl = $_POST['url'] ?? $_GET['url'] ?? null;

if (!$inquiryUrl) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing url parameter']);
    exit;
}

// Basic sanity check: only ever follow bankalfalah.com inquiry URLs
$host = parse_url($inquiryUrl, PHP_URL_HOST);
if (!$host || stripos($host, 'bankalfalah.com') === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Rejected non-Bank-Alfalah URL']);
    exit;
}

try {
    $raw = apg_curl_get($inquiryUrl);
    $data = json_decode($raw, true);

    if (!is_array($data) || !isset($data['TransactionReferenceNumber'])) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'Unexpected response from Bank Alfalah', 'raw' => $raw]);
        exit;
    }

    $orderRef = $data['TransactionReferenceNumber'];

    apg_update_order($orderRef, [
        'status'               => $data['TransactionStatus'] ?? 'Unknown',
        'transaction_id'       => $data['TransactionId'] ?? null,
        'transaction_datetime' => $data['TransactionDateTime'] ?? null,
        'response_code'        => $data['ResponseCode'] ?? null,
        'account_number'       => $data['AccountNumber'] ?? null,
        'ipn_received_at'      => date('c'),
    ]);

    // TODO: this is where you'd trigger fulfilment — e.g. send a
    // confirmation email, kick off the project, notify your team, etc.
    // if (($data['TransactionStatus'] ?? '') === 'Paid') { ... }

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}