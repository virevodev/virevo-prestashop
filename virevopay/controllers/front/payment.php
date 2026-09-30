<?php
/**
 * Crée la commande (en attente de virement), initie le paiement Virevo et
 * redirige le client vers la page de paiement.
 *
 * @license GPLv2 or later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class VirevoPayPaymentModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $cart = $this->context->cart;

        if (!$this->module->active
            || (int) $cart->id_customer === 0
            || (int) $cart->id_address_delivery === 0
            || (int) $cart->id_address_invoice === 0) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // Le module est-il bien autorisé pour ce client / panier ?
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] === $this->module->name) {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // Garde aussi à l'arrivée ici, et pas seulement dans hookPaymentOptions :
        // le panier a pu changer depuis. Il faut refuser AVANT validateOrder,
        // sinon une commande resterait créée pour un paiement impossible.
        if (!$this->module->reachesMinimum($cart)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $currency = $this->context->currency;
        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);

        // 1) Créer la commande en « attente de virement » (état natif PrestaShop).
        $this->module->validateOrder(
            (int) $cart->id,
            (int) Configuration::get('PS_OS_BANKWIRE'),
            $total,
            $this->module->displayName,
            null,
            [],
            (int) $currency->id,
            false,
            $customer->secure_key
        );

        $orderId = (int) $this->module->currentOrder;

        // Redirections du client après paiement / annulation.
        $returnUrl = $this->context->link->getPageLink(
            'order-confirmation',
            true,
            null,
            'id_cart=' . (int) $cart->id . '&id_module=' . (int) $this->module->id
                . '&id_order=' . $orderId . '&key=' . $customer->secure_key
        );
        $cancelUrl = $this->context->link->getPageLink('order', true);

        // 2) Créer le paiement Virevo (référence = id de commande).
        require_once _PS_MODULE_DIR_ . 'virevopay/classes/VirevoApiClient.php';
        $api = new VirevoApiClient($this->module->getApiKey());
        $resp = $api->createPayment((int) round($total * 100), 'EUR', (string) $orderId, 'ps-' . $orderId, $returnUrl, $cancelUrl);

        if (!$resp || empty($resp['payment_url'])) {
            // Échec de création : la commande reste en attente, on prévient le client.
            Tools::redirect($this->context->link->getModuleLink($this->module->name, 'payment', ['virevo_error' => 1], true));
        }

        // Mémorise l'identifiant Virevo (nécessaire au remboursement via avoir).
        if (!empty($resp['id'])) {
            $this->module->storeVirevoPaymentId($orderId, $resp['id']);
        }

        // 3) Rediriger vers la page de paiement Virevo.
        Tools::redirect($resp['payment_url']);
    }
}
