<?php
/**
 * Shared helpers used by create_order.php, return.php and ipn_listener.php
 */

function apg_config() {
    static $cfg = null;
    if ($cfg === null) {
        $all = require __DIR__ . '/config.php';
        $env = $all['environment'];
        $cfg = array_merge($all[$env], [
            'channel_id'    => $all['channel_id'],
            'return_url'    => $all['return_url'],
            'listener_url'  => $all['listener_url'],
            'orders_file'   => $all['orders_file'],
            'environment'   => $env,
        ]);
    }
    return $cfg;
}

/**
 * AES-128-CBC / PKCS7 encryption used for HS_RequestHash / RequestHash,
 * matching Bank Alfalah's CryptoJS.AES.encrypt(...) implementation.
 */
function apg_encrypt_hash($mapString, $key1, $key2) {
    $cipherText = openssl_encrypt(
        $mapString,
        'aes-128-cbc',
        $key1,
        OPENSSL_RAW_DATA,
        $key2
    );
    if ($cipherText === false) {
        throw new RuntimeException('Encryption failed — check that Key1/Key2 are exactly 16 characters.');
    }
    return base64_encode($cipherText);
}

/* --------------------------------------------------------------------
 * Very small JSON-file order store.
 * Replace these three functions with real DB calls in production.
 * ------------------------------------------------------------------ */

function apg_orders_load() {
    $cfg = apg_config();
    if (!file_exists($cfg['orders_file'])) {
        return [];
    }
    $raw = file_get_contents($cfg['orders_file']);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function apg_orders_save($orders) {
    $cfg = apg_config();
    file_put_contents($cfg['orders_file'], json_encode($orders, JSON_PRETTY_PRINT));
}

function apg_store_order($orderRef, array $fields) {
    $orders = apg_orders_load();
    $orders[$orderRef] = array_merge($fields, [
        'order_ref'  => $orderRef,
        'status'     => $fields['status'] ?? 'Pending',
        'created_at' => date('c'),
    ]);
    apg_orders_save($orders);
}

function apg_get_order($orderRef) {
    $orders = apg_orders_load();
    return $orders[$orderRef] ?? null;
}

function apg_update_order($orderRef, array $fields) {
    $orders = apg_orders_load();
    if (!isset($orders[$orderRef])) {
        $orders[$orderRef] = ['order_ref' => $orderRef];
    }
    $orders[$orderRef] = array_merge($orders[$orderRef], $fields, ['updated_at' => date('c')]);
    apg_orders_save($orders);
}

/**
 * Simple cURL POST helper returning the raw body.
 */
function apg_curl_post($url, array $fields) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $result = curl_exec($ch);
    if ($result === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL POST to ' . $url . ' failed: ' . $err);
    }
    curl_close($ch);
    return $result;
}

/**
 * Simple cURL GET helper returning the raw body.
 */
function apg_curl_get($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $result = curl_exec($ch);
    if ($result === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL GET to ' . $url . ' failed: ' . $err);
    }
    curl_close($ch);
    return $result;
}

function apg_safe_html($val) {
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}