<?php
/**
 * FIXED & CLEAN ApgClient for your Sandbox
 */

class ApgClient
{
    private array $cfg;

    public function __construct(array $fullConfig)
    {
        $env = $fullConfig['environment'] ?? 'sandbox';
        $this->cfg = $fullConfig[$env];

        // Add common keys
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

    private function buildHash(array $fields): string
    {
        $str = '';
        foreach ($fields as $k => $v) {
            $str .= $k . '=' . $v . '&';
        }
        return $this->encrypt(rtrim($str, '&'));
    }

    /**
     * Step 1 of the flow: build an auto-submitting HTML form that sends the
     * customer's own browser to Bank Alfalah's HS/HS/HS endpoint.
     *
     * IMPORTANT: this endpoint is NOT a JSON API — it only responds correctly
     * to a real browser POST. It replies with an HTTP redirect back to
     * HS_ReturnURL, appending success / AuthToken / ReturnURL as GET params.
     * That's why this can't be done with a server-side cURL call.
     */
    public function buildHandshakeRedirectForm(string $orderRef): string
    {
        $hashFields = [
            'HS_ChannelId'                  => $this->cfg['channel_id'],
            'HS_MerchantId'                 => $this->cfg['merchant_id'],
            'HS_StoreId'                    => $this->cfg['store_id'],
            'HS_ReturnURL'                  => $this->cfg['return_url'],
            'HS_MerchantHash'               => $this->cfg['merchant_hash'],
            'HS_MerchantUsername'           => $this->cfg['merchant_username'],
            'HS_MerchantPassword'           => $this->cfg['merchant_password'],
            'HS_TransactionReferenceNumber' => $orderRef,
        ];

        $requestHash = $this->buildHash($hashFields);

        $fields = array_merge($hashFields, [
            'HS_IsRedirectionRequest' => '0',
            'HS_RequestHash'          => $requestHash,
        ]);

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Redirecting...</title></head><body>';
        $html .= '<p style="text-align:center; padding:50px; font-family:sans-serif;">Redirecting to Bank Alfalah Secure Payment...</p>';
        $html .= '<form id="hs" action="' . htmlspecialchars($this->cfg['hs_url']) . '" method="POST">';

        foreach ($fields as $k => $v) {
            $html .= sprintf('<input type="hidden" name="%s" value="%s">', htmlspecialchars($k), htmlspecialchars($v));
        }

        $html .= '</form><script>document.getElementById("hs").submit();</script></body></html>';

        return $html;
    }

    public function buildSsoRedirectForm(string $authToken, string $orderRef, string $amount): string
    {
        $hashFields = [
            'AuthToken'                     => $authToken,
            'ChannelId'                     => $this->cfg['channel_id'],
            'Currency'                      => 'PKR',
            'IsBIN'                         => '0',
            'ReturnURL'                     => $this->cfg['return_url'],
            'MerchantId'                    => $this->cfg['merchant_id'],
            'StoreId'                       => $this->cfg['store_id'],
            'MerchantHash'                  => $this->cfg['merchant_hash'],
            'MerchantUsername'              => $this->cfg['merchant_username'],
            'MerchantPassword'              => $this->cfg['merchant_password'],
            'TransactionTypeId'             => '3',
            'TransactionReferenceNumber'    => $orderRef,
            'TransactionAmount'             => $amount,
        ];

        $requestHash = $this->buildHash($hashFields);

        $fields = array_merge($hashFields, ['RequestHash' => $requestHash]);

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