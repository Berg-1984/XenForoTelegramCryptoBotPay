<?php

namespace BergStudio\CryptoBotPayment\Service;

use XF\Mvc\Controller;

class Api extends \XF\Service\AbstractService
{	
	protected $botToken;
	
	protected $apiEndpoint;
	
	public function __construct(\XF\App $app, string $apiEndpoint, string $botToken)
	{
     parent::__construct($app);
     
     $this->botToken = $botToken;
    
     $this->apiEndpoint = $apiEndpoint;
	}	

	public function getMe(&$error)
   {
     return $this->request('getMe', [], $error);
   }
   
   public function getCurrencies(&$error)
   {
     return $this->request('getCurrencies', [], $error);
   }
   
   public function createInvoice(array $data, &$error)
   {
     return $this->request('createInvoice', $data, $error);
   }
   
   public function getInvoice($invoiceId, &$error)
   {
   	$invoices = $this->getInvoices([$invoiceId], $error);
   	
   	if(!isset($invoices[0]))
   	{
   	  return null;
   	}
   	
        return $invoices[0];
   }
   
   public function getInvoices(array $invoiceIds, &$error)
   {
     $invoices = $this->request('getInvoices', ['invoice_ids' => implode(',', $invoiceIds)], $error);
     
     if(!isset($invoices['items']))
     {
     	return null;
     }
     
     return $invoices['items'];
   }
   
   public function deleteInvoice($invoiceId, &$error)
   {
     return $this->request('deleteInvoice', ['invoice_id' => $invoiceId], $error);
   }
   
    private function request(string $method, array $data = [], &$error = null)
    {
    	$client = \XF::app()->http()->get('clientUntrusted');
    	  
    	$uri = $this->apiEndpoint . $method;
    	    	
    	$params = [
    	           'headers' => $this->getHeaders(),
                 'form_params' => $data
                ];
    	  
      try{
          $response = $client->post($uri, $params);

          $body = $response->getBody()->getContents();
          
          $out = \GuzzleHttp\json_decode($body, true);
           
          if (isset($out['ok']) && $out['ok'] === true && isset($out['result'])) 
          {
            return $out['result'];
          }
          
          $error = $body;
          
          \XF::logError("Telegram Crypto Bot - $error");
         }
         catch(\Exception $error) 
         {
           \XF::logException($error, false, "Telegram Crypto Bot - ");
         }
    }
    
    protected function getHeaders()
    {
     return [
             'Content-Type' => 'application/x-www-form-urlencoded',
             'Crypto-Pay-API-Token' => $this->botToken
            ];
    }
    
 }       