<?php

namespace BergStudio\CryptoBotPayment\Data;

class Currency
{  
   public function fiat()
   {
    return [
            'USD', 'EUR', 'RUB', 'BYN', 'UAH', 'GBP', 'CNY',
            'KGS', 'TJS', 'KZT', 'UZS', 'GEL', 'TRY', 'AMD',
            'THB','INR', 'BRL', 'IDR', 'AZN', 'AED', 'PLN', 'ILS'
           ];
   }

	public function crypto()
	{
	 return [
            'USDT', 'TON', 'BTC', 'ETH', 'LTC', 'BNB', 'DOGE', 
            'TRX', 'USDC', 'SOL', 'GRAM', 'NOT', 'HMSTR', 'DOGS',
            'CATI', 'PEPE', 'WIF', 'BONK','MAJOR', 'MY'
           ];
	}
	
	public function isSupportedCurrency($currencyCode)
	{
    return in_array(strtoupper($currencyCode), $this->fiat());
	}

}