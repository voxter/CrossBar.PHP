#!/usr/bin/php
<?php

//include('../include/CrossBar.php');

$HOME = getenv("HOME");
include("$HOME/src/work/CrossBar.PHP/CrossBar.php");
$XBOPTS = parse_ini_file("$HOME/.xbopts.conf");

$XBAR = new CrossBar($XBOPTS);


$TYPE = "GET";
$REALM = "";
$OBJECT = "";
$LIST = "";

foreach( $argv as $key => $val ) {

	if( $val == "-t" ) $TYPE = $argv[$key+1];
	if( $val == "-l" ) $LIST = "mwhhahahah";
	if( $val == "-r" ) $REALM = $argv[$key+1];
	if( $val == "-o" ) $OBJECT = $argv[$key+1];

}





if( $XBAR->is_authenticated ) {

	printf("Connected {$XBOPTS['username']}@{$XBOPTS['host']}:{$XBOPTS['port']}\n\n");

	$accounts = $XBAR->get_accounts();

	if( strlen($REALM) ) {
		$XBAR->use_account($accounts[$REALM]);
	}



	if( strlen($LIST) ) foreach( $accounts as $key => $realm ) echo " * ".$key."\n";



	if( strlen($OBJECT) ) {
		$response = $XBAR->send($TYPE,"/v1/accounts/{$XBAR->use_account_id}/$OBJECT");
		print_r($response);
	}



} else {
	printf("Connection failure {$XBOPTS['username']}@{$XBOPTS['host']}:{$XBOPTS['port']}\n");
}











?>
