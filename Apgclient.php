<?php
/**
 * ApgClient — thin wrapper around Bank Alfalah's Alfa Payment Gateway (APG)
 * "Page Redirection" flow, as described in the Merchant Integration Guide v1.1.
 *
 * Flow implemented (server does the handshake, avoiding an extra customer
 * round-trip — this mirrors HS_IsRedirectionRequest = 0):
 *
 *   1. initiateHandshake()  -> server-to-server POST to HS/HS/HS, returns AuthToken
 *   2. buildSsoRedirectForm() -> HTML auto-submit form sending the browser to
 *                                 SSO/SSO/SSO, which shows Alfalah's secure
 *                                 checkout (card / wallet / bank account)
 *   3. Customer pays, is redirected back to payment_return_url with the
 *      order id appended as ".../O=<TransactionReferenceNumber>"
 *   4. inquireOrderStatus() -> GET call to the IPN endpoint to confirm the
 *                                final TransactionStatus ("Paid" or not)
 *
 * NOTE ON HASH FIELD ORDER:
 * The guide's own sample code is inconsistent about exactly which fields —
 * and in what order — go into the string that gets AES-encrypted to produce
 * HS_RequestHash / RequestHash. The order below follows the guide's worked
 * "Sample Request of Initiate Handshake API" example as closely as possible.
 * If your sandbox returns {"success":"false","ErrorMessage":"Invalid Request"}
 * on Step 1, the most common cause is a mismatched field order/casing in the
 * hash string — see buildHandshakeHash() and buildSsoHash() below, both of
 * which take an explicit ordered array so you can quickly try alternate
 * orderings while debugging against the sandbox.
 */

class ApgClient
{
    private array $cfg;   // active environment config (sandbox|production)
    private string $channelId;
    private string $currency;
    private string $handshakeReturnUrl;
    private string $paymentReturnUrl;

    public function __construct(array $fullConfig)
    {
        $env = $fullConfig[APG_ENV] ?? null;
        if (!$env) {
            throw new RuntimeException('Invalid APG_ENV: ' . APG_ENV);
        }
        $this->cfg = $env;
        $this->channelId = $fullConfig['channel_id'];
        $this->currency = $fullConfig['currency'];
        $this->handshakeReturnUrl = $fullConfig['handshake_return_url'];
        $this->paymentReturnUrl = $fullConfig['payment_return_url'];
    }

    /* ---------------------------------------------------------------- *
     * Encryption
     * ---------------------------------------------------------------- */

    /**
     * AES-128-CBC / PKCS7, base64-encoded — matches the guide's CryptoJS
     * example (Key1 = key, Key2 = iv, both used as raw ASCII bytes).
     */
    private function encrypt(string $plainText): string
    {
        $key1 = $this->cfg['key1'];
        $key2 = $this->cfg['key2'];

        $cipherText = openssl_encrypt(
            $plainText,
            'aes-128-cbc',
            $key1,
            OPENSSL_RAW_DATA,
            $key2
        );

        if ($cipherText === false) {
            throw new RuntimeException('APG encryption failed: ' . openssl_error_string());
        }

        return base64_encode($cipherText);
    }

    /**
     * Build "key1=val1&key2=val2..." from an ordered assoc array and encrypt it.
     * Order matters — see class docblock.
     */
    private function hashFromOrderedFields(array $orderedFields): string
    {
        $parts = [];
        foreach ($orderedFields as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        $mapString = implode('&', $parts);

        return $this->encrypt($mapString);
    }

    /* ---------------------------------------------------------------- *
     * STEP 1: Initiate Handshake
     * ---------------------------------------------------------------- */

    public function buildHandshakeHash(string $transactionReferenceNumber): string
    {
        // Order taken from the guide's worked "Sample Request of Initiate
        // Handshake API" example.
        $ordered = [
            'HS_ChannelId'                  => $this->channelId,
            'HS_MerchantId'                 => $this->cfg['merchant_id'],
            'HS_StoreId'                    => $this->cfg['store_id'],
            'HS_ReturnURL'                  => $this->handshakeReturnUrl,
            'HS_MerchantHash'               => $this->cfg['merchant_hash'],
            'HS_MerchantUsername'           => $this->cfg['merchant_username'],
            'HS_MerchantPassword'           => $this->cfg['merchant_password'],
            'HS_TransactionReferenceNumber' => $transactionReferenceNumber,
        ];

        return $this->hashFromOrderedFields($ordered);
    }

    /**
     * Server-to-server POST to HS/HS/HS. Returns ['success'=>bool, 'auth_token'=>?string, 'error'=>?string]
     */
    public function initiateHandshake(string $transactionReferenceNumber): array
    {
        $requestHash = $this->buildHandshakeHash($transactionReferenceNumber);

        $fields = [
            'HS_ChannelId'                  => $this->channelId,
            'HS_IsRedirectionRequest'       => '0', // server handles the handshake directly
            'HS_MerchantId'                 => $this->cfg['merchant_id'],
            'HS_StoreId'                    => $this->cfg['store_id'],
            'HS_ReturnURL'                  => $this->handshakeReturnUrl,
            'HS_MerchantHash'               => $this->cfg['merchant_hash'],
            'HS_MerchantUsername'           => $this->cfg['merchant_username'],
            'HS_MerchantPassword'           => $this->cfg['merchant_password'],
            'HS_TransactionReferenceNumber' => $transactionReferenceNumber,
            'HS_RequestHash'                => $requestHash,
        ];

        $response = $this->post($this->cfg['handshake_url'], $fields);

        if ($response === null) {
            return ['success' => false, 'auth_token' => null, 'error' => 'No response from APG (network error).'];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return ['success' => false, 'auth_token' => null, 'error' => 'Unexpected APG response: ' . $response];
        }

        $ok = isset($data['success']) && ($data['success'] === true || $data['success'] === 'true');

        return [
            'success'    => $ok,
            'auth_token' => $ok ? ($data['AuthToken'] ?? null) : null,
            'error'      => $ok ? null : ($data['ErrorMessage'] ?? 'Unknown handshake error'),
        ];
    }

    /* ---------------------------------------------------------------- *
     * STEP 2: SSO redirect (Alfalah's secure checkout page)
     * ---------------------------------------------------------------- */

    public function buildSsoHash(
        string $authToken,
        string $transactionReferenceNumber,
        string $transactionAmount,
        string $transactionTypeId
    ): string {
        // Order follows the merchant's own previously working handshake code
        // (the only concrete precedent given for this stage). RequestHash
        // itself is excluded from the string being hashed.
        $ordered = [
            'AuthToken'                     => $authToken,
            'ChannelId'                     => $this->channelId,
            'Currency'                      => $this->currency,
            'IsBIN'                         => '0',
            'ReturnURL'                     => $this->paymentReturnUrl,
            'MerchantId'                    => $this->cfg['merchant_id'],
            'StoreId'                       => $this->cfg['store_id'],
            'MerchantHash'                  => $this->cfg['merchant_hash'],
            'MerchantUsername'              => $this->cfg['merchant_username'],
            'MerchantPassword'              => $this->cfg['merchant_password'],
            'TransactionTypeId'             => $transactionTypeId,
            'TransactionReferenceNumber'    => $transactionReferenceNumber,
            'TransactionAmount'             => $transactionAmount,
        ];

        return $this->hashFromOrderedFields($ordered);
    }

    /**
     * Returns raw HTML: a hidden auto-submitting form that sends the
     * customer's browser to Bank Alfalah's secure SSO checkout page.
     */
    public function buildSsoRedirectForm(
        string $authToken,
        string $transactionReferenceNumber,
        string $transactionAmount,
        string $transactionTypeId = ''
    ): string {
        $requestHash = $this->buildSsoHash($authToken, $transactionReferenceNumber, $transactionAmount, $transactionTypeId);

        $fields = [
            'AuthToken'                  => $authToken,
            'RequestHash'                => $requestHash,
            'ChannelId'                  => $this->channelId,
            'Currency'                   => $this->currency,
            'IsBIN'                      => '0',
            'ReturnURL'                  => $this->paymentReturnUrl,
            'MerchantId'                 => $this->cfg['merchant_id'],
            'StoreId'                    => $this->cfg['store_id'],
            'MerchantHash'               => $this->cfg['merchant_hash'],
            'MerchantUsername'           => $this->cfg['merchant_username'],
            'MerchantPassword'           => $this->cfg['merchant_password'],
            'TransactionTypeId'          => $transactionTypeId,
            'TransactionReferenceNumber' => $transactionReferenceNumber,
            'TransactionAmount'          => $transactionAmount,
        ];

        $inputs = '';
        foreach ($fields as $name => $value) {
            $inputs .= sprintf(
                '<input type="hidden" name="%s" value="%s">' . "\n",
                htmlspecialchars($name, ENT_QUOTES),
                htmlspecialchars((string) $value, ENT_QUOTES)
            );
        }

        $ssoUrl = htmlspecialchars($this->cfg['sso_url'], ENT_QUOTES);

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Redirecting to secure payment…</title></head>
<body>
  <p>Redirecting you to Bank Alfalah's secure payment page, please wait…</p>
  <form id="SsoRedirectForm" action="{$ssoUrl}" method="post">
    {$inputs}
  </form>
  <script>document.getElementById('SsoRedirectForm').submit();</script>
</body>
</html>
HTML;
    }

    /* ---------------------------------------------------------------- *
     * STEP 3: IPN — inquire final order status
     * ---------------------------------------------------------------- */

    public function inquireOrderStatus(string $transactionReferenceNumber): ?array
    {
        $url = rtrim($this->cfg['ipn_url'], '/') . '/' .
            rawurlencode($this->cfg['merchant_id']) . '/' .
            rawurlencode($this->cfg['store_id']) . '/' .
            rawurlencode($transactionReferenceNumber);

        $response = $this->get($url);
        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }

    /* ---------------------------------------------------------------- *
     * HTTP helpers
     * ---------------------------------------------------------------- */

    private function post(string $url, array $fields): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $result = curl_exec($ch);
        if ($result === false) {
            error_log('APG POST error: ' . curl_error($ch));
            curl_close($ch);
            return null;
        }
        curl_close($ch);
        return $result;
    }

    private function get(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $result = curl_exec($ch);
        if ($result === false) {
            error_log('APG GET error: ' . curl_error($ch));
            curl_close($ch);
            return null;
        }
        curl_close($ch);
        return $result;
    }
}