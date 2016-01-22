<?php
require_once dirname(__FILE__) . '/../../Components/Barzahlen/Api/loader.php';

/**
 * Barzahlen Payment Module (Shopware 4)
 *
 * @copyright   Copyright (c) 2015 Cash Payment Solutions GmbH (https://www.barzahlen.de)
 * @author      Alexander Diebler
 * @license     http://opensource.org/licenses/AGPL-3.0  GNU Affero General Public License, version 3 (GPL-3.0)
 */

class Shopware_Controllers_Frontend_PaymentBarzahlen extends Shopware_Controllers_Frontend_Payment
{
    const LOGFILE = 'files/log/barzahlen.log';
    // (payment) state, given by s_core_states
    const PAYMENT_OPEN = 17;
    const PAYMENT_PAID = 12;
    const PAYMENT_EXPIRED = 35;
    const PAYMENT_VERIFY = 21;

    /*
     * DEBUG METHOD
    public function logParams($sMethod = '') {
        error_log(date("Y-m-d H:i:s") . " " . $sMethod  . " - " . " - " . print_r($this->Request()->getParams(),true) . "\r\n", 3, 'files/log/debug.log');
    }
    */

    /**
     * Checks that Barzahlen was choosen. If controller was called by accident,
     * the request is send back to the checkout.
     */
    public function indexAction()
    {
        if ($this->getPaymentShortName() == 'barzahlen') {
            $this->redirect(array('action' => 'gateway', 'forceSecure' => true));
        } else {
            $this->redirect(array('controller' => 'checkout'));
        }
    }

    /**
     * Order information are gather and send to Barzahlen to request a payment
     * slip. If the request was successful, the order is saved and the order id
     * will be updated.
     */
    public function gatewayAction()
    {
        // check if payment request is already processing
        if (!isset(Shopware()->Session()->BarzahlenProcess)) {

            Shopware()->Session()->BarzahlenProcess = true;

            $config = Shopware()->Plugins()->Frontend()->ZerintPaymentBarzahlen()->Config();
            $paymentUniqueId = $this->createPaymentUniqueId();

            $shopId = $config->barzahlenShopId;
            $paymentKey = $config->barzahlenPaymentKey;
            $sandbox = $config->barzahlenSandbox;
            $api = new Barzahlen_Api($shopId, $paymentKey, $sandbox);
            $api->setDebug($config->barzahlenDebug, self::LOGFILE);
            $api->setUserAgent('Shopware v' . Shopware::VERSION . ' / Plugin v1.0.7');

            $userinfo = $this->getUser();
            if (!$userinfo) {
                Shopware()->Session()->BarzahlenPaymentError = "Es gab einen Fehler bei der Datenübertragung. Bitte versuchen Sie es erneut oder wählen Sie eine andere Zahlungsmethode.";
                $this->redirect(array('controller' => 'checkout', 'action' => 'confirm'));
            }

            $customerEmail = $userinfo["additional"]["user"]["email"];
            $customerStreetNr = $userinfo["billingaddress"]["street"] . ' ' . $userinfo["billingaddress"]["streetnumber"];
            $customerZipcode = $userinfo["billingaddress"]["zipcode"];
            $customerCity = $userinfo["billingaddress"]["city"];
            $customerCountry = $userinfo["additional"]["country"]["countryiso"];
            $amount = $this->getAmount();
            $currency = $this->getCurrencyShortName();
            $payment = new Barzahlen_Request_Payment($customerEmail, $customerStreetNr, $customerZipcode, $customerCity, $customerCountry, $amount, $currency);

            try {
                $api->handleRequest($payment);
            } catch (Exception $e) {
                $this->_logError($e);
            }

            if ($payment->isValid()) {
                $orderId = $this->saveOrder($payment->getTransactionId(), $paymentUniqueId, NULL, true);
                $update = new Barzahlen_Request_Update($payment->getTransactionId(), $orderId);

                try {
                    $api->handleRequest($update);
                } catch (Exception $e) {
                    $this->_logError($e);
                }

                Shopware()->Session()->BarzahlenResponse = $payment->getXmlArray();
                $this->redirect(array('controller' => 'checkout', 'action' => 'finish'));
            } else {
                Shopware()->Session()->BarzahlenPaymentError = "Es gab einen Fehler bei der Datenübertragung. Bitte versuchen Sie es erneut oder wählen Sie eine andere Zahlungsmethode.";
                $this->redirect(array('controller' => 'checkout', 'action' => 'confirm'));
            }
        } else {
            // if there is already a payment process wait for the results
            while (!isset(Shopware()->Session()->BarzahlenResponse) && !isset(Shopware()->Session()->BarzahlenPaymentError)) {
                sleep(1);
            }
            if (isset(Shopware()->Session()->BarzahlenResponse)) {
                $this->redirect(array('controller' => 'checkout', 'action' => 'finish'));
            }
            if (isset(Shopware()->Session()->BarzahlenPaymentError)) {
                $this->redirect(array('controller' => 'checkout', 'action' => 'confirm'));
            }
        }
    }

    /**
     * Notifications are valided and the updates will be performed.
     * States set in DB in table s_core_states
     */
    public function notifyAction()
    {
        $config = Shopware()->Plugins()->Frontend()->ZerintPaymentBarzahlen()->Config();
        $shopId = $config->barzahlenShopId;
        $notificationKey = $config->barzahlenNotificationKey;
        $notify = new Barzahlen_Notification($shopId, $notificationKey, $this->Request()->getParams());

        try {
            $notify->validate();
        } catch (Exception $e) {
            $this->_logError($e);
        }

        if ($notify->isValid()) {
            $this->_sendHeader(200);

            $order = Shopware()->Modules()->Order();
            $orderId = $this->_getOrderId($this->Request()->transaction_id);
            $orderNumber = isset($this->Request()->order_id) ? $this->Request()->order_id : $this->_getOrderNumber($orderId);

            if (!$this->_checkPaymentStatus($orderId)) {
                $order->setPaymentStatus($orderId, self::PAYMENT_VERIFY);
                $this->_logError("Payment for order " . $orderNumber . " couldn't be updated by notification. Already processed.");
                return;
            }

            switch ($this->Request()->state) {
                case 'paid':
                    $order->setPaymentStatus($orderId, self::PAYMENT_PAID);
                    $this->_setClearedDate($this->Request()->transaction_id);
                    break;
                case 'expired':
                    $order->setPaymentStatus($orderId, self::PAYMENT_EXPIRED);
                    break;
                default:
                    $order->setPaymentStatus($orderId, self::PAYMENT_VERIFY);
                    break;
            }
        } else {
            $this->_sendHeader(400);
        }
    }

    /**
     * Selects order id by transaction id.
     *
     * @param integer $transactionId corresponding transaction id
     * @return order id (integer)
     */
    protected function _getOrderId($transactionId)
    {
        $sql = 'SELECT id FROM s_order WHERE transactionID=?';
        $orderId = Shopware()->Db()->fetchOne($sql, array($transactionId));
        return $orderId;
    }

    /**
     * Selects order number by order id.
     *
     * @param integer $orderId corresponding order id
     * @return order number (integer)
     */
    protected function _getOrderNumber($orderId)
    {
        $sql = 'SELECT ordernumber FROM s_order WHERE id=?';
        $orderNumber = Shopware()->Db()->fetchOne($sql, array($orderId));
        return $orderNumber;
    }

    /**
     * Checks that the payment wasn't already processed and can be updated.
     *
     * @param integer $orderId internal order id
     * @return boolean
     */
    protected function _checkPaymentStatus($orderId)
    {
        $sql = 'SELECT cleared FROM s_order WHERE id=?';
        $status = Shopware()->Db()->fetchOne($sql, array($orderId));

        if ($status == self::PAYMENT_OPEN) {
            return true;
        }

        return false;
    }

    /**
     * After a successful payment the order gets a clear date.
     *
     * @param integer $transactionId corresponding transaction id
     */
    protected function _setClearedDate($transactionId)
    {
        try {
            $sql = 'UPDATE s_order SET cleareddate=NOW()
                 WHERE transactionID=? AND cleareddate IS NULL LIMIT 1';
            $status = Shopware()->Db()->query($sql, array($transactionId));
        } catch (Exception $e) {
            $this->_logError($e->getMessage());
        }
    }

    /**
     * Sending the header corresponding to the header code.
     *
     * @param type $code
     */
    protected function _sendHeader($code)
    {
        switch ($code) {
            case 200:
                header("HTTP/1.1 200 OK");
                header("Status: 200 OK");
                break;
            case 400:
            default:
                header("HTTP/1.1 400 Bad Request");
                header("Status: 400 Bad Request");
                die();
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
