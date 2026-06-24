<?php
/**
 * Virevo — virement instantané (module de paiement PrestaShop).
 *
 * @author    Virevo
 * @license   GPLv2 or later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class VirevoPay extends PaymentModule
{
    public function __construct()
    {
        $this->name = 'virevopay';
        $this->tab = 'payments_gateways';
        $this->version = '0.3.0';
        $this->author = 'Virevo';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => '8.99.99'];
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        parent::__construct();

        $this->displayName = $this->l('Virevo — virement instantané');
        $this->description = $this->l('Encaissez par virement instantané (Virevo), sans frais de carte. Confirmation automatique par webhook signé.');
        $this->confirmUninstall = $this->l('Supprimer la configuration Virevo ?');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
            && $this->registerHook('actionOrderSlipAdd')
            && $this->installTable()
            && Configuration::updateValue('VIREVOPAY_MODE', 'test')
            && Configuration::updateValue('VIREVOPAY_TEST_KEY', '')
            && Configuration::updateValue('VIREVOPAY_LIVE_KEY', '')
            && Configuration::updateValue('VIREVOPAY_WEBHOOK_SECRET', '');
    }

    public function uninstall()
    {
        return Configuration::deleteByName('VIREVOPAY_MODE')
            && Configuration::deleteByName('VIREVOPAY_TEST_KEY')
            && Configuration::deleteByName('VIREVOPAY_LIVE_KEY')
            && Configuration::deleteByName('VIREVOPAY_WEBHOOK_SECRET')
            && $this->uninstallTable()
            && parent::uninstall();
    }

    /** Table de correspondance commande PrestaShop ↔ paiement Virevo. */
    private function installTable()
    {
        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'virevopay_payment` (
                `id_order` INT UNSIGNED NOT NULL PRIMARY KEY,
                `virevo_payment_id` VARCHAR(64) NOT NULL
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    private function uninstallTable()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'virevopay_payment`');
    }

    /** Mémorise l'identifiant de paiement Virevo pour une commande. */
    public function storeVirevoPaymentId($idOrder, $paymentId)
    {
        return Db::getInstance()->execute(
            'REPLACE INTO `' . _DB_PREFIX_ . 'virevopay_payment` (id_order, virevo_payment_id) VALUES ('
            . (int) $idOrder . ", '" . pSQL($paymentId) . "')"
        );
    }

    public function getVirevoPaymentId($idOrder)
    {
        return Db::getInstance()->getValue(
            'SELECT virevo_payment_id FROM `' . _DB_PREFIX_ . 'virevopay_payment` WHERE id_order = ' . (int) $idOrder
        );
    }

    /**
     * Avoir créé dans l'admin PrestaShop → pousse le remboursement à Virevo.
     */
    public function hookActionOrderSlipAdd($params)
    {
        if (empty($params['order'])) {
            return;
        }
        $order = $params['order'];
        if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return;
        }
        $paymentId = $this->getVirevoPaymentId($order->id);
        if (!$paymentId) {
            return;
        }

        // Montant du dernier avoir (produits TTC + port TTC).
        $slips = OrderSlip::getOrdersSlip($order->id_customer, $order->id);
        $last = is_array($slips) ? end($slips) : false;
        if (!$last) {
            return;
        }
        $amount = (float) $last['total_products_tax_incl'] + (float) $last['total_shipping_tax_incl'];
        if ($amount <= 0) {
            return;
        }

        require_once _PS_MODULE_DIR_ . 'virevopay/classes/VirevoApiClient.php';
        $api = new VirevoApiClient($this->getApiKey());
        $api->refund($paymentId, (int) round($amount * 100), 'Avoir PrestaShop');
    }

    /** Clé d'API du mode actif. */
    public function getApiKey()
    {
        return Configuration::get('VIREVOPAY_MODE') === 'live'
            ? Configuration::get('VIREVOPAY_LIVE_KEY')
            : Configuration::get('VIREVOPAY_TEST_KEY');
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active || !$this->checkCurrency($params['cart'])) {
            return [];
        }

        $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->l('Payer par virement instantané'))
            ->setAction($this->context->link->getModuleLink($this->name, 'payment', [], true))
            ->setAdditionalInformation($this->l('Sans frais de carte. Vous serez redirigé vers une page de paiement sécurisée.'));

        return [$option];
    }

    public function hookPaymentReturn($params)
    {
        // Page de confirmation par défaut de PrestaShop (le statut suit le webhook).
        return '';
    }

    public function checkCurrency($cart)
    {
        $currency = new Currency((int) $cart->id_currency);

        return 'EUR' === $currency->iso_code;
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitVirevo')) {
            Configuration::updateValue('VIREVOPAY_MODE', Tools::getValue('VIREVOPAY_MODE'));
            Configuration::updateValue('VIREVOPAY_TEST_KEY', trim(Tools::getValue('VIREVOPAY_TEST_KEY')));
            Configuration::updateValue('VIREVOPAY_LIVE_KEY', trim(Tools::getValue('VIREVOPAY_LIVE_KEY')));
            Configuration::updateValue('VIREVOPAY_WEBHOOK_SECRET', trim(Tools::getValue('VIREVOPAY_WEBHOOK_SECRET')));
            $output .= $this->displayConfirmation($this->l('Réglages enregistrés.'));
        }

        return $output . $this->renderForm();
    }

    protected function renderForm()
    {
        $webhook_url = $this->context->link->getModuleLink($this->name, 'webhook', [], true);

        $form = [
            'form' => [
                'legend' => ['title' => $this->l('Réglages Virevo'), 'icon' => 'icon-cogs'],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->l('Mode'),
                        'name' => 'VIREVOPAY_MODE',
                        'options' => [
                            'query' => [
                                ['id' => 'test', 'name' => $this->l('Test (bac à sable)')],
                                ['id' => 'live', 'name' => $this->l('Live (réel)')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    ['type' => 'text', 'label' => $this->l('Clé API test'), 'name' => 'VIREVOPAY_TEST_KEY'],
                    ['type' => 'text', 'label' => $this->l('Clé API live'), 'name' => 'VIREVOPAY_LIVE_KEY'],
                    [
                        'type' => 'text',
                        'label' => $this->l('Secret de webhook'),
                        'name' => 'VIREVOPAY_WEBHOOK_SECRET',
                        'desc' => sprintf($this->l('Secret « whsec_… » de Virevo → Développeurs. URL de webhook à y enregistrer : %s'), $webhook_url),
                    ],
                ],
                'submit' => ['title' => $this->l('Enregistrer')],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitVirevo';
        $helper->currentIndex = AdminController::$currentIndex . '&' . http_build_query(['configure' => $this->name]);
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => [
                'VIREVOPAY_MODE' => Configuration::get('VIREVOPAY_MODE'),
                'VIREVOPAY_TEST_KEY' => Configuration::get('VIREVOPAY_TEST_KEY'),
                'VIREVOPAY_LIVE_KEY' => Configuration::get('VIREVOPAY_LIVE_KEY'),
                'VIREVOPAY_WEBHOOK_SECRET' => Configuration::get('VIREVOPAY_WEBHOOK_SECRET'),
            ],
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$form]);
    }
}
