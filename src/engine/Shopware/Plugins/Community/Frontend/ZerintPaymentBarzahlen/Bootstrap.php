<?php
require_once dirname(__FILE__) . '/Components/Barzahlen/Api/loader.php';

/**
 * Barzahlen Payment Module (Shopware 5)
 *
 * @copyright   Copyright (c) 2015 Cash Payment Solutions GmbH (https://www.barzahlen.de)
 * @author      encurio GmbH auf Basis der Version von Alexander Diebler
 * @license     http://opensource.org/licenses/AGPL-3.0  GNU Affero General Public License, version 3 (GPL-3.0)
 */

class Shopware_Plugins_Frontend_ZerintPaymentBarzahlen_Bootstrap extends Shopware_Components_Plugin_Bootstrap
{
    const LOGFILE = 'files/log/barzahlen.log';

    /**
     * DEBUG METHO
     * @param $name
     * @param null $args
     */
    /*
    public function __construct($name, $args=null) {
        file_put_contents('files/log/encurio.log', date("Y-m-d H:i:s") . " " . __METHOD__  . " - " . " - " . print_r($_GET['REQUEST'],true) . "\r\n", FILE_APPEND);
        parent::__construct($name, $args);
    }
    */

    /**
     * Install methods. Calls sub methods for a successful installation.
     *
     * @return boolean
     */
    public function install()
    {
        $this->createEvents();
        $this->createPayment();
        $this->createRules();
        $this->createForm();
        $this->createTranslations();

        return true;
    }

    /**
     * Subscribes to events in order to run plugin code.
     */
    protected function createEvents()
    {
        $event = $this->subscribeEvent('Enlight_Controller_Dispatcher_ControllerPath_Frontend_PaymentBarzahlen', 'onGetControllerPathFrontend');

        $event = $this->subscribeEvent('Enlight_Controller_Action_Frontend_PaymentBarzahlen_Notify', 'onNotification');

        $event = $this->subscribeEvent('Enlight_Controller_Action_Frontend_Checkout_Finish', 'onCheckoutSuccess');

        $event = $this->subscribeEvent('Enlight_Controller_Action_Frontend_Checkout_Confirm', 'onCheckoutConfirm');

        $event = $this->subscribeEvent('Enlight_Controller_Action_PostDispatch_Backend_Order', 'onGetOrderControllerPostDispatch');

        $event = $this->subscribeEvent('Enlight_Controller_Action_PreDispatch_Backend_Order', 'onGetOrderControllerPreDispatch');

        $event = $this->subscribeEvent('Enlight_Controller_Action_PostDispatch_Backend_Index', 'onBackendIndexPostDispatch');
    }

    /**
     * Creates a new or updates the old payment entry for the database.
     */
    public function createPayment()
    {
        $getOldPayments = $this->Payment();

        if (empty($getOldPayments['id'])) {
            $settings = array('name' => 'barzahlen',
                'description' => 'Barzahlen',
                'action' => 'payment_barzahlen',
                'active' => 1,
                'position' => 1,
                'pluginID' => $this->getId());

            Shopware()->Payments()->createRow($settings)->save();
        }
    }

    /**
     * Sets rules for Barzahlen payment.
     * Country = DE
     * max. Order Amount < 1000 Euros
     */
    public function createRules()
    {
        $payment = $this->Payment();

        $rules = "INSERT INTO s_core_rulesets
              (paymentID, rule1, value1)
              VALUES
              ('" . (int) $payment['id'] . "', 'ORDERVALUEMORE', '1000'),
              ('" . (int) $payment['id'] . "', 'LANDISNOT', 'DE'),
              ('" . (int) $payment['id'] . "', 'CURRENCIESISOISNOT', 'EUR')";

        Shopware()->Db()->query($rules);
    }

    /**
     * Creates the settings form for the backend.
     */
    protected function createForm()
    {
        $form = $this->Form();

        $form->setElement('boolean', 'barzahlenSandbox', array(
            'label' => 'Testmodus',
            'value' => true,
            'required' => true,
            'scope' => \Shopware\Models\Config\Element::SCOPE_SHOP
        ));

        $form->setElement('number', 'barzahlenShopId', array(
            'label' => 'Shop ID',
            'value' => '',
            'required' => true,
            'scope' => \Shopware\Models\Config\Element::SCOPE_SHOP
        ));

        $form->setElement('text', 'barzahlenPaymentKey', array(
            'label' => 'Zahlungsschlüssel',
            'value' => '',
            'required' => true,
            'scope' => \Shopware\Models\Config\Element::SCOPE_SHOP
        ));

        $form->setElement('text', 'barzahlenNotificationKey', array(
            'label' => 'Benachrichtigungsschlüssel',
            'value' => '',
            'required' => true,
            'scope' => \Shopware\Models\Config\Element::SCOPE_SHOP
        ));

        $form->setElement('boolean', 'barzahlenDebug', array(
            'label' => 'Erweitertes Logging',
            'value' => false,
            'required' => true,
            'scope' => \Shopware\Models\Config\Element::SCOPE_SHOP
        ));
    }

    /**
     * Sets translations for plugin text phrases.
     */
    protected function createTranslations()
    {
        $form = $this->Form();
        $translations = array(
            'de_DE' => array(
                'barzahlenSandbox' => 'Testmodus',
                'barzahlenShopId' => 'Shop ID',
                'barzahlenPaymentKey' => 'Zahlungsschlüssel',
                'barzahlenNotificationKey' => 'Benachrichtigungsschlüssel',
                'barzahlenDebug' => 'Erweitertes Logging'
            ),
            'en_GB' => array(
                'barzahlenSandbox' => 'Sandbox',
                'barzahlenShopId' => 'Shop ID',
                'barzahlenPaymentKey' => 'Payment Key',
                'barzahlenNotificationKey' => 'Notification Key',
                'barzahlenDebug' => 'Extended Logging'
            )
        );

        $shopRepository = Shopware()->Models()->getRepository('\Shopware\Models\Shop\Locale');
        foreach ($translations as $locale => $snippets) {
            $localeModel = $shopRepository->findOneBy(array(
                'locale' => $locale
                    ));
            foreach ($snippets as $element => $snippet) {
                if ($localeModel === null) {
                    continue;
                }
                $elementModel = $form->getElement($element);
                if ($elementModel === null) {
                    continue;
                }
                $translationModel = new \Shopware\Models\Config\ElementTranslation();
                $translationModel->setLabel($snippet);
                $translationModel->setLocale($localeModel);
                $elementModel->addTranslation($translationModel);
            }
        }
    }

    /**
     * Performs the uninstallation of the payment plugin.
     *
     * @return boolean
     */
    public function uninstall()
    {
        $payment = $this->Payment();
        Shopware()->Db()->query("DELETE FROM s_core_rulesets WHERE paymentID = '" . (int) $payment['id'] . "'");
        $this->disable();

        return true;
    }

    /**
     * Enables the payment method.
     *
     * @return parent return
     */
    public function enable()
    {
        $payment = $this->Payment();
        $payment->active = 1;
        $payment->save();

        return parent::enable();
    }

    /**
     * Disables the payment method.
     *
     * @return parent return
     */
    public function disable()
    {
        $payment = $this->Payment();
        $payment->active = 0;
        $payment->save();

        return parent::disable();
    }

    /**
     * Gathers all information for the backend overview of the plugin.
     *
     * @return array with all information
     */
    public function getInfo()
    {
        $img = 'https://cdn.barzahlen.de/images/barzahlen_logo.png';
        return array(
            'version' => $this->getVersion(),
            'autor' => 'Cash Payment Solutions GmbH',
            'label' => "Barzahlen Payment Module",
            'source' => "Local",
            'description' => '<p><img src="' . $img . '" alt="Barzahlen" /></p> <p>Barzahlen bietet Ihren Kunden die Möglichkeit, online bar zu bezahlen. Sie werden in Echtzeit über die Zahlung benachrichtigt und profitieren von voller Zahlungsgarantie und neuen Kundengruppen. Sehen Sie wie Barzahlen funktioniert: <a href="http://www.barzahlen.de/partner/funktionsweise" target="_blank">http://www.barzahlen.de/partner/funktionsweise</a></p><p>Sie haben noch keinen Barzahlen-Account? Melden Sie sich hier an: <a href="https://partner.barzahlen.de/user/register" target="_blank">https://partner.barzahlen.de/user/register</a></p>',
            'license' => 'GNU GPL v3.0',
            'copyright' => 'Copyright (c) 2015, Cash Payment Solutions GmbH',
            'support' => 'support@barzahlen.de',
            'link' => 'http://www.barzahlen.de'
        );
    }

    /**
     * Returns the currennt plugin version.
     *
     * @return string with current version
     */
    public function getVersion()
    {
        return "1.0.8";
    }

    /**
     * Selects all payment method information from the database.
     *
     * @return payment method information
     */
    public function Payment()
    {
        return Shopware()->Payments()->fetchRow(array('name=?' => 'barzahlen'));
    }

    /**
     * Register Barzahlen view directory.
     */
    protected function registerTemplateDir()
    {
        $this->Application()->Template()->addTemplateDir($this->Path() . 'Views/', 'barzahlen');
    }

    /**
     * Calls the payment constructor when frontend event fires.
     *
     * @param Enlight_Event_EventArgs $args
     * @return string with path to payment controller
     */
    public function onGetControllerPathFrontend(Enlight_Event_EventArgs $args)
    {
        return dirname(__FILE__) . '/Controllers/Frontend/PaymentBarzahlen.php';
    }

    /**
     * Sets empty template file to avoid errors.
     *
     * @param Enlight_Event_EventArgs $args
     */
    public function onNotification(Enlight_Event_EventArgs $args)
    {
        $view = $args->getSubject()->View();
        $this->registerTemplateDir();
        $view->extendsTemplate('frontend/payment_barzahlen/notify.tpl');
    }

    /**
     * Prepares checkout success page with received payment slip information.
     *
     * @param Enlight_Event_EventArgs $args
     */
    public function onCheckoutSuccess(Enlight_Event_EventArgs $args)
    {
        if (isset(Shopware()->Session()->BarzahlenResponse)) {
            $view = $args->getSubject()->View();
            $this->registerTemplateDir();
            $view->barzahlen_infotext_1 = Shopware()->Session()->BarzahlenResponse['infotext-1'];
            $view->extendsBlock(
                'frontend_checkout_finish_teaser',
                '{include file="frontend/payment_barzahlen/infotext.tpl"}' . "\n",
                'prepend'
            );

            if (isset(Shopware()->Session()->BarzahlenProcess)) {
                unset(Shopware()->Session()->BarzahlenProcess);
            }
        }
    }

    /**
     * Setting payment method selection payment description depending on sandbox
     * settings in payment config. Extending checkout/confirm template to show
     * Barzahlen Payment Error, if necessary.
     *
     * @param Enlight_Event_EventArgs $args
     */
    public function onCheckoutConfirm(Enlight_Event_EventArgs $args)
    {
        $payment = $this->Payment();
        $config = Shopware()->Plugins()->Frontend()->ZerintPaymentBarzahlen()->Config();

        $description = '<img src="https://cdn.barzahlen.de/images/barzahlen_logo.png" style="height: 45px;"/><br/>';
        $description .= '<p id="payment_desc"><img src="https://cdn.barzahlen.de/images/barzahlen_special.png" style="float: right; margin-left: 10px; max-width: 180px; max-height: 180px;">Mit Abschluss der Bestellung bekommen Sie einen Zahlschein angezeigt, den Sie sich ausdrucken oder auf Ihr Handy schicken lassen können. Bezahlen Sie den Online-Einkauf mit Hilfe des Zahlscheins an der Kasse einer Barzahlen-Partnerfiliale.';

        if ($config->barzahlenSandbox) {
            $description .= '<br/><br/>Der <strong>Sandbox Modus</strong> ist aktiv. Allen getätigten Zahlungen wird ein Test-Zahlschein zugewiesen. Dieser kann nicht von unseren Einzelhandelspartnern verarbeitet werden.';
        }

        $description .= '</p>';
        $description .= '<b>Bezahlen Sie bei:</b>&nbsp;';

        for ($i = 1; $i <= 10; $i++) {
            $count = str_pad($i, 2, "0", STR_PAD_LEFT);
            $description .= '<img src="https://cdn.barzahlen.de/images/barzahlen_partner_' . $count . '.png" alt="" style="display: inline; height: 1em; vertical-align: -0.1em;" />';
        }

        $newData = array('additionaldescription' => $description);
        $where = array('id = ' . (int) $payment['id']);

        Shopware()->Payments()->update($newData, $where);

        if (isset(Shopware()->Session()->BarzahlenPaymentError)) {
            $view = $args->getSubject()->View();
            $this->registerTemplateDir();
            $view->barzahlen_payment_error = Shopware()->Session()->BarzahlenPaymentError;
            $view->extendsTemplate('frontend/payment_barzahlen/error.tpl');
            unset(Shopware()->Session()->BarzahlenPaymentError);
        }

        if (isset(Shopware()->Session()->BarzahlenProcess)) {
            unset(Shopware()->Session()->BarzahlenProcess);
        }
    }

    /**
     * Cancels payment slip after order status is set to canceled.
     *
     * @param Enlight_Event_EventArgs $args
     */
    public function onGetOrderControllerPostDispatch(Enlight_Event_EventArgs $args)
    {
        if ($args->getRequest()->getActionName() === 'save') {
            $id = $args->getSubject()->Request()->getParam('id', null);
            $order = Shopware()->Models()->getRepository('Shopware\Models\Order\Order')->find($id);

            if ($order->getPayment()->getName() === 'barzahlen' && $order->getOrderStatus()->getId() == 4) {
                $transactionId = $order->getTransactionId();
                $cancel = new Barzahlen_Request_Cancel($transactionId);

                $config = $this->Config();
                $shopId = $config->barzahlenShopId;
                $paymentKey = $config->barzahlenPaymentKey;
                $sandbox = $config->barzahlenSandbox;
                $api = new Barzahlen_Api($shopId, $paymentKey, $sandbox);
                $api->setDebug($config->barzahlenDebug, self::LOGFILE);

                try {
                    $api->handleRequest($cancel);
                } catch (Exception $e) {
                    $this->_logError($e);
                }
            }
        }
    }

    /**
     * Cancels payment slip before order is deleted.
     *
     * @param Enlight_Event_EventArgs $args
     */
    public function onGetOrderControllerPreDispatch(Enlight_Event_EventArgs $args)
    {
        if ($args->getRequest()->getActionName() === 'delete') {
            $id = $args->getSubject()->Request()->getParam('id', null);
            $order = Shopware()->Models()->getRepository('Shopware\Models\Order\Order')->find($id);

            if ($order->getPayment()->getName() === 'barzahlen') {
                $transactionId = $order->getTransactionId();
                $cancel = new Barzahlen_Request_Cancel($transactionId);

                $config = $this->Config();
                $shopId = $config->barzahlenShopId;
                $paymentKey = $config->barzahlenPaymentKey;
                $sandbox = $config->barzahlenSandbox;
                $api = new Barzahlen_Api($shopId, $paymentKey, $sandbox);
                $api->setDebug($config->barzahlenDebug, self::LOGFILE);

                try {
                    $api->handleRequest($cancel);
                } catch (Exception $e) {
                    $this->_logError($e);
                }
            }
        }
    }

    /**
     * Checks for plugin updates. (Once a week.)
     *
     * @param Enlight_Event_EventArgs $args
     */
    public function onBackendIndexPostDispatch(Enlight_Event_EventArgs $args)
    {
        if (file_exists('files/log/barzahlen.check')) {
            $file = fopen('files/log/barzahlen.check', 'r');
            $lastCheck = fread($file, 1024);
            fclose($file);
        } else {
            $lastCheck = 0;
        }

        if (Shopware()->Auth()->hasIdentity() && ($lastCheck == 0 || $lastCheck < strtotime("-1 week"))) {

            if(!file_exists('files/log/')) {
                if(!mkdir('files/log/')) {
                    return;
                }
            }

            $file = fopen('files/log/barzahlen.check', 'w');
            fwrite($file, time());
            fclose($file);

            try {
                $config = $this->Config();
                $shopId = $config->barzahlenShopId;
                $paymentKey = $config->barzahlenPaymentKey;

                $checker = new Barzahlen_Version_Check($shopId, $paymentKey);
                $response = $checker->checkVersion('Shopware 5', Shopware::VERSION, $this->getVersion());

                if ($response != false) {
                    echo '<script type="text/javascript">
                          if(confirm(decodeURI("F%FCr das Barzahlen-Plugin ist eine neue Version (' . (string) $response . ') verf%FCgbar. Jetzt ansehen?"))) {
                            window.location.href = "https://integration.barzahlen.de/de/shopsysteme/shopware";
                          }
                          else {
                            window.location.reload();
                          }</script>';
                }
            } catch (Exception $e) {
                $this->_logError($e);
            }
        }
    }

    /**
     * Saves errors to given log file.
     *
     * @param string $error error message
     */
    protected function _logError($error)
    {
        $time = date("[Y-m-d H:i:s] ");
        error_log($time . $error . "\r\r", 3, self::LOGFILE);
    }
}
