<?php
// ===================================================================================================
//                           _  __     _ _
//                          | |/ /__ _| | |_ _  _ _ _ __ _
//                          | ' </ _` | |  _| || | '_/ _` |
//                          |_|\_\__,_|_|\__|\_,_|_| \__,_|
//
// This file is part of the Kaltura Collaborative Media Suite which allows users
// to do with audio, video, and animation what Wiki platfroms allow them to do with
// text.
//
// Copyright (C) 2006-2011  Kaltura Inc.
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as
// published by the Free Software Foundation, either version 3 of the
// License, or (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Affero General Public License for more details.
//
// You should have received a copy of the GNU Affero General Public License
// along with this program.  If not, see <http://www.gnu.org/licenses/>.
//
// @ignore
// ===================================================================================================

/**
 * @namespace
 */
namespace Kaltura\Client;

/**
 * @package Kaltura
 * @subpackage Client
 */
class Base 
{
	const KALTURA_SERVICE_FORMAT_JSON = 1;
	const KALTURA_SERVICE_FORMAT_XML  = 2;
	const KALTURA_SERVICE_FORMAT_PHP  = 3;

	/**
	 * @var string
	 */
	protected $apiVersion = null;

	/**
	 * @var \Kaltura\Client\Configuration
	 */
	protected $config;
	
	/**
	 * @var string
	 */
	private $ks;
	
	/**
	 * @var boolean
	 */
	private $shouldLog = false;
	
	/**
	 * @var bool
	 */
	private $isMultiRequest = false;
	
	/**
	 * @var array<\Kaltura\Client\ServiceActionCall>
	 */
	private $callsQueue = array();
	
	/**
	* @var Array of response headers
	*/
	private $responseHeaders = array();
	
	/**
	 * Kaltura client constructor
	 *
	 * @param \Kaltura\Client\Configuration $config
	 */
	public function __construct(Configuration $config)
	{
	    $this->config = $config;
	    
	    $logger = $this->config->getLogger();
		if ($logger)
			$this->shouldLog = true;	
	}

	/* Store response headers into array */
	public function readHeader($ch, $string)
	{
		array_push($this->responseHeaders, $string);
		return strlen($string);	
	}
	
	/* Retrieve response headers */
	public function getResponseHeaders()
	{
		return $this->responseHeaders;
	}
	
	public function getServeUrl()
	{
		if (count($this->callsQueue) != 1)
			return null;
			 
		$params = array();
		$files = array();
		$this->log("service url: [" . $this->config->getServiceUrl() . "]");
		
		// append the basic params
		$this->addParam($params, "apiVersion", $this->apiVersion);
		$this->addParam($params, "format", $this->config->getFormat());
		$this->addParam($params, "clientTag", $this->config->getClientTag());
		
		$call = $this->callsQueue[0];
		$this->callsQueue = array();
		$this->isMultiRequest = false; 
		
		$params = array_merge($params, $call->params);
		$signature = $this->signature($params);
		$this->addParam($params, "kalsig", $signature);
		
		$url = $this->config->getServiceUrl() . "/api_v3/index.php?service={$call->service}&action={$call->action}";
		$url .= '&' . http_build_query($params); 
		$this->log("Returned url [$url]");
		return $url;
	}

	public function queueServiceActionCall($service, $action, $params = array(), $files = array())
	{
		// in start session partner id is optional (default -1). if partner id was not set, use the one in the config
		if (!isset($params["partnerId"]) || $params["partnerId"] === -1)
			$params["partnerId"] = $this->config->getPartnerId();
			
		$this->addParam($params, "ks", $this->ks);
		
		$call = new ServiceActionCall($service, $action, $params, $files);
		$this->callsQueue[] = $call;
	}
	
	/**
	 * Call all API service that are in queue
	 *
	 * @return object
	 */
	public function doQueue()
	{
		if (count($this->callsQueue) == 0)
		{
			$this->isMultiRequest = false; 
			return null;
		}
			 
		$startTime = microtime(true);
				
		$params = array();
		$files = array();
		$this->log("service url: [" . $this->config->getServiceUrl() . "]");
		
		// append the basic params
		$this->addParam($params, "apiVersion", $this->apiVersion);
		$this->addParam($params, "format", $this->config->getFormat());
		$this->addParam($params, "clientTag", $this->config->getClientTag());
		$this->addParam($params, "ignoreNull", true);
		
		$url = $this->config->getServiceUrl()."/api_v3/index.php?service=";
		if ($this->isMultiRequest)
		{
			$url .= "multirequest";
			$i = 1;
			foreach ($this->callsQueue as $call)
			{
				$callParams = $call->getParamsForMultiRequest($i);
				$callFiles = $call->getFilesForMultiRequest($i);
				$params = array_merge($params, $callParams);
				$files = array_merge($files, $callFiles);
				$i++;
			}
		}
		else
		{
			$call = $this->callsQueue[0];
			$url .= $call->service."&action=".$call->action;
			$params = array_merge($params, $call->params);
			$files = $call->files;
		}
		
		// reset
		$this->callsQueue = array();
		$this->isMultiRequest = false; 
		
		$signature = $this->signature($params);
		$this->addParam($params, "kalsig", $signature);
		
		list($postResult, $error) = $this->doHttpRequest($url, $params, $files);
						
		if ($error)
		{
			throw new ClientException($error, ClientException::ERROR_GENERIC);
		}
		else 
		{
			$this->log("result (serialized): " . $postResult);
			
			if ($this->config->getFormat() == self::KALTURA_SERVICE_FORMAT_XML)
			{
				$result = $this->unmarshal($postResult);

				if (is_null($result)) 
					throw new ClientException("failed to unserialize server result\n$postResult", ClientException::ERROR_UNSERIALIZE_FAILED);
			}
			else
			{
				throw new ClientException("unsupported format: $postResult", ClientException::ERROR_FORMAT_NOT_SUPPORTED);
			}
		}
		
		$endTime = microtime (true);
		
		$this->log("execution time for [".$url."]: [" . ($endTime - $startTime) . "]");
		
		return $result;
	}

	private static function unmarshalArray(\SimpleXMLElement $xmls)
	{
		$ret = array();
		foreach($xmls as $xml)
			$ret[] = self::unmarshalItem($xml);
			
		return $ret;
	}

	public static function unmarshalItem(\SimpleXMLElement $xml)
	{
		$nodeName = $xml->getName();
		
		if(!$xml->objectType)
		{
			if($xml->item)
				return self::unmarshalArray($xml->children());
				
			if($xml->error)
			{
				$code = "{$xml->error->code}";
				$message = "{$xml->error->message}";
				return new ApiException($message, $code);
			}
			
			return "$xml";
		}
		$objectType = reset($xml->objectType);
			
		$type = TypeMap::getZendType($objectType);
		if(!class_exists($type))
			throw new ClientException("Invalid object type class [$type] of Kaltura type [$objectType]", ClientException::ERROR_INVALID_OBJECT_TYPE);
			
		return new $type($xml);
	}

	private function unmarshal($xmlData)
	{
		$xml = new \SimpleXMLElement($xmlData);
		return self::unmarshalItem($xml->result);
	}

	/**
	 * Sign array of parameters
	 *
	 * @param array $params
	 * @return string
	 */
	private function signature($params)
	{
		ksort($params);
		$str = "";
		foreach ($params as $k => $v)
		{
			$str .= $k.$v;
		}
		return md5($str);
	}
	
	/**
	 * Send http request by using curl (if available) or php stream_context
	 *
	 * @param string $url
	 * @param parameters $params
	 * @return array of result and error
	 */
	private function doHttpRequest($url, $params = array(), $files = array())
	{
		if (!function_exists('curl_init'))
			throw new ClientException("Curl extension must be enabled", ClientException::ERROR_CURL_MUST_BE_ENABLED);
			
		return $this->doCurl($url, $params, $files);
	}

	/**
	 * Curl HTTP POST Request
	 *
	 * @param string $url
	 * @param array $params
	 * @return array of result and error
	 */
	private function doCurl($url, $params = array(), $files = array())
	{
		$this->responseHeaders = array();
		$cookies = array();
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, 1);
		if (count($files) > 0)
		{
			foreach($files as &$file)
				$file = "@".$file; // let curl know its a file
			curl_setopt($ch, CURLOPT_POSTFIELDS, array_merge($params, $files));
		}
		else
		{
			$opt = http_build_query($params, null, "&");
			$this->log("curl: $url&$opt");
			curl_setopt($ch, CURLOPT_POSTFIELDS, $opt);
		}
		curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, $this->config->getUserAgent());
		if (count($files) > 0)
			curl_setopt($ch, CURLOPT_TIMEOUT, 0);
		else
			curl_setopt($ch, CURLOPT_TIMEOUT, $this->config->getCurlTimeout());
			
		if ($this->config->getStartZendDebuggerSession() === true)
		{
			$zendDebuggerParams = $this->getZendDebuggerParams($url);
			$cookies = array_merge($cookies, $zendDebuggerParams);
		}
		
		if (count($cookies) > 0) 
		{
			$cookiesStr = http_build_query($cookies, null, '; ');
			curl_setopt($ch, CURLOPT_COOKIE, $cookiesStr);
		}
		
		if ($this->config->getProxyHost()) {
			curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true);
			curl_setopt($ch, CURLOPT_PROXY, $this->config->getProxyHost());
			if ($this->config->getProxyPort()) {
				curl_setopt($ch, CURLOPT_PROXYPORT, $this->config->getProxyPort());
			}
			if ($this->config->getProxyUser()) {
				curl_setopt($ch, CURLOPT_PROXYUSERPWD, $this->config->getProxyUser().':'.$this->config->getProxyPassword());
			}
			if ($this->config->getProxyType() === 'SOCKS5') {
				curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
			}	
		}
		
		// Set SSL verification
		if(!$this->config->getVerifySSL())
		{		
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		}
		
		// Set custom headers
		curl_setopt($ch, CURLOPT_HTTPHEADER, $this->config->getRequestHeaders());
		
		// Save response headers
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, array($this, 'readHeader') );
		
		$result = curl_exec($ch);
		$curlError = curl_error($ch);
		curl_close($ch);
		return array($result, $curlError);
	}

	/**
	 * @return string
	 */
	public function getKs()
	{
		return $this->ks;
	}
	
	/**
	 * @param string $ks
	 */
	public function setKs($ks)
	{
		$this->ks = $ks;
	}
	
	/**
	 * @return Configuration
	 */
	public function getConfig()
	{
		return $this->config;
	}
	
	/**
	 * @param Configuration $config
	 */
	public function setConfig(Configuration $config)
	{
		$this->config = $config;
		
		$logger = $this->config->getLogger();
		if ($logger instanceof ILogger)
		{
			$this->shouldLog = true;	
		}
	}
	
	/**
	 * @return string
	 */
	public function getApiVersion()
	{
		return $this->apiVersion;
	}

	/**
	 * @param string $apiVersion
	 */
	public function setApiVersion($apiVersion)
	{
		$this->apiVersion = $apiVersion;
	}
	
	/**
	 * Add parameter to array of parameters that is passed by reference
	 *
	 * @param arrat $params
	 * @param string $paramName
	 * @param string $paramValue
	 */
	public function addParam(&$params, $paramName, $paramValue)
	{
		if ($paramValue === null)
			return;
			
		if ($paramValue instanceof NullValue) {
			$params[$paramName . '__null'] = '';
			return;
		}
			
		if(is_object($paramValue) && $paramValue instanceof ObjectBase)
		{
			$this->addParam($params, "$paramName:objectType", $paramValue->getKalturaObjectType());
		    foreach($paramValue as $prop => $val)
				$this->addParam($params, "$paramName:$prop", $val);
				
			return;
		}	
		
		if(!is_array($paramValue))
		{
			$params[$paramName] = (string)$paramValue;
			return;
		}
		
		if ($paramValue)
		{
			foreach($paramValue as $subParamName => $subParamValue)
				$this->addParam($params, "$paramName:$subParamName", $subParamValue);
		}
		else
		{
			$this->addParam($params, "$paramName:-", "");
		}
	}
	
	/**
	 * Validate the result object and throw exception if its an error
	 *
	 * @param object $resultObject
	 */
	public function throwExceptionIfError($resultObject)
	{
		if ($this->isError($resultObject))
		{
			throw $resultObject;
		}
	}
	
	/**
	 * Checks whether the result object is an error
	 *
	 * @param object $resultObject
	 */
	public function isError($resultObject)
	{
		return ($resultObject instanceof ApiException);
	}
	
	/**
	 * Validate that the passed object type is of the expected type
	 *
	 * @param any $resultObject
	 * @param string $objectType
	 */
	public function validateObjectType($resultObject, $objectType)
	{
		if (is_object($resultObject))
		{
			if (!($resultObject instanceof $objectType))
				throw new ClientException("Invalid object type", ClientException::ERROR_INVALID_OBJECT_TYPE);
		}
		else if (gettype($resultObject) != "NULL" && gettype($resultObject) != $objectType)
		{
			throw new ClientException("Invalid object type [" . gettype($resultObject) . "] expected [$objectType]", ClientException::ERROR_INVALID_OBJECT_TYPE);
		}
	}
	
	public function startMultiRequest()
	{
		$this->isMultiRequest = true;
	}
	
	public function doMultiRequest()
	{
		return $this->doQueue();
	}
	
	public function isMultiRequest()
	{
		return $this->isMultiRequest;	
	}
		
	public function getMultiRequestQueueSize()
	{
		return count($this->callsQueue);	
	}
	
    public function getMultiRequestResult()
	{
        return new MultiRequestSubResult($this->getMultiRequestQueueSize() . ':result');
	}
	
	/**
	 * @param string $msg
	 */
	protected function log($msg)
	{
		if ($this->shouldLog)
			$this->config->getLogger()->log($msg);
	}
	
	/**
	 * Return a list of parameters used to start a new debug session on the destination server api
	 * @link http://kb.zend.com/index.php?View=entry&EntryID=434
	 * @param $url
	 */
	protected function getZendDebuggerParams($url)
	{
		$params = array();
		$passThruParams = array('debug_host',
			'debug_fastfile',
			'debug_port',
			'start_debug',
			'send_debug_header',
			'send_sess_end',
			'debug_jit',
			'debug_stop',
			'use_remote');
		
		foreach($passThruParams as $param)
		{
			if (isset($_COOKIE[$param]))
				$params[$param] = $_COOKIE[$param];
		}
		
		$params['original_url'] = $url;
		$params['debug_session_id'] = microtime(true); // to create a new debug session
		
		return $params;
	}
	
	public function generateSession($adminSecretForSigning, $userId, $type, $partnerId, $expiry = 86400, $privileges = '')
	{
		$rand = rand(0, 32000);
		$expiry = time()+$expiry;
		$fields = array ( 
			$partnerId , 
			$partnerId , 
			$expiry , 
			$type, 
			$rand , 
			$userId , 
			$privileges 
		);
		$info = implode ( ";" , $fields );

		$signature = $this->hash ( $adminSecretForSigning , $info );	 
		$strToHash =  $signature . "|" . $info ;
		$encoded_str = base64_encode( $strToHash );

		return $encoded_str;
	}
	
	private function hash ( $salt , $str )
	{
		return sha1($salt.$str);
	}

	/**
	 * @return KalturaNull
	 */
	public static function getKalturaNullValue()
	{
        return NullValue::getInstance();
	}
	
	public function __get($prop)
	{
		$getter = 'get'.ucfirst($prop).'Service';
		if (method_exists($this, $getter))
			return $this->$getter();
	}
}
