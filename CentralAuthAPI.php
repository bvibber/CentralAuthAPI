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

		if ( empty( $userCookie ) || empty( $sessionCookie ) ) {
			$this->errorOut( 'no-centralauth-cookies' );
			return;
		}
	
		$wiki = WikiMap::getWiki( $this->target );
		if ( !$wiki ) {
			$this->errorOut( 'invalid-centralauth-target' );
			return;
		}
		$baseUrl = $wiki->getCanonicalServer();

		// Wikimedia-specific hack
		$apiPath = "/w/api.php";

		// Copy over any GET parameters to the final URL
		$getParams = array();
		foreach ( $_GET as $key => $val ) {
			if ( $key !== 'apitarget' ) {
				$getParams[$key] = $val;
			}
		}
		$url = $baseUrl . $apiPath . '?' . wfArrayToCgi( $getParams );
		
		$opts = array(
			'http' => array(
				'user_agent' => 'MediaWiki internal proxy',
				'header' => "Cookie: centralauth_User=" . urlencode( $userCookie ) .
					";centralauth_Session=" . urlencode( $sessionCookie ),
			),
		);

		$posted = ($_SERVER['REQUEST_METHOD'] == 'POST');
		if ( $posted ) {
			$opts['http']['method'] = 'POST';
			if ( count( $_FILES ) ) {
				// multipart
				$opts['http']['content'] = $this->copyMultipartFormData();
				$opts['http']['header'] = array(
					'Content-Type: multipart/form-data; boundary=' . $this->separator
				);
			} else {
				// hopefully not multipart
				$opts['http']['header'] = array(
					'Content-Type: application/x-www-form-urlencoded'
				);
				$opts['http']['content'] = $_POST;
			}
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
		header( 'HTTP/1.x 400 Bad Request' );
		die( $msg );
	}
	
	function getModule() {
		return new ProxiedApiModule();
	}

	function copyMultipartFormData() {
		$data = '';
		$separator = '-----------------------------' . mt_rand();
		$this->separator = $separator;
		foreach ( $_POST as $key => $val ) {
			$data .= "--$separator\r\n";
			$data .= "Content-Disposition: form-data; name=\"$key\"\r\n";
			$data .= "\r\n";
			$data .= $val . "\r\n";
		}
		foreach ( $_FILES as $key => $file ) {
			$name = $file['name'];
			$type = $file['type'];
			$data .= "--$separator\r\n";
			$data .= "Content-Disposition: form-data; name=\"$key\"; filename=\"$name\"\r\n";
			$data .= "Content-Type: $type\r\n";
			$data .= "\r\n";
			$data .= file_get_contents( $file['tmp_name'] );
			$data .= "\r\n";
		}
		$data .= "--$separator--";
		return $data;
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
