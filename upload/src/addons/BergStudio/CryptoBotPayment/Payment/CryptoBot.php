<?php

namespace BergStudio\CryptoBotPayment\Payment;

use XF\Entity\PaymentProfile;
use XF\Entity\PurchaseRequest;
use XF\Mvc\Controller;
use XF\Payment\AbstractProvider;
use XF\Payment\CallbackState;
use XF\Purchasable\Purchase;
use XF\Str\Formatter;

class CryptoBot extends AbstractProvider
{
  public function getTitle()
  {
    return \XF::phrase('berg_crypto_bot_title');
  }

  public function getApiEndpoint()
  {
    if(\XF::config('enableLivePayments'))
    {
      return 'https://pay.crypt.bot/api/';
    }
    else
    {
      return 'https://testnet-pay.crypt.bot/api/';
    }
  }

  public function verifyCurrency(PaymentProfile $paymentProfile, $currencyCode)
  {
    return $this->getCurrency()->isSupportedCurrency($currencyCode);
  }

  public function verifyConfig(array &$options, &$errors = [])
  {
    $options['fee_amount'] = floatval($options['fee_amount']);

    $options['accepted_currencies'] = \XF::app()->request()
                                      ->filter('options.accepted_currencies', 'array');

    if (!$options['invoice_url_type'])
    {
      $errors[] = \XF::phrase('berg_crypto_bot_no_invoice_url_type');
      return false;
    }

    if (!$options['accepted_currencies'])
    {
      $errors[] = \XF::phrase('berg_crypto_bot_no_accepted_currencies');
      return false;
    }

    if (!$options['bot_token'])
    {
      $errors[] = \XF::phrase('berg_crypto_bot_no_token');
      return false;
    }

    $infoCryptoPay = $this->api($options['bot_token'])->getMe($error);

    if ($error)
    {
      $errors[] = $error->getMessage();
      return false;
    }

    return true;
  }

  protected function getPaymentParams(PurchaseRequest $purchaseRequest, Purchase $purchase)
  {
    $options = $purchase->paymentProfile->options;

    return [
            'currency_type' => 'fiat',
            'fiat' => $purchaseRequest->cost_currency,
            'amount' => $purchaseRequest->cost_amount,
            'accepted_assets' => implode(',', $options['accepted_currencies']),
            'description' => $this->getPaymentDescription($purchase, $options),
            'hidden_message' => '',
            'payload' => $purchaseRequest->request_key,
            'paid_btn_name' => 'callback',
            'paid_btn_url' => $purchase->returnUrl,
            'allow_comments' => 'true',
            'allow_anonymous' => 'false',
            'expires_in' => 86400
           ];
}

  public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase)
  {
    $options = $purchase->paymentProfile->options;

    $params = $this->getPaymentParams($purchaseRequest, $purchase);

    $invoice = $this->api($options['bot_token'])
                    ->createInvoice($params, $error);

    if($error)
    {
     throw $controller->exception($controller->error(\XF::phrase('something_went_wrong_please_try_again')));
    }

    $purchaseRequest->fastUpdate('provider_metadata', $invoice['invoice_id']);

    $this->paymentRepo()->logCallback(
                                      $purchaseRequest->request_key,
                                      $this->providerId,
                                      $invoice['invoice_id'],
                                      'info',
                                      "IV{$invoice['invoice_id']} created",
                                      $invoice,
                                      $purchaseRequest->user_id
                                     );

    return $controller->redirect($this->getInvoiceUrl($invoice, $options));
  }

  public function requestInvoice(Controller $controller, PurchaseRequest $purchaseRequest)
  {
    $botToken = $purchaseRequest->PaymentProfile->options['bot_token'];

    $invoice = $this->api($botToken)
                    ->getInvoice($purchaseRequest->provider_metadata, $error);

    if($error)
    {
     throw $controller->exception($controller->error($error));
    }

    return $invoice;
  }

      /**
   * @param \XF\Http\Request $request
   *
   * @return CallbackState
   */
  public function setupCallback(\XF\Http\Request $request)
  {
    $state = new CallbackState();

    $state->inputRaw = $request->getInputRaw();

    $state->body = json_decode($state->inputRaw, true);

    if(!is_array($state->body))
    {
      return $state;
    }

    $invoice = $state->body['payload'];
    $updateId = $state->body['update_id'];
    $updateType = $state->body['update_type'];

    $state->eventType = $updateType;
    $state->requestKey = $invoice['payload'];
    $state->transactionId = $invoice['invoice_id'];
    $state->costAmount = $invoice['amount'];
    $state->costCurrency = $invoice['fiat'];;
    $state->paymentStatus = $invoice['status'];
    $state->subscriberId = $state->getPurchaseRequest()->user_id;
    $state->signature = $request->getServer('HTTP_CRYPTO_PAY_API_SIGNATURE');

    $state->ip = $request->getIp();
    $state->httpCode = 200;

    return $state;
  }

  public function setupCheck($invoice)
  {
    $state = new CallbackState();

    if(!is_array($invoice))
    {
      return $state;
    }

    $state->requestKey = $invoice['payload'];
    $state->transactionId = $invoice['invoice_id'];
    $state->costAmount = $invoice['amount'];
    $state->costCurrency = $invoice['fiat'];
    $state->paymentStatus = $invoice['status'];
    $state->subscriberId = $state->getPurchaseRequest()->user_id;
    $state->invoice = $invoice;

    $state->ip = \XF::app()->request->getIp();
    $state->httpCode = 200;

    return $state;
  }

  public function validateCallback(CallbackState $state)
  {
    if (!$state->body)
    {
      //$state->logType = 'error';
      $state->logMessage = 'Webhook received from Crypto Bot Pay does not contain a request body.';
      $state->httpCode = 400;

      return false;
    }

    if (!$state->signature)
    {
      $state->logType = 'error';
      $state->logMessage = 'Webhook received from Crypto Bot Pay does not contain a signature.';
      $state->httpCode = 403;

      return false;
    }

    if (!$this->verifyCallbackSignature($state))
    {
      $state->logType = 'error';
      $state->logMessage = 'Webhook received from Crypto Bot Pay could not be verified as being valid. Secret mismatch.';
      $state->httpCode = 403;

      return false;
    }

    if ($state->eventType !== 'invoice_paid')
    {
      $state->logType = 'info';
      $state->logMessage = "Webhook received from Crypto Bot Pay not supported: $state->eventType.";
      $state->httpCode = 200;

      return false;
    }

    return true;
  }

  public function validateTransaction(CallbackState $state)
  {
    if (!$state->transactionId)
    {
      $state->logType = 'error';
      $state->logMessage = 'No invoice ID. No action to take.';

      return false;
    }

    if (!$state->paymentStatus)
    {
      $state->logType = 'error';
      $state->logMessage = 'No invoice payment status. No action to take.';

      return false;
    }

    return parent::validateTransaction($state);
  }

  public function validatePurchaseRequest(CallbackState $state)
  {
    if (!$state->requestKey || $state->requestKey != $state->getPurchaseRequest()->request_key)
    {
      $state->logType = 'error';
      $state->logMessage = 'Invalid request key. Unrelated payment, no action to take.';

      return false;
    }

    return parent::validatePurchaseRequest($state);
  }

  public function validateCost(CallbackState $state)
  {
    $purchaseRequest = $state->getPurchaseRequest();

    if ($state->costCurrency != $purchaseRequest->cost_currency)
    {
      $state->logType = 'error';
      $state->logMessage = 'Invalid cost currency';

      return false;
    }

    if ($state->costAmount != $purchaseRequest->cost_amount)
    {
      $state->logType = 'error';
      $state->logMessage = 'Invalid cost amount';

      return false;
    }

    return true;
  }

  public function getPaymentResult(CallbackState $state)
  {
    switch ($state->paymentStatus)
    {
      //active, paid or expired
      case 'paid':
                  $state->paymentResult = CallbackState::PAYMENT_RECEIVED;
                  break;
    }
  }

  public function prepareLogData(CallbackState $state)
  {
    if ($state->body)
    {
      $state->logDetails = [
                            'body' => $state->body,
                            'signature' => $state->signature
                           ];
    }
    elseif ($state->invoice)
    {
      $state->logDetails = $state->invoice;
    }
  }

  public function completeTransaction(CallbackState $state)
  {
    return parent::completeTransaction($state);
  }

  public function supportsRecurring(\XF\Entity\PaymentProfile $paymentProfile, $unit, $amount, &$result = self::ERR_NO_RECURRING)
  {
    return parent::supportsRecurring($paymentProfile, $unit, $amount, $result);
  }

  protected function verifyCallbackSignature(CallbackState $state)
  {
    $botToken = $state->getPaymentProfile()->options['bot_token'];

    $hashBotToken = hash('sha256', $botToken, true);
    $computedSignature = hash_hmac('sha256', $state->inputRaw, $hashBotToken);

    return \XF\Util\Php::hashEquals($state->signature, $computedSignature);
  }

  public function renderConfig(PaymentProfile $profile)
  {
    $data = [
             'profile' => $profile,
             'invoice_url_types' => ['bot', 'web_app', 'mini_app'],
             'crypto_currencies' => $this->getCurrency()->crypto(),
             'webhook_url' => $this->getCallbackUrl()
            ];

    return \XF::app()->templater()->renderTemplate('admin:payment_profile_' . $this->providerId, $data);
  }

  public function getCallbackUrl()
  {
    return \XF::app()->router('public')->buildLink(
                                                   'canonical:payment_callback.php', null,
                                                   ['_xfProvider' => $this->providerId]
                                                  );
  }

  protected function getPaymentDescription(Purchase $purchase, array $options)
  {
    $description = $purchase->title;

    $fee_amount = $options['fee_amount'];

    if($fee_amount > 0)
    {
     $description .= "\n" . \XF::phrase('berg_crypto_bot_fee_message_x', ['fee' => $fee_amount]);
    }

    return \XF::app()->stringFormatter()->wholeWordTrim($description, 1024);
  }

  protected function getInvoiceUrl(array $invoice, array $options)
  {
    $url_type = $options['invoice_url_type'];

    return $invoice[$url_type . '_invoice_url'] ?? $invoice['pay_url'];;
  }

  protected function paymentRepo()
  {
    return \XF::repository('XF:Payment');
  }

  public function api($botToken)
  {
    return \XF::app()->service('BergStudio\CryptoBotPayment:Api', $this->getApiEndpoint(), $botToken);
  }

  protected function getCurrency()
  {
    return \XF::app()->data('BergStudio\CryptoBotPayment:Currency');
  }

}
