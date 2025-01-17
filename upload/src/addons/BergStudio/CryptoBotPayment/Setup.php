<?php

namespace BergStudio\CryptoBotPayment;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;

class Setup extends AbstractSetup
{
	use StepRunnerInstallTrait;
	use StepRunnerUpgradeTrait;
	use StepRunnerUninstallTrait;

   public function installStep1()
   {
    $provider = $this->app->em->create('XF:PaymentProvider');
    
    $provider->bulkSet([
                        'provider_id'    => $this->getProviderId(),
                        'provider_class' => 'BergStudio\CryptoBotPayment:CryptoBot',
                        'addon_id'       => $this->addOn->getAddOnId()
                       ]);
                       
    $provider->save();
	}

    public function uninstallStep1()
    {
      $db = $this->db();
      $providerId = $this->getProviderId();
      
      $db->delete('xf_payment_provider', 'provider_id = ?', $providerId);
		$db->delete('xf_payment_profile', 'provider_id = ?', $providerId);
		$db->delete('xf_purchase_request', 'provider_id = ?', $providerId);
		$db->delete('xf_payment_provider_log', 'provider_id = ?', $providerId);   
	 }
	 
	 protected function getProviderId() 
	 {
	 	return 'tg_crypto_bot';
	 }
   
}