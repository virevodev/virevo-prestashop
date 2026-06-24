<?php
/**
 * Client de l'API publique Virevo (/v1) pour PrestaShop.
 *
 * @license GPLv2 or later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class VirevoApiClient
{
    /** @var string */
    private $apiKey;
    /** @var string */
    private $baseUrl;

    public function __construct($apiKey, $baseUrl = 'https://app.virevo.fr')
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Crée un paiement. Renvoie le tableau décodé ou null en cas d'échec.
     *
     * @param int    $amountCents
     * @param string $currency
     * @param string $reference       Référence marchand (id de commande).
     * @param string $idempotencyKey
     * @return array|null
     */
    public function createPayment($amountCents, $currency, $reference, $idempotencyKey, $returnUrl = '', $cancelUrl = '')
    {
        $body = [
            'amount_cents' => (int) $amountCents,
            'currency' => $currency,
            'reference' => $reference,
        ];
        if ($returnUrl) {
            $body['return_url'] = $returnUrl;
        }
        if ($cancelUrl) {
            $body['cancel_url'] = $cancelUrl;
        }

        $ch = curl_init($this->baseUrl . '/v1/payments');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Idempotency-Key: ' . $idempotencyKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (false === $body || $code < 200 || $code >= 300) {
            return null;
        }

        $data = json_decode($body, true);

        return is_array($data) ? $data : null;
    }
}
