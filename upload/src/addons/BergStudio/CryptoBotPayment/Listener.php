<?php

namespace BergStudio\CryptoBotPayment;

use XF\Mvc\Entity\Entity;

class Listener
{   
  public static function postSavePurchaseRequest(\XF\Mvc\Entity\Entity $entity)
  { 	  
      if($entity->provider_id !== 'tg_crypto_bot')
      {
        return;
      }
      
      $options = $entity->PaymentProfile->options;    
      $fee = intval($options['cb_fee']);  
      
      if($fee > 0)
      {
       $cost_fee_amount = $entity->cost_amount + ($entity->cost_amount * $fee / 100);
       
       $entity->set('cost_amount', round($cost_fee_amount, 2), ['forceSet' => true]); 
      } 
  }
}
 