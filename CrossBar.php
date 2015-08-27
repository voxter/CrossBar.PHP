<?php

require_once("kazoo-php-sdk/vendor/autoload.php");
require_once("FailureHandler.php");

/**
 * Wrapper for official kazoo-php-sdk while integrating with existing aastra apps
 * Author: Daniel Finke 2015
 * Some code borrowed from Chris Megalos' previous CrossBar SDK
 *
 * TODO: unauthenticated Riak tie-in
 **/
class CrossBar {

	var $host; /**< ipv4 address or hostname */
	var $port;
	var $xauth;
	var $use_account_id;
	var $auth_account_id;
	var $usermd5; /**< usermd5 md5 of "user:password" */
	var $realm;
	var $authToken;

	var $is_authenticated = false; /**< is_authenticated boolean flag that is changed upon authentication. */

	var $logfile; /**< Log file */
	var $color = true;
	var $colors = array();
	var $debug = false;	/**< debug enables debugging if true. */

	// The heavy-lifting beast used for requests
	var $sdk;

	// Handler for dealing with problems that occur during requests
	// Should implement FailureHandler interface
	var $failureHandler;

	//var $force_no_decode = false;  /**< force_no_decode disables json_decode from the send method. */

	public function __construct($options) {
		// Default log locations
		if(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			$this->logfile = "c:/Program Files (x86)/Apache Software Foundation/Apache2.2/logs/xbar.log";
		}
		else {
			$this->logfile = "/var/log/xbar.log";
		}

		$this->colors = array(
			"red" => chr(0x1B).'[1;31m',
			"green" => chr(0x1B).'[1;32m',
			"yellow" => chr(0x1B).'[1;33m',
			"blue" => chr(0x1B).'[1;34m',
			"purple" => chr(0x1B).'[1;35m',
			"cyan" => chr(0x1B).'[1;36m',
			"white" => chr(0x1B).'[1;37m',
			"reset" => chr(0x1B).'[0m'
		);

		$scheme = 'http';

		// Add variables
		foreach($options as $key => $value) {
			$this->$key = $value;
		}

		// Server to connect to for crossbar API reqs
		$kazooOpts = array(
			'base_url' => $scheme . '://' . $this->host . ':' . $this->port
		);

		// Auth token from cached creds
		if(isset($this->xauth) && isset($this->auth_account_id)) {
			$this->authToken = new \Kazoo\AuthToken\XAuth($this->xauth, $this->auth_account_id);
			$this->is_authenticated = true;
		}
		// Or from user md5
		else {
			$this->authToken = new \Kazoo\AuthToken\UserMd5($this->usermd5, $this->realm);
		}

		// Create SDK used for reqs
		$this->sdk = new \Kazoo\SDK($this->authToken, $kazooOpts);

		// We assume xauth mode is already authenticated
		// If it fails it is handled by FailureHandler at request time
		$this->validateAuthToken();
	}
	
	public function validateAuthToken() {
		try {
			$this->xauth = $this->authToken->getToken();
			$this->auth_account_id = $this->authToken->getAccountId();
			$this->use_account_id = $this->authToken->getAccountId();
			$this->is_authenticated = true;
		}
		catch(Kazoo\AuthToken\Exception\Unauthenticated $e) {
			$this->is_authenticated = false;
			// Side effect of logging the auth failure for us
			$this->formatException($e);
		}
		catch(Kazoo\Api\Exception\Validation $e) {
			$this->is_authenticated = false;
			// Side effect of logging the auth failure for us
			$this->formatException($e);
		}
		return $this->is_authenticated;
	}

	function use_account($accountId, $cache = false) {
		$this->use_account_id = $accountId;

		//load all data?
		/*if($cache) {
			$this->cache = true;
		}
		else {
			$this->cache = false;
		}*/
	}

	/**
	@brief Writes a log line to /var/log/xbar.log (default) or if $debug is set then it's sent to stdout
	@param $logthis is the string to log
	@return null
	**/
	public function log($logthis) {
		if($this->color) {
			$logthis = $this->applyColor($logthis);
		}

		if($this->debug) {
			echo $logthis."\n";
		}
		else {
			syslog(LOG_INFO,"CrossBar: ".$_SERVER['REMOTE_ADDR']." - ".$logthis);
			file_put_contents($this->logfile,date("Y-m-d H:i:s")." - {$_SERVER['REMOTE_ADDR']} - ".$logthis."\n",FILE_APPEND);
		}
	}

	private function applyColor($text) {
		foreach($this->colors as $key => $color) {
			$text = str_replace('<'.$key.'>', $color, $text);
		}
		return $text;
	}

	/**
	 *
	 * Start of API methods
	 *
	 *
	 *
	 **/

	/**
	@brief attempts to retrieve the version. Usually returns 403 forbidden
	@return array data representing about page
	**/
	function get_version() {
		return $this->get(array(
			'About' => array()
		));
	}

	function get_accounts($accountId = null) {
		$children = $this->get_children($accountId);
		$realms = array();
		foreach($children as $child) {
			$realms[$child['realm']] = $child['id'];
		}

        $crealm = $this->get_account($this->auth_account_id);
        $realms[$crealm['realm']] = $this->auth_account_id;

		return $realms;
	}

	function get_account($accountId = null) {
		return $this->get(array('Account' => array('id' => $accountId)));
	}

	function get_account_id_by_did($did, $realmId = null) {
		if($realmId == null) {
			$realmId = $this->use_account_id;
		}

		$child_nums = array();
		$current_nums = array();

		static $account_id = null;

		$check_response = $this->get(array(
			'Account' => array('id' => $realmId),
			'PhoneNumbers' => array()
		));
		foreach($check_response['numbers'] as $number => $data) {
			if($number == $did) {
				$account_id = $realmId;
				return $realmId;
			}
		}

		$children = $this->get_children($realmId);
		foreach($children as $child) {
			$account_id = $this->get_account_id_by_did($did, $child['id']);
			if($account_id != null) {
				return $account_id;
			}
		}

		return $account_id;
	}

	function get_account_id_by_realm($realm, $accountId = null) {
		$realms = $this->get_accounts($accountId);
		return $realms[$realm];
	}

	function put_account($data, $accountId = null) {
		/*if($accountId != null) {
			$this->use_account_id = $accountId;
		}*/
		return $this->put(array(
		    'Account' => array('id' => $accountId),
		    'addChild' => array('args' => $data)
		), null);
	}

	function post_account($data, $accountId = null) {
		return $this->post(array(
			'Account' => array('id' => $accountId)
		), $data);
	}

	function del_account($accountId) {
		return $this->del(array(
			'Account' => array('id' => $accountId)
		));
	}

	function get_children($accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'children' => array()
		));
	}

	function get_siblings($accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'siblings' => array()
		));
	}

	function get_descendants($accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'descendants' => array()
		));
	}
//
	function set_parent($account_id, $new_parent_id ) {
		if( $account_id == null ) $account_id = $this->use_account_id;
		$response = $this->send("POST","/v1/accounts/{$account_id}/parent","parent=$new_parent_id");
		return($response['data']);
	}

	function get_credits($accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'braintreeCredits' => array()
		));
	}

	/**
	@brief Gets the details on all the cdrs
	@param $filters	  The filters to apply to the search
	@param $accountId The account that the cdr belongs too
	@return $response The response is an array that is just passed through.
	**/
	function get_cdrs($filters = array(), $accountId = null) {
		if($filters == null) {
			$filters = array();
		}

		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Cdrs' => array('filters' => $filters)
		));
	}

	function get_connectivity($cid = null, $accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Connectivities' => array('id' => $cid)
		));
	}

	/**
	@brief updates the connectivity
	@return string json data
	**/
	function put_connectivity($data, $accountId = null) {
		return $this->put(array(
			'Account' => array('id' => $accountId),
			'Connectivity' => array()
		), $data);
	}

	/**
	@brief create new connectivity (attach a pbx)
	@return string json data
	**/
	function post_connectivity($data, $cid, $accountId = null) {
		return $this->post(array(
			'Account' => array('id' => $accountId),
			'Connectivity' => array('id' => $cid)
		), $data);
	}

	/**
	@brief deletes the connectivity
	@return string json data
	**/
	function del_connectivity($cid, $accountId = null) {
		return $this->del(array(
			'Account' => array('id' => $accountId),
			'Connectivity' => array('id' => $cid)
		));
	}

	function get_pbxs($accountId = null) {
		return $this->get_connectivity(null, $accountId);
	}

	function get_pbx($cid, $accountId = null) {
		return $this->get_connectivity($cid, $accountId);
	}

	function put_pbx($data, $accountId = null) {
		return $this->put_connectivity($data, $accountId);
	}

	function post_pbx($data, $accountId = null) {
		return $this->post_connectivity($data, $accountId);
	}

	function del_pbx($cid, $accountId = null) {
		return $this->del_connectivity($cid, $accountId);
	}

	function get_callflows($accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Callflows' => array()
		));
	}

	function get_callflow_id_map($accountId = null) {
		$cfs = $this->get_callflows($accountId);
		$nums = array();
		foreach($cfs as $key => $data) {
			foreach($data['numbers'] as $num) {
				$nums[$num] = $data['id'];
			}
		}

		return $nums;
	}

	function get_callflows_by($accountId = null, $type = 'device') {
		$aout = array();
		$cfs = $this->get_callflows($accountId);

		foreach($cfs as $cf) {
			$xcf = $this->get_callflow($cf['id']);
			foreach($xcf['metadata'] as $key => $data) {
				if($data['pvt_type'] == $type) {
					$aout[$xcf['id']] = $cf['numbers'];
				}
			}
		}

		return $aout;
	}

	/**
	 * Get all callflows which have user object with the user's id
	 * @param $userId		user id who the call flows should reference
	 * @param $accountId	kazoo account id to search in
	 */
	function get_callflows_by_user($userId, $accountId = null) {
		$cfs = $this->get_callflows($accountId);

		$userCfs = array();
		foreach($cfs as $cf) {
			// User ID required
			if(!isset($cf['user_id'])) {
				continue;
			}

			// If user matches, add them
			if($cf['user_id'] == $userId) {
				$userCfs[] = $cf;
			}
		}

		return $userCfs;
	}

	function get_callflow($cfId, $accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Callflow' => array('id' => $cfId)
		));
	}

	function put_callflow($data, $accountId = null) {
		return $this->put(array(
			'Account' => array('id' => $accountId),
			'Callflow' => array()
		), $data);
	}

	function post_callflow($data, $cfId, $accountId = null) {
		return $this->post(array(
			'Account' => array('id' => $accountId),
			'Callflow' => array('id' => $cfId)
		), $data);
	}

	function del_callflow($cfId, $accountId = null) {
		return $this->del(array(
			'Account' => array('id' => $accountId),
			'Callflow' => array('id' => $cfId)
		));
	}

	function get_all_info($accountId = null) {
		$temp_users = $this->get_users($accountId);
		$temp_devices = $this->get_devices($accountId);
		$devices_status = $this->get_devices_status($accountId);
		$vmboxes = $this->get_vmboxes($accountId);
		ob_start();
		$this->log(ob_get_clean());

		$users = array();

		foreach( $temp_users as $user ) { $users[$user['id']] = $user; }
		foreach( $temp_devices as $device ) {
			if( isset($devices_status[$device['id']]) ) $device['online'] = true;
			$device['user'] = $users[$device['owner_id']];
		}
	}

	function get_users($accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Users' => array()
		));
	}

	function get_user_by_name($username, $accountId = null) {
		$response = $this->get(array(
			'Account' => array('id' => $accountId),
			'Users' => array('filters' => array('username' => $username))
		));
		return $response[0];
	}

	function get_user_id($username) {
		$user = $this->get_user_by_name($username);
		if(isset($user['id'])) {
			return $user['id'];
		}
		return false;
	}

	function get_user($userId, $accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'User' => array('id' => $userId)
		));
	}

	function put_user($data, $accountId = null) {
		return $this->put(array(
			'Account' => array('id' => $accountId),
			'User' => array()
		), $data);
	}

	function post_user($data, $userId, $accountId = null) {
		return $this->post(array(
			'Account' => array('id' => $accountId),
			'User' => array('id' => $userId)
		), $data);
	}

	function del_user($userId, $accountId = null) {
		return $this->del(array(
			'Account' => array('id' => $accountId),
			'User' => array('id' => $userId)
		));
	}

	function get_devices($accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Devices' => array()
		));
	}
	
	function get_devices_status($accountId = null) {
	    return $this->get(array(
			'Account' => array('id' => $accountId),
			'Devices' => array(),
			'status' => array()
		));
	}

	function get_devices_by_owner($ownerId, $accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Devices' => array('filters' => array('owner_id' => $ownerId))
		));
	}

	function get_device_by_name($name, $accountId = null) {
		$response = $this->get(array(
			'Account' => array('id' => $accountId),
			'Devices' => array('filters' => array('name' => $name))
		));
		return $response[0];
	}

	function get_device_by_owner($ownerId, $accountId = null) {
		$response = $this->get_devices_by_owner($ownerId, $accountId);
		return $response[0];
	}

	function get_device($deviceId, $accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Device' => array('id' => $deviceId)
		));
	}

	function put_device($data, $accountId = null) {
		return $this->put(array('Account' => array('id' => $accountId), 'Device' => array()), $data);
	}

	function post_device($data, $deviceId, $accountId = null) {
		return $this->post(array(
			'Account' => array('id' => $accountId),
			'Device' => array('id' => $deviceId)
		), $data);
	}

	function del_device($deviceId, $accountId = null) {
		return $this->del(array(
			'Account' => array('id' => $accountId),
			'Device' => array('id' => $deviceId)
		));
	}

	function get_vmboxes($accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'VMBoxes' => array()
		));
	}

	function get_vmbox($boxId, $accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'VMBox' => array('id' => $boxId)
		));
	}

	function get_vmbox_by_name($name, $accountId = null) {
		$response = $this->get(array(
			'Account' => array('id' => $accountId),
			'VMBoxes' => array('filters' => array('name' => $name))
		));
		return $response[0];
	}

	function get_vmbox_by_ext($extension, $accountId = null) {
		$response = $this->get(array(
			'Account' => array('id' => $accountId),
			'VMBoxes' => array('filters' => array('mailbox' => $extension))
		));
		return $response[0];
	}

	function get_vmbox_by_number($number, $accountId = null) {
		$response = $this->get(array(
			'Account' => array('id' => $accountId),
			'VMBoxes' => array('filters' => array('mailbox' => $number))
		));
		return $response[0];
	}

	function get_vmbox_by_owner($ownerId, $accountId = null) {
		$response = $this->get(array(
			'Account' => array('id' => $accountId),
			'VMBoxes' => array('filters' => array('owner_id' => $ownerId))
		));
		foreach($response as $key => $data) {
			$response[$key] = array_merge($data, $this->get_vmbox($data['id'], $accountId));
		}
		return $response;
	}

	function put_vmbox($data, $accountId = null) {
		return $this->put(array(
			'Account' => array('id' => $accountId),
			'VMBox' => array()
		), $data);
	}

	function post_vmbox($data, $boxId, $accountId = null) {
		return $this->post(array(
			'Account' => array('id' => $accountId),
			'VMBox' => array('id' => $boxId)
		), $data);
	}

	function del_vmbox($boxId, $accountId = null) {
		return $this->del(array(
			'Account' => array('id' => $accountId),
			'VMBox' => array('id' => $boxId)
		));
	}

	function login_vmbox($mailbox, $pin, $accountId = null) {
		$response = $this->get(array(
			'Account' => array('id' => $accountId),
			'VMBoxes' => array('filters' => array('mailbox' => $mailbox, 'pin' => $pin))
		));
		return $response[0];
	}

	function get_messages($boxId, $accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'VMBox' => array('id' => $boxId),
			'Messages' => array()
		));
	}

	function get_message($messageId, $boxId, $accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'VMBox' => array('id' => $boxId),
			'Message' => array('id' => $messageId)
		));
	}

	// TODO: make this work with Message\Response class
	function get_message_raw($messageId, $boxId, $accountId = null) {
		if($accountId == null) {
			$accountId = $this->use_account_id;
		}

		return $this->sdk->get(
			"http://{$this->host}:{$this->port}/v1/accounts/{$accountId}/vmboxes/{$boxId}/messages/{$messageId}/raw");
	}

	function del_message($messageId, $boxId, $accountId = null) {
		return $this->del(array(
			'Account' => array('id' => $accountId),
			'VMBox' => array('id' => $boxId),
			'Message' => array('id' => $messageId)
		));
	}

	function get_queues($accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Queues' => array()
		));
	}

	function get_queues_stats($accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Queues' => array(),
			'stats' => array()
		));
	}

	function get_queues_waiting_calls($accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Queues' => array(),
			'stats' => array('args' => array(array('status' => 'waiting')))
		));
	}

	function get_queue($queueId, $accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Queue' => array('id' => $queueId)
		));
	}

	function get_queue_status($queueId, $agentId, $accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId)
		));
	}

	function get_agents_stats($accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Agents' => array(),
			'stats' => array()
		));
	}

	function get_agent_status($agentId, $type = null, $accountId = null) {
		if(!is_null($type)) {
			return $this->get(array(
				'Account' => array('id' => $accountId),
				'Agent' => array('id' => $agentId),
				'status' => array('args' => array(array('status' => $type, 'recent' => 'true')))
			));
		}
		else {
			return $this->get(array(
				'Account' => array('id' => $accountId),
				'Agent' => array('id' => $agentId),
				'status' => array('args' => array(array('recent' => 'true')))
			));
		}
	}

	function logout_queue($queueId, $accountId = null) {
		$queue = $this->sdk->Account($accountId)->Queue($queueId);

		return $queue->saveRoster(array());
	}

	function login_agent($agentId, $queueId, $accountId = null) {
		$queue = $this->sdk->Account($accountId)->Queue($queueId);
		$queueAgents = $queue->agents;

		foreach($queueAgents as $key => $agent) {
			if($agent == $agentId) {
				unset($queueAgents[$key]);
			}
		}

		$queueAgents[] = $agentId;
		return $queue->saveRoster($queueAgents);
	}

	function logout_agent($agentId, $queueId, $accountId = null) {
		$queue = $this->sdk->Account($accountId)->Queue($queueId);
		$queueAgents = $queue->agents;

		foreach($queueAgents as $key => $agent) {
			if($agent == $agentId) {
				unset($queueAgents[$key]);
			}
		}

		return $queue->saveRoster(array_values($queueAgents));
	}

	/**
	@brief Gets the details on all the faxes
	@param $accountId The account that the fax belongs too
	@return $response The response is an array that is just passed through.
	**/
	function get_faxes($accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Faxes' => array(),
			'outgoing' => array()
		));
	}

	/**
	@brief Gets the details on a specific fax
	@param $faxId The unique fax id as returned by the get_faxes func
	@param $accountId The account that the fax belongs too
	@return $response The response is an array that is just passed through.
	**/
    function get_fax($faxId = null, $accountId = null) {
    	if($faxId == null) {
    		return $this->get_faxes($accountId);
    	}

    	return $this->get(array(
    		'Account' => array('id' => $accountId),
    		'Fax' => array('id' => $faxId)
    	));
	}

	/**
	@brief Gets the actual fax file that was sent
	@param $fax_id The unique fax id as returned by the get_faxes func
	@param $account_id The account that the fax belongs too
	@return $response The response is an array that is just passed through.
	**/
	// TODO: finish implementation
	function get_fax_file( $fax_id = null, $account_id = null ) {
		// Doesn't exist in the 2600 api yet but should.
		$bldred=chr(0x1B).'[1;31m';
		$this->log("{$bldred}!!!!: get_fax_file not yet implemented.");

		return( array('status' => 'failure', 'message' => 'del_fax not yet implemented.') );

		/*
		// From the get_media_raw call above and tweaked a bit.
		$tmp = $this->force_no_decode;
		$this->force_no_decode = true;

		if( $account_id == null ) $account_id = $this->use_account_id;
			$response = $this->send("GET","/v1/accounts/{$account_id}/faxes/{$fax_id}/file" );

		$this->force_no_decode = $tmp;
		return($response);

		*/
	}

	/**
	@brief Accepts a URL to a PDF to then fax out
	@param $data The data as defined at https://2600hz.atlassian.net/wiki/display/docs/Faxes+API
	@param $accountId The account that is sending the fax
	@return $response The response is an array that is just passed through.
	**/
	function put_fax($data, $accountId = null) {
		return $this->put(array(
			'Account' => array('id' => $accountId),
			'Fax' => array()
		), $data['data']);
	}

	/**
	@brief Deletes a fax from the queue?  Do we really need this?
	@param $faxId The unique fax id as returned by the get_faxes func
	@param $accountId The account that the fax belongs too
	@return $response The response is an array that is just passed through.
	**/
	// TODO: finish implementation
	function del_fax($faxId, $accountId = null) {
		// Doesn't exist in the 2600 api yet but should.
		$bldred=chr(0x1B).'[1;31m';
		$this->log("{$bldred}!!!!: del_fax not yet implemented.");

		return( array('status' => 'failure', 'message' => 'del_fax not yet implemented.') );

		/*
		// From the del_media call above and tweaked a bit.
		if( $account_id == null ) $account_id = $this->use_account_id;
			$response = $this->send("DELETE","/v1/accounts/{$account_id}/faxes/{$fax_id}" );
		return($response);
		*/
	}

	function get_media($mediaId = null, $accountId = null) {
	    if($mediaId == null) {
	        return $this->get(array(
                'Account' => array('id' => $accountId),
                'Medias' => array()
            ));
	    }
	    else {
            return $this->get(array(
                'Account' => array('id' => $accountId),
                'Media' => array('id' => $mediaId)
            ));
		}
	}
	
	function get_media_by_name($name, $accountId = null) {
		$response = $this->get(array(
			'Account' => array('id' => $accountId),
			'Medias' => array('filters' => array('name' => $name))
		));
		return $response[0];
	}

	function get_media_raw($mediaId = null, $accountId = null) {
	    return $this->get(array(
	        'Account' => array('id' => $accountId),
	        'Media' => array('id' => $mediaId),
	        'getRaw' => array()
	    ));
	}

	function put_media($data, $accountId = null) {
		$response = $this->put(array(
			'Account' => array('id' => $accountId),
			'Media' => array()
		), $data['data']);
		
		if($response['status'] == 'success' && isset($response['data']['id']) &&
		    isset($data['raw'])) {
		    return $this->post(array(
		        'Account' => array('id' => $accountId),
		        'Media' => array('id' => $response['data']['id']),
		        'postRaw' => array('args' => array(
		            'raw' => $data['raw'],
		            'type' => $data['type']
		        ))
		    ), array('id' => $response['data']['id']));
		}
		else {
		    return $response;
		}
	}

	function post_media($data, $mediaId, $accountId = null) {
		$response = $this->post(array(
			'Account' => array('id' => $accountId),
			'Media' => array('id' => $mediaId)
		), $data['data']);
		
		if($response['status'] == 'success' && isset($data['raw'])) {
		    return $this->post(array(
		        'Account' => array('id' => $accountId),
		        'Media' => array('id' => $response['data']['id']),
		        'postRaw' => array('args' => array(
		            'raw' => $data['raw'],
		            'type' => $data['type']
		        ))
		    ), null);
		}
		else {
		    return $response;
		}
	}

	function del_media($mediaId, $accountId = null) {
		return $this->del(array(
			'Account' => array('id' => $accountId),
			'Media' => array('id' => $mediaId)
		));
	}

	function get_menus($accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Menus' => array()
		));
	}

	function get_menu($menuId, $accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Menu' => array('id' => $menuId)
		));
	}

	function get_menu_by_name($name, $accountId = null) {
		$response = $this->get(array(
			'Account' => array('id' => $accountId),
			'Menus' => array('filters' => array('name' => $name))
		));
		return $response[0];
	}

	function put_menu($data, $accountId = null) {
		return $this->put(array(
			'Account' => array('id' => $accountId),
			'Menu' => array()
		), $data);
	}

	function post_menu($data, $menuId, $accountId = null) {
		return $this->post(array(
			'Account' => array('id' => $accountId),
			'Menu' => array('id' => $menuId)
		), $data);
	}

	function del_menu($menuId, $accountId = null) {
		return $this->del(array(
			'Account' => array('id' => $accountId),
			'Menu' => array('id' => $menuId)
		));
	}

	function get_conferences($accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Conferences' => array()
		));
	}

	function get_conference($conferenceId = null, $accountId = null) {
		if($conferenceId == null) {
			return $this->get_conferences($accountId);
		}
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Conference' => array('id' => $conferenceId)
		));
    }

    function get_conference_by_name($name, $accountId = null) {
    	$response = $this->get(array(
    		'Account' => array('id' => $accountId),
    		'Conferences' => array('filters' => array('name' => $name))
    	));
		return $response[0];
	}

	function put_conference($data, $accountId = null) {
		return $this->put(array(
			'Account' => array('id' => $accountId),
			'Conference' => array()
		), $data);
	}

	function post_conference($data, $conferenceId, $accountId = null) {
		return $this->post(array(
			'Account' => array('id' => $accountId),
			'Conference' => array('id' => $conferenceId)
		), $data);
	}

	function del_conference($conferenceId = null, $accountId = null) {
		if($conferenceId == null) {
			return $this->delNoIdException();
		}

		return $this->del(array(
			'Account' => array('id' => $accountId),
			'Conference' => array('id' => $conferenceId)
		));
	}

	function get_conference_participants($conferenceId, $accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Conference' => array('id' => $conferenceId),
			'details' => array()
		));
	}

	public function conference_participant_action($action, $conferenceId, $participantId, $accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Conference' => array('id' => $conferenceId),
			'action' => array('args' => array($action, $participantId))
		));
	}

	function get_directories($accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Directories' => array()
		));
	}

	function get_directory($directoryId = null, $accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Directory' => array('id' => $directoryId)
		));
	}

    function get_directory_by_name($name, $accountId = null) {
    	$response = $this->get(array(
			'Account' => array('id' => $accountId),
			'Directories' => array('filters' => array('name' => $name))
		));
		return $response[0];
	}

	function put_directory($data, $accountId = null) {
		return $this->put(array(
			'Account' => array('id' => $accountId),
			'Directory' => array()
		), $data);
	}

	function post_directory($data, $directoryId, $accountId = null) {
		return $this->post(array(
			'Account' => array('id' => $accountId),
			'Directory' => array('id' => $directoryId)
		), $data);
	}

	function del_directory($directoryId = null, $accountId = null) {
		return $this->del(array(
			'Account' => array('id' => $accountId),
			'Directory' => array('id' => $directoryId)
		));
	}

	function get_realm_numbers($realmId = null) {
		if($realmId == null) {
			$realmId = $this->use_account_id;
		}

		$child_nums = array();
		$current_nums = array();

		$check_response = $this->get(array(
			'Account' => array('id' => $realmId),
			'PhoneNumbers' => array()
		));
		foreach($check_response['numbers'] as $number => $data) {
			if($number != 'id') {
				$current_nums[$number] = $realmId;
			}
		}
		$children = $this->get_children($realmId);
		foreach($children as $child) {
			$current_nums = array_merge($current_nums, $this->get_realm_numbers($child['id']));
		}

		return $current_nums;
	}
	
	function get_phone_number($e164, $assignedAccountId) {
	    return $this->get(array(
	        'Account' => array('id' => $assignedAccountId),
	        'PhoneNumber' => array('id' => $e164)
	    ));
	}
	
	function delete_phone_number($e164, $assignedAccountId) {
	    return $this->del(array(
	        'Account' => array('id' => $assignedAccountId),
	        'PhoneNumber' => array('id' => $e164)
	    ));
	}

	function get_resources($accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Resources' => array()
		));
	}

	function get_available_subscriptions($accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Events' => array(),
			'available' => array()
		));
	}

	function get_subs($accountId = null) {
		return $this->get_available_subscriptions($accountId);
	}

	function get_temporal_rules($accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'TemporalRules' => array()
		));
	}

	function get_temporal_rule_by_name($name, $accountId = null) {
		$response = $this->get_temporal_rules($accountId);
		foreach($response as $temporalRule) {
			if($temporalRule['name'] == $name) {
				return $temporalRule;
			}
		}
		return $this->notFoundException();
	}

	function get_temporal_rule($temporalRuleId, $accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'TemporalRule' => array('id' => $temporalRuleId)
		));
	}

	function put_temporal_rules($data, $accountId = null) {
		return $this->put(array(
			'Account' => array('id' => $accountId),
			'TemporalRule' => array()
		), $data);
	}

	function post_temporal_rules($data, $temporalRuleId, $accountId = null) {
		return $this->post(array(
			'Account' => array('id' => $accountId),
			'TemporalRule' => array('id' => $temporalRuleId)
		), $data);
	}

	function del_temporal_rules($temporalRuleId, $accountId = null) {
		return $this->del(array(
			'Account' => array('id' => $accountId),
			'TemporalRule' => array('id' => $temporalRuleId)
		));
	}

	function get_webhooks($accountId = null) {
		return $this->get(array(
			'Account' => array('id' => $accountId),
			'Webhooks' => array()
		));
	}

	function put_webhook($name, $url, $bindEvent = 'authz', $retries = 2, $accountId = null) {
		return $this->put(array(
			'Account' => array('id' => $accountId),
			'Webhook' => array()
		), array(
			'name' => $name,
			'bind_event' => $bindEvent,
			'uri' => $uri,
			'http_verb' => "post",
			'retries' => $retries,
			'hook' => 'all'
		));
	}

	function del_webhook($hookId, $accountId = null) {
		return $this->del(array(
			'Account' => array('id' => $accountId),
			'Webhook' => array('id' => $hookId)
		));
	}

	/**
	 * The _call method allows for dynamically performing crossbar requests
	 * without writing a template for each one
	 **/
	// TODO: finish implementation
	public function __call($name, $args) {

		// Look for a request method
		$selMethod = null;
		$methods = array('get', 'put', 'post', 'delete');
		foreach($methods as $method) {
			if(strpos($name, $method) === 0) {
				$selMethod = $method;
				break;
			}
		}

		// No method, don't continue
		if(!isset($selMethod)) {
			return;
		}

		// Next what are they looking for?
		$name = substr($name, strpos($name, $method)+1, strlen($name));
	}

	// TODO: make this work using the new get method
	function get_object_x($types, $filters = array(), $accountId = null) {
		/*$request = array();

		// First add the account prefix
		$request['Account']

		$i = 0;
		foreach($types as $type => $id) {
			// Remove trailing
			$request[ucwords($type)]['id'] = $id;
			if($i == count($types)-1) {
				$request[ucwords($type)]['filters'] = $filters;
			}
		}

		return $this->get(array(*/



		if( $account_id == null ) $account_id = $this->use_account_id;
		$filter = '';

		if( count($types) ) foreach( $types as $t => $id ) $filter .= "$t/$id/";

		if( count($filters) ) {
			foreach( $filters as $key => $val ) $filter .= "filter_$key=$val&";
			if( strlen($filter) ) $filter = '?'.substr($filter,0,-1);
		}
		$response = $this->sdk->get("http://{$this->host}:{$this->port}/v1/accounts/{$account_id}/$filter");

		return $response->getData();
	}

	// TODO: make this work using the new get method
	function get_object( $type, $id = null, $filters = array(), $account_id = null ) {

		if( $account_id == null ) $account_id = $this->use_account_id;

		$filter = '';

		if( count($filters) ) {
			foreach( $filters as $key => $val ) $filter .= "filter_$key=$val&";
			if( strlen($filter) ) $filter = '?'.substr($filter,0,-1);
		} else if( strlen($id) ) {
			$filter = "/$id";
		}

		$response = $this->sdk->get("http://{$this->host}:{$this->port}/v1/accounts/{$account_id}/$type$filter");

		return $response->getData();
	}

	/**
	 *
	 * Start of net methods
	 *
	 *
	 *
	 **/

	public function get($request) {
		try {
			$call = $this->sdk;
			foreach($request as $classKey => $requestPart) {
				if(in_array($classKey, array('Account', 'Accounts')) &&
						!isset($requestPart['id'])) {
					$requestPart['id'] = $this->use_account_id;
				}
			
				$param = null;
				if(isset($requestPart['id'])) {
					$param = $requestPart['id'];
				}
				else if(isset($requestPart['filters'])) {
					$param = $this->formatFilters($requestPart['filters']);
				}

				// Args parameter overrides all
				if(isset($requestPart['args'])) {
					$call = call_user_func_array(array($call, $classKey), $requestPart['args']);
					continue;
				}

				if($param == null) {
					$call = $call->$classKey();
				}
				else {
					$call = $call->$classKey($param);
				}
			}

			if($call instanceof Kazoo\Api\AbstractResource) {
				$mstart = microtime(true);
				$res = $call->toArray();
				$req = $call->getResponse()->getRequest();
				$mend = microtime(true);
				$this->log("<red>{$req->getMethod()} {$req->getUrl()} <yellow>µT:".($mend - $mstart)."<reset>");
				return $res;
			}
			else {
				return $call;
			}
		}
		catch(Kazoo\AuthToken\Exception\Unauthenticated $e) {
			if(isset($this->failureHandler) && $this->failureHandler instanceof FailureHandler &&
				$this->failureHandler->handleUnauthenticated($this)) {
				// The failure handler only gets one attempt to fix authentication problems
				$this->failureHandler = null;
				return $this->get($request);
			}
			return $this->formatException($e);
		}
		catch(Kazoo\AuthToken\Exception\Unauthorized $e) {
			return $this->formatException($e);
		}
		catch(Kazoo\HttpClient\Exception\NotFound $e) {
			return $this->formatException($e);
		}
		catch(Kazoo\Api\Exception\Validation $e) {
			return $this->formatException($e);
		}
	}

	public function put($request, $data) {
		try {
			$call = $this->sdk;
			foreach($request as $classKey => $requestPart) {
				if(in_array($classKey, array('Account', 'Accounts')) &&
						!isset($requestPart['id'])) {
					$requestPart['id'] = $this->use_account_id;
				}
			
				$param = null;
				if(isset($requestPart['id'])) {
					$param = $requestPart['id'];
				}
				/*else if(isset($requestPart['filters'])) {
					$param = $this->formatFilters($requestPart['filters']);
				}*/
				
				// Args parameter overrides all
				if(isset($requestPart['args'])) {
					$call = $call->$classKey($requestPart['args']);
					continue;
				}
				
				$call = $call->$classKey($param);
			}

			if($call instanceof Kazoo\Api\Entity\AbstractEntity) {
				$call->fromArray($data);
				$mstart = microtime(true);
				$res = $call->save()->toArray();
				$mend = microtime(true);
				$req = $call->getResponse()->getRequest();
				$this->log("<red>{$req->getMethod()} {$req->getUrl()} <yellow>µT:".($mend - $mstart)."<reset>");
				//return $res;
				if(isset($res['id'])) {
				    return array(
				        'status' => 'success',
				        'data' => $res
				    );
				}
				else {
				    return array('status' => 'success');
				}
			}
			else {
				return $call;
			}
		}
		catch(Kazoo\AuthToken\Exception\Unauthenticated $e) {
			if(isset($this->failureHandler) && $this->failureHandler instanceof FailureHandler &&
				$this->failureHandler->handleUnauthenticated($this)) {
				// The failure handler only gets one attempt to fix authentication problems
				$this->failureHandler = null;
				return $this->put($request, $data);
			}
			return $this->formatException($e);
		}
		catch(Kazoo\AuthToken\Exception\Unauthorized $e) {
			return $this->formatException($e);
		}
		catch(Kazoo\HttpClient\Exception\NotFound $e) {
			return $this->formatException($e);
		}
		catch(Kazoo\Api\Exception\Validation $e) {
			return $this->formatException($e);
		}
	}

	public function post($request, $data) {
		if(!isset($data['id'])) {
			return $this->postException($data);
		}
		return $this->put($request, $data);
	}

	public function del($request) {
		try {
			$call = $this->sdk;
			foreach($request as $classKey => $requestPart) {
				if(in_array($classKey, array('Account', 'Accounts')) &&
						!isset($requestPart['id'])) {
					$requestPart['id'] = $this->use_account_id;
				}
			
				$param = null;
				if(isset($requestPart['id'])) {
					$param = $requestPart['id'];
				}
				/*else if(isset($requestPart['filters'])) {
					$param = $this->formatFilters($requestPart['filters']);
				}*/
				$call = $call->$classKey($param);
			}

			if($call instanceof Kazoo\Api\Entity\AbstractEntity) {
				$mstart = microtime(true);
				$res = $call->remove()->toArray();
				$mend = microtime(true);
				$req = $call->getResponse()->getRequest();
				$this->log("<red>{$req->getMethod()} {$req->getUrl()} <yellow>µT:".($mend - $mstart)."<reset>");
				//return $res;
				return array('status' => 'success');
			}
			/*else {
				return $call->save();
			}*/
		}
		catch(Kazoo\AuthToken\Exception\Unauthenticated $e) {
			if(isset($this->failureHandler) && $this->failureHandler instanceof FailureHandler &&
				$this->failureHandler->handleUnauthenticated($this)) {
				// The failure handler only gets one attempt to fix authentication problems
				$this->failureHandler = null;
				return $this->del($request);
			}
			return $this->formatException($e);
		}
		catch(Kazoo\AuthToken\Exception\Unauthorized $e) {
			return $this->formatException($e);
		}
		catch(Kazoo\HttpClient\Exception\NotFound $e) {
			return $this->formatException($e);
		}
		catch(Kazoo\Api\Exception\Validation $e) {
			return $this->formatException($e);
		}
	}

	/**
	 *
	 * Start of exception methods
	 *
	 *
	 *
	 **/

	private function formatException($e) {
		$resp = $e->getResponse();
		$req = $resp->getRequest();

		$contentType = $req->getHeader("content-type")->__toString();
		$header1 = 'HTTP/1.1 ' . $resp->getResponse()->getStatusCode() . ' ' . $resp->getResponse()->getReasonPhrase();
		$headers = $req->getRawHeaders();
		$headers = explode("\r\n", $headers);
		$requestId = $resp->getResponse()->getHeader("x-request-id")->__toString();

		$respBody = $resp->getBody();
		$contentLength = strlen($respBody);

		if($req instanceof EntityEnclosingRequestInterface) {
			$reqBody = $request->getBody();
			$contentLength = $reqBody->getContentLength();
		}
		//$mend $mstart

		$this->log("<purple>>>>>: {$req->getMethod()} {$req->getUrl()} HTTP/1.0 ({$contentType}) len:".$contentLength."<reset>");
		if(isset($reqBody) && $contentType == 'application/json') {
			$this->log("<purple>>>>>: ".((string)$reqBody)."<reset>");
		}
		$this->log("<yellow><<<<: ".trim($header1)." µT="./*($mend - $mstart).*/" request_id:{$requestId}<reset>");
		foreach($headers as $header) {
			$this->log("<yellow><<<<: ".$header."<reset>");
		}
		$this->log("<yellow><<<<: ".$respBody."<reset>\n");
		return array('status' => 'failure', 'error' => $e->getStatusCode(), 'errors' => json_decode(json_encode($e->getData()), true),
			'message' => $e->getMessage());
	}

	private function postException($data) {
		return array('status' => 'failure', 'error' => 400, 'errors' => array('id' => 'Field is required but missing'),
			'message' => 'invalid data');
	}

	private function delNoIdException() {
		return array('status' => 'failure', 'error' => 400, 'errors' => array('id' => 'Field is required but missing'),
			'message' => 'invalid data');
	}

	private function notFoundException() {
		return array('status' => 'failure', 'error' => 404, 'errors' => array(),
			'message' => 'not found');
	}

	/**
	 * Prefixes list filters with filter_ for the new SDK
	 **/
	private function formatFilters($filters) {
		foreach($filters as $key => $value) {
			$filters['filter_'.$key] = $filters[$key];
			unset($filters[$key]);
		}
		return $filters;
	}

	/**
	 *
	 * Start of deprecated methods
	 *
	 *
	 *
	 **/

	/**
	 * Deprecated, please call put_webhook instead
	 *
	 */
	function create_webhook($name, $url, $bindEvent = 'authz', $retries = 2, $accountId = null) {
		$this->log(__FILE__." ".__FUNCTION__.":".__LINE__." is deprecated, see comment");
		$this->put_webhook($name, $url, $bindEvent, $retries, $accountId);
	}

	/**
	 * Deprecated, please call del_webhook instead
	 *
	 */
	function delete_webhook($hookId, $accountId = null) {
		$this->log(__FILE__." ".__FUNCTION__.":".__LINE__." is deprecated, see comment");
		$this->del_webhook($hookId, $accountId);
	}

	/**
	 * Deprecated, please call get_conferences instead
	 *
	 */
	function find_conferences($accountId = null) {
		return $this->get_conferences($accountId);
    }

	/**
	 * Deprecated, does not work
	 *
	 */
	function get_device_by_username($username, $accountId = null) {
		$response = $this->sdk->Account($accountId)->Devices(array('username' => $username))->toArray();
		return $response[0];
	}

}

?>
