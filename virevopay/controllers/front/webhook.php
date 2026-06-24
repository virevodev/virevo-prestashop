<?php
/**
 * Réception des webhooks Virevo (payment.succeeded) — signature vérifiée.
 * Passe la commande à « Paiement accepté ». Endpoint public (sécurité = HMAC).
 *
 * @license GPLv2 or later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class VirevoPayWebhookModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ssl = true;

    const TOLERANCE_SECONDS = 300;

    public function postProcess()
    {
        $payload = file_get_contents('php://input');
        $signature = isset($_SERVER['HTTP_VIREVO_SIGNATURE']) ? $_SERVER['HTTP_VIREVO_SIGNATURE'] : '';
        $secret = Configuration::get('VIREVOPAY_WEBHOOK_SECRET');

        if (!$this->verifySignature($secret, $signature, $payload)) {
            $this->respond(400, ['error' => 'invalid signature']);
        }

        $event = json_decode($payload, true);
        if (is_array($event) && isset($event['type']) && $event['type'] === 'payment.succeeded') {
            $this->markOrderPaid($event);
        }

        $this->respond(200, ['received' => true]);
    }

    private function markOrderPaid(array $event)
    {
        $payment = isset($event['data']['payment']) ? $event['data']['payment'] : [];
        $orderId = isset($payment['reference']) ? (int) $payment['reference'] : 0;
        if ($orderId <= 0) {
            return;
        }

        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order) || $order->module !== 'virevopay') {
            return;
        }

        $paidState = (int) Configuration::get('PS_OS_PAYMENT');
        if ((int) $order->getCurrentState() === $paidState) {
            return; // idempotent : déjà réglée.
        }

        $order->setCurrentState($paidState);
    }

    /** Signature « t=<unix>,v1=<hmac> » : HMAC-SHA256 de "<t>.<corps>". */
    private function verifySignature($secret, $header, $body)
    {
        if (empty($secret) || empty($header)) {
            return false;
        }
        $parts = [];
        parse_str(str_replace(',', '&', $header), $parts);
        if (empty($parts['t']) || empty($parts['v1'])) {
            return false;
        }
        if (abs(time() - (int) $parts['t']) > self::TOLERANCE_SECONDS) {
            return false; // anti-rejeu.
        }
        $expected = hash_hmac('sha256', $parts['t'] . '.' . $body, $secret);

        return hash_equals($expected, (string) $parts['v1']);
    }

    private function respond($code, array $data)
    {
        header('Content-Type: application/json', true, $code);
        echo json_encode($data);
        exit;
    }
}
