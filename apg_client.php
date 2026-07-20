<?php
/**
 * ApgClient — rebuilt to match Bank Alfalah's own confirmed-working
 * reference PHP exactly (handshake via server-side cURL, then an
 * auto-submitting SSO form). Field order and the empty "RequestHash="
 * placeholder inside the SSO hash string are copied verbatim from
 * their reference code — do not "clean up" or reorder these, the bank's
 * server checks the hash against this exact string.
 */

class ApgClient
{
    private array $cfg;

    public function __construct(array $fullConfig)
    {
        $env = $fullConfig['environment'] ?? 'sandbox';
        $this->cfg = $fullConfig[$env];

        $this->cfg['channel_id'] = $fullConfig['channel_id'] ?? '1001';
        $this->cfg['return_url'] = $fullConfig['return_url'] ?? '';
    }

    private function encrypt(string $plain): string
    {
        $cipher = openssl_encrypt($plain, 'aes-128-cbc', $this->cfg['key1'], OPENSSL_RAW_DATA, $this->cfg['key2']);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed: ' . openssl_error_string());
        }
        return base64_encode($cipher);
    }

    /**
     * Step 1: Handshake — server-side cURL call, matching Bank Alfalah's
     * reference code exactly. Returns AuthToken directly as JSON.
     */
    public function initiateHandshake(string $orderRef): array
    {
        $mapString =
              'HS_ChannelId=' . $this->cfg['channel_id']
            . '&HS_IsRedirectionRequest=0'
            . '&HS_MerchantId=' . $this->cfg['merchant_id']
            . '&HS_StoreId=' . $this->cfg['store_id']
            . '&HS_ReturnURL=' . $this->cfg['return_url']
            . '&HS_MerchantHash=' . $this->cfg['merchant_hash']
            . '&HS_MerchantUsername=' . $this->cfg['merchant_username']
            . '&HS_MerchantPassword=' . $this->cfg['merchant_password']
            . '&HS_TransactionReferenceNumber=' . $orderRef;

        $requestHash = $this->encrypt($mapString);

        $fields = [
            'HS_ChannelId'                  => $this->cfg['channel_id'],
            'HS_IsRedirectionRequest'       => '0',
            'HS_MerchantId'                 => $this->cfg['merchant_id'],
            'HS_StoreId'                    => $this->cfg['store_id'],
            'HS_ReturnURL'                  => $this->cfg['return_url'],
            'HS_MerchantHash'               => $this->cfg['merchant_hash'],
            'HS_MerchantUsername'           => $this->cfg['merchant_username'],
            'HS_MerchantPassword'           => $this->cfg['merchant_password'],
            'HS_TransactionReferenceNumber' => $orderRef,
            'HS_RequestHash'                => $requestHash,
        ];

        $response = $this->curlPost($this->cfg['hs_url'], $fields);
        $data = json_decode($response, true) ?? [];

        $success = isset($data['success']) && ($data['success'] === true || $data['success'] === 'true');

        return [
            'success'      => $success,
            'auth_token'   => $success ? ($data['AuthToken'] ?? null) : null,
            'error'        => $success ? null : ($data['ErrorMessage'] ?? 'Handshake failed'),
            'raw_response' => $response,
        ];
    }

    /**
     * Step 2: SSO redirect form. Note the empty "RequestHash" field is
     * INSIDE the hashed string itself (right after AuthToken) — this
     * matches Bank Alfalah's reference code exactly and is required.
     */
    public function buildSsoRedirectForm(string $authToken, string $orderRef, string $amount): string
    {
        $mapStringSSo =
              'AuthToken=' . $authToken
            . '&RequestHash='
            . '&ChannelId=' . $this->cfg['channel_id']
            . '&Currency=PKR'
            . '&IsBIN=0'
            . '&ReturnURL=' . $this->cfg['return_url']
            . '&MerchantId=' . $this->cfg['merchant_id']
            . '&StoreId=' . $this->cfg['store_id']
            . '&MerchantHash=' . $this->cfg['merchant_hash']
            . '&MerchantUsername=' . $this->cfg['merchant_username']
            . '&MerchantPassword=' . $this->cfg['merchant_password']
            . '&TransactionTypeId=3'
            . '&TransactionReferenceNumber=' . $orderRef
            . '&TransactionAmount=' . $amount;

        $requestHash = $this->encrypt($mapStringSSo);

        $fields = [
            'AuthToken'                  => $authToken,
            'RequestHash'                => $requestHash,
            'ChannelId'                  => $this->cfg['channel_id'],
            'Currency'                   => 'PKR',
            'IsBIN'                      => '0',
            'ReturnURL'                  => $this->cfg['return_url'],
            'MerchantId'                 => $this->cfg['merchant_id'],
            'StoreId'                    => $this->cfg['store_id'],
            'MerchantHash'               => $this->cfg['merchant_hash'],
            'MerchantUsername'           => $this->cfg['merchant_username'],
            'MerchantPassword'           => $this->cfg['merchant_password'],
            'TransactionTypeId'          => '3',
            'TransactionReferenceNumber' => $orderRef,
            'TransactionAmount'          => $amount,
        ];

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Redirecting...</title></head><body>';
        $html .= '<p style="text-align:center; padding:50px; font-family:sans-serif;">Redirecting to Bank Alfalah Secure Payment...</p>';
        $html .= '<form id="sso" action="' . htmlspecialchars($this->cfg['sso_url']) . '" method="POST">';

        foreach ($fields as $k => $v) {
            $html .= sprintf('<input type="hidden" name="%s" value="%s">', htmlspecialchars($k), htmlspecialchars($v));
        }

        $html .= '</form><script>document.getElementById("sso").submit();</script></body></html>';

        return $html;
    }

    private function curlPost(string $url, array $data): string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res ?: '';
    }
}