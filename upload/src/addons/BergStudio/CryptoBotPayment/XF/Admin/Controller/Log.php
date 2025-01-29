<?php

namespace BergStudio\CryptoBotPayment\XF\Admin\Controller;

use XF\Mvc\ParameterBag;
use XF\Payment\CallbackState;

class Log extends XFCP_Log
{
  public function actionPaymentProviderCheck(ParameterBag $params)
  {
    $entry = $this->assertPaymentProviderLogExists($params->provider_log_id, [
                                                   'Provider',
                                                   'PurchaseRequest',
                                                   'PurchaseRequest.PaymentProfile',
                                                   'PurchaseRequest.Purchasable',
                                                   'PurchaseRequest.User'
                                                   ], 'requested_log_entry_not_found');

    $provider = $entry->Provider;
    $handler = $provider->handler;
    $purchaseRequest = $entry->PurchaseRequest;

    $lastLog = $this->findLastLogPaymentAction($entry->transaction_id, $entry->provider_id);

    if($lastLog)
    {
      return $this->redirect($this->buildLink('logs/payment-provider', $lastLog), $lastLog->log_message);
    }

    $invoice = $handler->requestInvoice($this, $purchaseRequest);
    $state = $handler->setupCheck($invoice);

    if(!$handler->validateTransaction($state) ||
       !$handler->validatePurchaseRequest($state) ||
       !$handler->validateCost($state))
    {
      throw $this->exception($this->error($state->logMessage));
    }

    $handler->getPaymentResult($state);

    if($state->paymentResult !== CallbackState::PAYMENT_RECEIVED)
    {
      return $this->message("IV{$state->transactionId} unpaid!");
    }

    $handler->completeTransaction($state);

    if ($state->logType)
    {
      try
      {
       $handler->log($state);
      }
      catch (\Exception $e)
      {
       \XF::logException($e, false, "Error logging payment to payment provider: ");
      }
    }

    $lastLog = $this->findLastLogPaymentAction($entry->transaction_id, $entry->provider_id);

    return $this->redirect($this->buildLink('logs/payment-provider', $lastLog), $lastLog->log_message);
  }

  protected function findLastLogPaymentAction($transaction_id, $provider_id)
  {
    $paymentRepo = $this->repository('XF:Payment');
    $matchingLogsFinder = $paymentRepo->findLogsByTransactionIdForProvider($transaction_id, $provider_id);

    if(!$matchingLogsFinder->total())
    {
      return null;
    }

    return $matchingLogsFinder->fetchOne();
  }

}