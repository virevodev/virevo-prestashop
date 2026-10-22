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
            $this->abandonOrder($orderId, $cart);
        }

        // Mémorise l'identifiant Virevo (nécessaire au remboursement via avoir).
        if (!empty($resp['id'])) {
            $this->module->storeVirevoPaymentId($orderId, $resp['id']);
        }

        // 3) Rediriger vers la page de paiement Virevo.
        Tools::redirect($resp['payment_url']);
    }

    /**
     * Le paiement Virevo n'a pas pu être créé (API injoignable ou en erreur).
     *
     * Jusqu'à la 0.6.1, le client était renvoyé vers ce même contrôleur, qui
     * trouvait un panier déjà converti en commande : il atterrissait sur un
     * panier vide, sans message, et la commande restait « en attente de
     * virement » pour toujours, stock réservé, puisqu'aucun paiement n'existait
     * pour la clore. On annule donc la commande (ce qui restitue le stock), on
     * rend au client un panier identique et on lui dit quoi faire.
     */
    private function abandonOrder($orderId, Cart $cart)
    {
        $order = new Order((int) $orderId);
        if (Validate::isLoadedObject($order)) {
            $order->setCurrentState((int) Configuration::get('PS_OS_CANCELED'));
        }

        $copy = $cart->duplicate();
        if ($copy && !empty($copy['success'])) {
            $this->context->cookie->id_cart = (int) $copy['cart']->id;
            $this->context->cart = $copy['cart'];
            $this->context->cookie->write();
        }

        $this->errors[] = $this->module->l('Le paiement par virement instantané est momentanément indisponible. Votre panier a été conservé : vous pouvez réessayer ou choisir un autre moyen de paiement.', 'payment');
        $this->redirectWithNotifications($this->context->link->getPageLink('order', true));
    }
}
