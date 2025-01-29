<?php

namespace BergStudio\CryptoBotPayment;

use XF\Mvc\Entity\Entity;

class Listener
{
  public static function preSavePurchaseRequest(\XF\Mvc\Entity\Entity $entity)
  {
    if($entity->provider_id !== 'tg_crypto_bot')
    {
      return;
    }

    $options = $entity->PaymentProfile->options;

    $fee_amount = $options['fee_amount'];

    if($fee_amount > 0)
    {
      $total_amount = $entity->cost_amount + ($entity->cost_amount * $fee_amount / 100);

      $entity->cost_amount = round($total_amount, 2);
    }
  }
}
 