<?php

namespace Plugin\Smartpay;

use Exception;

class Client
{
    private $secretKey;
    private $publicKey;
    private $apiPrefix;

    function __construct($secretKey = null, $publicKey = null, $apiPrefix = null) {
        $this->secretKey = $secretKey;
        $this->publicKey = $publicKey;
        $this->apiPrefix = $apiPrefix;

        if (!$secretKey) {
            $this->secretKey = getenv('SMARTPAY_SECRET_KEY');
        }
        if (!$publicKey) {
            $this->publicKey = getenv('SMARTPAY_PUBLIC_KEY');
        }
        if (!$apiPrefix) {
            $this->apiPrefix = "https://api.smartpay.co/v1";
        }
    }

    /**
     * @throws Exception
     */
    public function httpGet($url)
    {;
        $curl = $this->curlInit($url);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($httpCode != 200) {
            log_error("[Smartpay] GET ${url} ${httpCode}", array(
                'response' => $response
            ));
            throw new Exception("システム管理者に連絡してください");
        }
        return json_decode($response, true);
    }

    /**
     * @throws Exception
     */
    public function httpPost($url, $data)
    {
        $curl = $this->curlInit($url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($httpCode != 200) {
            log_error("[Smartpay] POST ${url} ${httpCode}", array(
                'payload' => $data,
                'response' => $response
            ));
            throw new Exception("システム管理者に連絡してください");
        }
        return json_decode($response, true);
    }

    private function curlInit($url)
    {
        $url = $this->apiPrefix . $url;
        $curl = curl_init($url);
        $authorization = "Authorization: Basic {$this->secretKey}";
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Accept: application/json',
            'Content-Type: application/json',
            $authorization
        ));
        curl_setopt($curl, CURLOPT_ENCODING, 'gzip,deflate,sdch');
        return $curl;
    }
}