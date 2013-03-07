<?php

class ProxiedApiMain
{
	function __construct( $target ) {
		$this->target = $target;
	}
	
	function execute() {
		global $wgRequest;

		$userCookie = isset( $_COOKIE['centralauth_User'] ) ? $_COOKIE['centralauth_User'] : null;
		$sessionCookie = isset( $_COOKIE['centralauth_Session'] ) ? $_COOKIE['centralauth_Session'] : null;
		
		$baseUrl = 'http://commons.wikimedia.org/w/api.php';
		$getParams = array();
		foreach ( $_GET as $key => $val ) {
			if ( $key !== 'apitarget' ) {
				$getParams[$key] = $val;
			}
		}
		$url = $baseUrl . '?' . wfArrayToCgi( $getParams );
		
		$opts = array(
			'http' => array(
				'user_agent' => 'MediaWiki internal proxy',
			),
		);

		$posted = ($_SERVER['METHOD'] == 'POST');
		if ( $posted ) {
			$opts['http']['method'] = 'POST';
			$opts['http']['content'] = file_get_contents( 'php://stdin' );
		}
		
		// Quick hack
		$type = $wgRequest->getVal( 'format' );
		if ( $type == 'json' ) {
			$contentType = 'application/json; charset=utf-8';
		} else if ( $type == 'xml' ) {
			$contentType = 'text/xml; charset=utf-8';
		} else {
			$contentType = 'text/html; charset=utf-8';
		}
		header( "Content-type: $contentType" );

		// Do the proxy
		$context = stream_context_create( $opts );
		$output = file_get_contents( $url, false, $context );
		if ( $output === false ) {
			// output some sort of error
			echo "ERROR";
		} else {
			print $output;
		}
	}
	
	function errorOut( $msg ) {
		die( $msg );
	}
	
	function getModule() {
		return new ProxiedApiModule();
	}
}

class ProxiedApiModule {
	function mustBePosted() {
		return false;
	}
}

$wgHooks['BeforeCreateApiMain'][] = function( &$processor ) {
	global $wgRequest;
	$target = $wgRequest->getVal( 'apitarget' );
	if ( $target != '' ) {
		$processor = new ProxiedApiMain( $target );
		return false;
	} else {
		// Regular API request
		return true;
	}
};

