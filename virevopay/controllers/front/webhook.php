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
        $type = is_array($event) && isset($event['type']) ? $event['type'] : '';

        switch ($type) {
            case 'payment.succeeded':
                $this->markOrderPaid($event);
                break;

            // Les TROIS façons dont un paiement se termine sans argent. Jusqu'au
            // 2026-09-24 elles étaient acquittées puis jetées, et la commande
            // restait « en attente de virement » indéfiniment.
            case 'payment.failed':
            case 'payment.canceled':
            case 'payment.expired':
                $this->closeUnpaidOrder($event, $type);
                break;

            case 'payment.refunded':
                $this->recordRefund($event);
                break;
        }

        // Un type inconnu est acquitté volontairement : un événement ajouté plus
        // tard ne doit pas faire échouer la livraison chez les marchands qui
        // n'ont pas mis à jour le module.
        $this->respond(200, ['received' => true]);
    }

    /** Retrouve la commande d'un événement, ou null. */
    private function resolveOrder(array $event)
    {
        $payment = isset($event['data']['payment']) ? $event['data']['payment'] : [];
        $orderId = isset($payment['reference']) ? (int) $payment['reference'] : 0;
        if ($orderId <= 0) {
            return null;
        }

        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order) || $order->module !== 'virevopay') {
            return null;
        }

        return $order;
    }

    private function markOrderPaid(array $event)
    {
        $order = $this->resolveOrder($event);
        if ($order === null) {
            return;
        }

        $paidState = (int) Configuration::get('PS_OS_PAYMENT');
        if ((int) $order->getCurrentState() === $paidState) {
            return; // idempotent : déjà réglée.
        }

        $order->setCurrentState($paidState);
    }

    /**
     * Clôt une commande dont le paiement ne viendra pas.
     *
     * Deux gardes valent plus que le reste de la méthode.
     *
     * 1. **Une commande réglée n'est jamais touchée.** Un `payment.failed` peut
     *    arriver après un `payment.succeeded` : notification tardive, ou
     *    tentative précédente annoncée en retard. Annuler alors une commande
     *    payée serait bien pire que de ne rien faire.
     * 2. **Un état terminal n'est pas réécrit.** Le marchand a pu annuler ou
     *    rembourser lui-même : sa décision prime sur un événement qui redit ce
     *    qu'on sait déjà.
     */
    private function closeUnpaidOrder(array $event, $type)
    {
        $order = $this->resolveOrder($event);
        if ($order === null) {
            return;
        }

        $current = (int) $order->getCurrentState();
        $terminal = [
            (int) Configuration::get('PS_OS_PAYMENT'),
            (int) Configuration::get('PS_OS_CANCELED'),
            (int) Configuration::get('PS_OS_REFUND'),
            (int) Configuration::get('PS_OS_ERROR'),
        ];
        if (in_array($current, $terminal, true)) {
            return;
        }

        if ($type === 'payment.failed') {
            // « Erreur de paiement » laisse la commande visible : le client peut
            // réessayer de régler.
            $order->setCurrentState((int) Configuration::get('PS_OS_ERROR'));

            return;
        }

        // Expiré ou annulé : la demande n'est plus payable.
        $order->setCurrentState((int) Configuration::get('PS_OS_CANCELED'));
    }

    /**
     * Répercute un remboursement décidé depuis le tableau de bord Virevo.
     *
     * ⚠️ **On ne crée surtout PAS d'avoir.** Le module écoute
     * `actionOrderSlipAdd` pour pousser les avoirs PrestaShop vers Virevo : créer
     * un avoir ici déclencherait ce hook, qui renverrait un second remboursement
     * à Virevo, qui nous renotifierait. La boucle est réelle, et elle coûterait
     * de l'argent réel.
     *
     * On se limite donc à ce qui est sûr : passer la commande en « Remboursé »
     * quand le remboursement couvre le total, et tracer un message dans tous les
     * cas. Le marchand garde la main sur l'avoir comptable.
     */
    private function recordRefund(array $event)
    {
        $order = $this->resolveOrder($event);
        if ($order === null) {
            return;
        }

        $refund = isset($event['data']['refund']) ? $event['data']['refund'] : [];
        $cents = isset($refund['amount_cents']) ? (int) $refund['amount_cents'] : 0;
        if ($cents <= 0) {
            return;
        }
        $amount = $cents / 100;

        $message = new Message();
        $message->id_order = (int) $order->id;
        $message->private = 1;
        $message->message = sprintf(
            'Remboursement de %s enregistré chez Virevo. Aucun avoir n\'a été créé'
            . ' automatiquement : à faire depuis cette commande si votre comptabilité l\'exige.',
            Tools::displayPrice($amount, (int) $order->id_currency)
        );
        $message->add();

        $refundState = (int) Configuration::get('PS_OS_REFUND');
        if ($amount + 0.01 >= (float) $order->total_paid
            && (int) $order->getCurrentState() !== $refundState
        ) {
            $order->setCurrentState($refundState);
        }
    }

    /**
     * Signature « t=<unix>,v1=<hmac>[,v1=<hmac>] » : HMAC-SHA256 de "<t>.<corps>".
     * Plusieurs v1 possibles pendant une rotation de secret : on accepte si l'un
     * d'eux correspond.
     */
    private function verifySignature($secret, $header, $body)
    {
        if (empty($secret) || empty($header)) {
            return false;
        }
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) !== 2) {
                continue;
            }
            $k = trim($kv[0]);
            $v = trim($kv[1]);
            if ($k === 't') {
                $t = (int) $v;
            } elseif ($k === 'v1' && $v !== '') {
                $sigs[] = $v;
            }
        }
        if ($t === null || empty($sigs)) {
            return false;
        }
        if (abs(time() - $t) > self::TOLERANCE_SECONDS) {
            return false; // anti-rejeu.
        }
        $expected = hash_hmac('sha256', $t . '.' . $body, $secret);
        foreach ($sigs as $v1) {
            if (hash_equals($expected, $v1)) {
                return true;
            }
        }

        return false;
    }

    private function respond($code, array $data)
    {
        header('Content-Type: application/json', true, $code);
        echo json_encode($data);
        exit;
    }
}
