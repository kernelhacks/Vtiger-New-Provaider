<?php
/*+***********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.0
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is:  vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 *************************************************************************************/

class SMSNotifier_BulkVS_Provider implements SMSNotifier_ISMSProvider_Model {

	private $parameters = array();
    private static $SKIPPED_PARAMS = array('callback');
	private $API_URI = 'https://portal.bulkvs.com/api/v1.0/messageSend';
	private static $REQUIRED_PARAMETERS = array(array('name'=>'fromphone','label'=>'From','type'=>'text'),array('name'=>'api_key','label'=>'API Key','type'=>'text'));


	/**
	 * Function to get provider name
	 * @return <String> provider name
	 */
	public function getName() {
		return 'BulkVS';
	}

	/**
	 * Function to get required parameters other than (userName, password)
	 * @return <array> required parameters list
	 */
	public function getRequiredParams() {
        //To set default values
        foreach (self::$REQUIRED_PARAMETERS as $index => $parameters) {
            self::$REQUIRED_PARAMETERS[$index] = $parameters;
        }
        return self::$REQUIRED_PARAMETERS;
    }

    /**
	 * Function to set authentication parameters
	 * @param <String> $userName
	 * @param <String> $password
	 */
	public function setAuthParameters($userName, $password) {
		return false;
	}

	/**
	 * Function to set non-auth parameter.
	 * @param <String> $key
	 * @param <String> $value
	 */
	public function setParameter($key, $value) {
		$this->parameters[$key] = $value;
	}

	/**
	 * Function to get parameter value
	 * @param <String> $key
	 * @param <String> $defaultValue
	 * @return <String> value/$default value
	 */
	public function getParameter($key, $defaultValue = false) {
		if(isset($this->parameters[$key])) {
			return $this->parameters[$key];
		}
		return $defaultValue;
	}

	/**
	 * Function to prepare parameters
	 * @return <Array> parameters
	 */
	protected function prepareParameters() {
		//Default parameters required by provider
		foreach (self::$REQUIRED_PARAMETERS as $key=>$fieldInfo) {
			if(in_array($fieldInfo['name'], self::$SKIPPED_PARAMS)) continue;
			$params[$fieldInfo['name']] = $this->getParameter($fieldInfo['name']);
		}
		return $params;
	}
	
	/**
	 * Function to get service URL to use for a given type
	 * @param <String> $type like SEND, PING, QUERY
	 */
	public function getServiceURL($type = false) {
		return $this->API_URI;
	}

	/**
	 * Function to handle SMS Send operation
	 * @param <String> $message
	 * @param <Mixed> $toNumbers One or Array of numbers
	 */
	public function send($message, $toNumbers) {
        if (!is_array($toNumbers)) {
            $toNumbers = array($toNumbers);
        }

        $params = $this->prepareParameters();
        $params['text'] = $message;
        $params['to'] = implode(',', $toNumbers);
        $messageParams = array(
			'From' => $params['fromphone'],
			'To' => $toNumbers,
            'Message' => $params['text'],
        );
     
        $headers = array('accept' => 'application/json', 'Content-Type' => 'application/json', 'Authorization' => $params['api_key']);
        $httpClient = new Vtiger_Net_Client($this->getServiceURL());
        $httpClient->setHeaders($headers);
        $response = $httpClient->doPost(json_encode($messageParams));
        $decodedResponse = Zend_JSON::decode($response);
        $results = array();		

        foreach ($toNumbers as $toNumbers) {
            $result = array();
            if ($decodedResponse['Results'][0]["Status"] == "SUCCESS") {
                $result['id'] = $decodedResponse['RefId'];
                $result['to'] = $decodedResponse['Results'][0]['To'];
                $result['status'] = self::MSG_STATUS_DELIVERED;
            } else {
                $result['error'] = true;
                $result['statusmessage'] = $decodedResponse['error'];
                $result['to'] = $decodedResponse['to'];
            }
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Function to get query for status using messgae id
     * @param <Number> $messageId
     */
	public function query($messageId) {
		$result = array();
		
		$messageInfo = $this->getMessageStatus($messageId);
		$result['statusmessage'] = $messageInfo['statusmessage'];
		$result['status'] = $messageInfo['status'];
		$result['needlookup'] = 0;
		return $result;
	}
	
	
	function generateCallbackURL() {
		global $site_URL;
		$secret = md5(strtotime(date('Y-m-d H:i:s')));
		$siteUrl = trim($site_URL, '/');
		return $siteUrl."/modules/SMSNotifier/callbacks/BulkVS.php?vtsecret=".$secret;
	}
	
	function getMessageStatus($messageId) {
		$db = PearDatabase::getInstance();
		$query = "SELECT * FROM vtiger_smsnotifier_status WHERE smsmessageid=?";
		$result = $db->pquery($query, array($messageId));
		
		if($db->num_rows($result) > 0) {
			return $db->query_result_rowdata($result, 0);
		}
		return array();
	}
	function getProviderEditFieldTemplateName() {
		return 'ClickATellNew.tpl';
	}
    
	function updateMessageStatus(Vtiger_Request $request) {
		$messageId = $request->get('messageId');
		$status = $request->get('status');
		$messageDescription = $request->get('statusDescription');		
		$db = PearDatabase::getInstance();
		$query = "UPDATE vtiger_smsnotifier_status SET status=?, statusmessage=? WHERE smsmessageid=?";
		$db->pquery($query, array($status, $messageDescription, $messageId));
	}
	
	function validateRequest($request) {
		$db = PearDatabase::getInstance();
		$query = "SELECT id FROM vtiger_smsnotifier_servers WHERE parameters like ? AND providertype=? AND isactive=?";
		$result = $db->pquery($query, array('%'.$request->get('vtsecret').'%', $this->getName(), 1));
		
		if($db->num_rows($result) > 0) {
			return true;
		}
		return false;
	}
	

}
?>
