<?php

/**
 * CoinPayments.net Payment Gateway for BoxBilling
 * Based on the original PayPal module
 *
 * BoxBilling
 *
 * LICENSE
 *
 * This source file is subject to the license that is bundled
 * with this package in the file LICENSE.txt
 * It is also available through the world-wide-web at this URL:
 * http://www.boxbilling.com/LICENSE.txt
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@boxbilling.com so we can send you a copy immediately.
 *
 * @copyright Copyright (c) 2010-2012 BoxBilling (http://www.boxbilling.com)
 * @license   http://www.boxbilling.com/LICENSE.txt
 * @version   $Id$
 */
class Payment_Adapter_CoinPayments implements \Box\InjectionAwareInterface
{

    const API_URL = 'https://api.coinpayments.net';
    const CHECKOUT_URL = 'https://checkout.coinpayments.net';
    const API_VERSION = '1';

    const API_SIMPLE_INVOICE_ACTION = 'invoices';
    const API_WEBHOOK_ACTION = 'merchant/clients/%s/webhooks';
    const API_MERCHANT_INVOICE_ACTION = 'merchant/invoices';
    const API_CURRENCIES_ACTION = 'currencies';
    const API_CHECKOUT_ACTION = 'checkout';
    const FIAT_TYPE = 'fiat';

    protected $config = array();

    protected $di;

    public function setDi($di)
    {
        $this->di = $di;
    }

    public function getDi()
    {
        return $this->di;
    }

    /**
     * Payment_Adapter_CoinPayments constructor.
     * @param $config
     * @throws Exception
     */
    public function __construct($config)
    {
        $this->config = $config;

        if (!$this->config['client_id']) {
            throw new Exception('Payment gateway "CoinPayments" is not configured properly. Please update configuration parameter "CoinPayments.net Merchant ID" at "Configuration -> Payments".');
        }

        if ($this->config['webhooks'] == 'Yes' && !$this->config['client_secret']) {
            throw new Exception('Payment gateway "CoinPayments" is not configured properly. Please update configuration parameter "CoinPayments.net Client Secret" at "Configuration -> Payments".');
        }

        if ($this->config['test_mode']) {
            throw new Exception('Payment gateway "CoinPayments" does not support Test Mode.');
        }
    }

    /**
     * @return array
     */
    public static function getConfig()
    {
        return array(
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Enter your CoinPayments.net Client ID to start accepting payments by CoinPayments. Enable Webhooks and enter Client Secret to recieve payment info',
            'form' => array(
                'client_id' => array('text', array(
                    'label' => 'Client ID',
                    'validators' => array('notempty'),
                ),
                ),
                'webhooks' => array('radio', array(
                    'label' => 'Enable CoinPayments.net Webhooks',
                    'validators' => array(),
                    'multiOptions' => array('1' => 'Yes', '0' => 'No'),
                ),
                ),
                'client_secret' => array('text', array(
                    'label' => 'Client Secret',
                    'validators' => array(),
                ),
                ),
            ),
        );
    }

    /**
     * @param $api_admin
     * @param $invoice_id
     * @param $subscription
     * @return string
     * @throws Exception
     */
    public function getHtml($api_admin, $invoice_id, $subscription)
    {

        $invoice = $api_admin->invoice_get(array('id' => $invoice_id));

        $coin_invoice_id = sprintf('%s|%s', md5($this->getNotificationUrl($invoice['gateway_id'])), $invoice['nr']);
        $currency_code = $invoice['currency'];
        $coin_currency = $this->getCoinCurrency($currency_code);
        $amount = number_format($invoice['total'], $coin_currency['decimalPlaces'], '', '');
        $display_value = $invoice['total'];

        if ($this->config['webhooks']) {
            $webhooks_list = $this->getWebhooksList($this->config['client_id'], $this->config['client_secret']);
            if (!empty($webhooks_list)) {
                $webhooks_urls_list = array();
                if (!empty($webhooks_list['items'])) {
                    $webhooks_urls_list = array_map(function ($webHook) {
                        return $webHook['notificationsUrl'];
                    }, $webhooks_list['items']);
                }

                if (!in_array($this->getNotificationUrl($invoice['gateway_id']), $webhooks_urls_list)) {
                    $this->createWebHook($this->config['client_id'], $this->config['client_secret'], $this->getNotificationUrl($invoice['gateway_id']));
                }
            }
            $resp = $this->createMerchantInvoice($this->config['client_id'], $this->config['client_secret'], $coin_currency['id'], $coin_invoice_id, $amount, $display_value);
            $coin_invoice = array_shift($resp['invoices']);
        } else {
            $coin_invoice = $this->createSimpleInvoice($this->config['client_id'], $coin_currency['id'], $coin_invoice_id, $amount, $display_value);
        }

        $data = array(
            'invoice-id' => $coin_invoice['id'],
            'success-url' => $this->config['return_url'],
            'cancel-url' => $this->config['cancel_url'],
        );

        return $this->generateForm(sprintf('%s/%s/', static::CHECKOUT_URL, static::API_CHECKOUT_ACTION), $data);
    }

    /**
     * @param $api_admin
     * @param $id
     * @param $data
     * @param $gateway_id
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {

        $tx = $api_admin->invoice_transaction_get(array('id' => $id));

        $signature = isset($tx['ipn']['server']['HTTP_X_COINPAYMENTS_SIGNATURE']) ? $tx['ipn']['server']['HTTP_X_COINPAYMENTS_SIGNATURE'] : false;
        $content = $tx['ipn']['http_raw_post_data'];


        if ($this->config['webhooks'] && !empty($signature)) {

            $request_data = json_decode($content, true);

            if ($this->checkDataSignature($signature, $content, $gateway_id) && isset($request_data['invoice']['invoiceId'])) {

                $invoice_str = $request_data['invoice']['invoiceId'];
                $invoice_str = explode('|', $invoice_str);
                $host_hash = array_shift($invoice_str);
                $invoice_id = array_shift($invoice_str);

                if ($host_hash == md5($this->getNotificationUrl($gateway_id)) && $invoice_id) {

                    $status = $request_data['invoice']['status'];

                    if (!$tx['invoice_id']) {
                        $api_admin->invoice_transaction_update(array('id' => $id, 'invoice_id' => $invoice_id));
                    }

                    if (!$tx['type']) {
                        $api_admin->invoice_transaction_update(array('id' => $id, 'type' => 'button'));
                    }

                    if (!$tx['txn_id']) {
                        $api_admin->invoice_transaction_update(array('id' => $id, 'txn_id' => $request_data['invoice']['id']));
                    }

                    if (!$tx['txn_status']) {
                        $api_admin->invoice_transaction_update(array('id' => $id, 'txn_status' => $status));
                    }

                    if (!$tx['amount']) {
                        $api_admin->invoice_transaction_update(array('id' => $id, 'amount' => $request_data['invoice']['amount']['displayValue']));
                    }

                    if (!$tx['currency']) {
                        $api_admin->invoice_transaction_update(array('id' => $id, 'currency' => $request_data['invoice']['currency']['symbol']));
                    }


                    $invoice = $api_admin->invoice_get(array('id' => $invoice_id));
                    $client_id = $invoice['client']['id'];

                    if ($status == 'Completed') {
                        $bd = array(
                            'id' => $client_id,
                            'amount' => $request_data['invoice']['amount']['displayValue'],
                            'description' => 'CoinPayments transaction ' . $request_data['invoice']['id'],
                            'type' => 'CoinPayments',
                            'rel_id' => $request_data['invoice']['id'],
                        );
                        $api_admin->client_balance_add_funds($bd);
                        $api_admin->invoice_batch_pay_with_credits(array('client_id' => $client_id));
                        $d = array(
                            'id' => $id,
                            'error' => '',
                            'error_code' => '',
                            'status' => 'processed',
                            'updated_at' => date('c'),
                        );
                        $api_admin->invoice_transaction_update($d);
                    } elseif ($status == 'Cancelled') {
                        $d = array(
                            'id' => $id,
                            'error' => '',
                            'error_code' => '',
                            'status' => 'canceled',
                            'updated_at' => date('c'),
                        );
                        $api_admin->invoice_transaction_update($d);
                    } else {
                        $d = array(
                            'id' => $id,
                            'error' => '',
                            'error_code' => '',
                            'status' => 'pending',
                            'updated_at' => date('c'),
                        );
                        $api_admin->invoice_transaction_update($d);
                    }
                }
            }
        }
    }

    /**
     * @param $gateway_id
     * @return string
     */
    protected function getNotificationUrl($gateway_id)
    {
        return sprintf('%sbb-ipn.php?bb_gateway_id=%s', BB_URL, $gateway_id);
    }

    /**
     * @param $signature
     * @param $content
     * @param $gateway_id
     * @return bool
     */
    protected function checkDataSignature($signature, $content, $gateway_id)
    {
        $request_url = $this->getNotificationUrl($gateway_id);
        $client_secret = $this->config['client_secret'];
        $signature_string = sprintf('%s%s', $request_url, $content);
        $encoded_pure = $this->encodeSignatureString($signature_string, $client_secret);
        return $signature == $encoded_pure;
    }

    /**
     * @param $data
     * @return string
     */
    protected function escapeForForm($data)
    {
        if (defined('ENT_HTML401')) {
            return htmlspecialchars($data, ENT_QUOTES | ENT_HTML401, 'UTF-8');
        } else {
            return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        }
    }

    /**
     * @param $url
     * @param $data
     * @param string $method
     * @return string
     */
    protected function generateForm($url, $data, $method = 'get')
    {
        $form = '';
        $form .= '<form name="coinpayments_payment_form" action="' . $url . '" method="' . $method . '">' . PHP_EOL;
        foreach ($data as $key => $value) {
            $form .= sprintf('<input type="hidden" name="%s" value="%s" />', $this->escapeForForm($key), $this->escapeForForm($value)) . PHP_EOL;
        }
        $form .= '<input class="bb-button bb-button-submit" type="submit" value="Pay with Bitcoin, Litecoin, or other altcoins via CoinPayments.net" id="coinpayments_payment_button"/>' . PHP_EOL;
        $form .= '</form>' . PHP_EOL . PHP_EOL;

        if (isset($this->config['auto_redirect']) && $this->config['auto_redirect']) {
            $form .= sprintf('<h2>%s</h2>', __('Redirecting to CoinPayments.net'));
            $form .= "<script type='text/javascript'>$(document).ready(function(){    document.getElementById('coinpayments_payment_button').style.display = 'none';    document.forms['coinpayments_payment_form'].submit();});</script>";
        }
        return $form;
    }

    /**
     * @param $client_id
     * @param $client_secret
     * @param $notification_url
     * @return bool|mixed
     * @throws Exception
     */
    protected function createWebHook($client_id, $client_secret, $notification_url)
    {

        $action = sprintf(self::API_WEBHOOK_ACTION, $client_id);

        $params = array(
            "notificationsUrl" => $notification_url,
            "notifications" => array(
                "invoiceCreated",
                "invoicePending",
                "invoicePaid",
                "invoiceCompleted",
                "invoiceCancelled",
            ),
        );

        return $this->sendRequest('POST', $action, $client_id, $params, $client_secret);
    }

    /**
     * @param $client_id
     * @param int $currency_id
     * @param string $invoice_id
     * @param int $amount
     * @param string $display_value
     * @return bool|mixed
     * @throws Exception
     */
    protected function createSimpleInvoice($client_id, $currency_id = 5057, $invoice_id = 'Validate invoice', $amount = 1, $display_value = '0.01')
    {

        $action = self::API_SIMPLE_INVOICE_ACTION;

        $params = array(
            'clientId' => $client_id,
            'invoiceId' => $invoice_id,
            'amount' => array(
                'currencyId' => $currency_id,
                "displayValue" => $display_value,
                'value' => $amount
            ),
        );

        $params = $this->appendInvoiceMetadata($params, 'NotesToRecipient');
        return $this->sendRequest('POST', $action, $client_id, $params);
    }

    /**
     * @param $client_id
     * @param $client_secret
     * @param $currency_id
     * @param $invoice_id
     * @param $amount
     * @param $display_value
     * @return bool|mixed
     * @throws Exception
     */
    protected function createMerchantInvoice($client_id, $client_secret, $currency_id, $invoice_id, $amount, $display_value)
    {

        $action = self::API_MERCHANT_INVOICE_ACTION;

        $params = array(
            "invoiceId" => $invoice_id,
            "amount" => array(
                "currencyId" => $currency_id,
                "displayValue" => $display_value,
                "value" => $amount,
            ),
        );

        $params = $this->appendInvoiceMetadata($params, 'Notes');
        return $this->sendRequest('POST', $action, $client_id, $params, $client_secret);
    }

    /**
     * @param string $name
     * @return mixed
     * @throws Exception
     */
    protected function getCoinCurrency($name)
    {

        $params = array(
            'types' => self::FIAT_TYPE,
            'q' => $name,
        );
        $items = array();

        $listData = $this->getCoinCurrencies($params);
        if (!empty($listData['items'])) {
            $items = $listData['items'];
        }

        return array_shift($items);
    }

    /**
     * @param array $params
     * @return bool|mixed
     * @throws Exception
     */
    protected function getCoinCurrencies($params = array())
    {
        return $this->sendRequest('GET', self::API_CURRENCIES_ACTION, false, $params);
    }

    /**
     * @param $client_id
     * @param $client_secret
     * @return bool|mixed
     * @throws Exception
     */
    protected function getWebhooksList($client_id, $client_secret)
    {

        $action = sprintf(self::API_WEBHOOK_ACTION, $client_id);

        return $this->sendRequest('GET', $action, $client_id, null, $client_secret);
    }

    /**
     * @param $signature_string
     * @param $client_secret
     * @return string
     */
    protected function encodeSignatureString($signature_string, $client_secret)
    {
        return base64_encode(hash_hmac('sha256', $signature_string, $client_secret, true));
    }

    /**
     * @param $request_data
     * @return mixed
     */
    protected function appendInvoiceMetadata($request_data, $notes_field)
    {
        $request_data['metadata'] = array(
            "integration" => "Boxbilling",
            "hostname" => BB_URL,
        );

        $settingService = $this->di['mod_service']('System');
        $company = $settingService->getCompany();
        //var_dump("salfhsduifhauoiphfoeijsdpofjfps:   " . $company['name']);

        $request_data[$notes_field] = sprintf("%s / Store name: %s / Order # %s",BB_URL,$company['name'],explode('|', $request_data['invoiceId'])[1]);
        return $request_data;
    }

    /**
     * @param $method
     * @param $api_url
     * @param $client_id
     * @param $date
     * @param $client_secret
     * @param $params
     * @return string
     */
    protected function createSignature($method, $api_url, $client_id, $date, $client_secret, $params)
    {

        if (!empty($params)) {
            $params = json_encode($params);
        }

        $signature_data = array(
            chr(239),
            chr(187),
            chr(191),
            $method,
            $api_url,
            $client_id,
            $date->format('c'),
            $params
        );

        $signature_string = implode('', $signature_data);

        return $this->encodeSignatureString($signature_string, $client_secret);
    }

    /**
     * @param $action
     * @return string
     */
    protected function getApiUrl($action)
    {
        return sprintf('%s/api/v%s/%s', self::API_URL, self::API_VERSION, $action);
    }

    /**
     * @param $method
     * @param $api_action
     * @param $client_id
     * @param null $params
     * @param null $client_secret
     * @return bool|mixed
     * @throws Exception
     */
    protected function sendRequest($method, $api_action, $client_id, $params = null, $client_secret = null)
    {
        $response = false;

        $api_url = $this->getApiUrl($api_action);

        $date = new \Datetime();
        try {

            $curl = curl_init();

            $options = array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYHOST => false,
            );

            $headers = array(
                'Content-Type: application/json',
            );

            if ($client_secret) {
                $signature = $this->createSignature($method, $api_url, $client_id, $date, $client_secret, $params);
                $headers[] = 'X-CoinPayments-Client: ' . $client_id;
                $headers[] = 'X-CoinPayments-Timestamp: ' . $date->format('c');
                $headers[] = 'X-CoinPayments-Signature: ' . $signature;

            }

            $options[CURLOPT_HTTPHEADER] = $headers;

            if ($method == 'POST') {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = json_encode($params);
            } elseif ($method == 'GET' && !empty($params)) {
                $api_url .= '?' . http_build_query($params);
            }

            $options[CURLOPT_URL] = $api_url;

            curl_setopt_array($curl, $options);

            $response = json_decode(curl_exec($curl), true);

            curl_close($curl);

        } catch (Exception $e) {

        }
        return $response;
    }

}

