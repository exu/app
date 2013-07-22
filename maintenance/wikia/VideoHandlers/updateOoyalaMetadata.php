<?php

/**
* Maintenance script to update Metadata for Ooyala
* This is one time use script
* @author Saipetch Kongkatong
*/

/**
 * Constructs a URL to get assets from Ooyala API
 * @param integer $apiPageSize
 * @param integer $nextPage
 * @return string $url
 */
function getApiAssets( $apiPageSize, $nextPage ) {
	$cond = array(
		"status = 'live'",
		"labels INCLUDES 'Age gated'",
	);

	$params = array(
		'limit' => $apiPageSize,
		'where' => implode( ' AND ', $cond ),
	);

	if ( !empty($nextPage) ) {
		$parsed = explode( "?", $nextPage );
		parse_str( array_pop($parsed), $params );
	}

	$method = 'GET';
	$reqPath = '/v2/assets';
	$url = OoyalaApiWrapper::getApi( $method, $reqPath, $params );

	return $url;
}

/**
 * Send request to Ooyala to update metadata
 * @param string $videoId
 * @param array $metadata
 * @return boolean $resp
 */
function updateMetadata( $videoId, $metadata ) {
	$method = 'PATCH';
	$reqPath = '/v2/assets/'.$videoId.'/metadata';

	$reqBody = json_encode( $metadata );

	$url = OoyalaApiWrapper::getApi( $method, $reqPath, array(), $reqBody );
	echo "\tRequest to update metadata: $url\n";

	$options = array(
		'method' => $method,
		'postData' => $reqBody,
	);

	$req = MWHttpRequest::factory( $url, $options );
	$status = $req->execute();
	if ( $status->isGood() ) {
		$meta = json_decode( $req->getContent(), true );
		$resp = true;

		echo "\tUpdated Metadata for $videoId: \n";
		foreach( explode( "\n", var_export( $meta, TRUE ) ) as $line ) {
			echo "\t\t:: $line\n";
		}
	} else {
		$resp = false;
		echo "\tERROR: problem adding metadata (".$status->getMessage().").\n";
	}

	return $resp;
}

// ----------------------------- Main ------------------------------------

ini_set( "include_path", dirname( __FILE__ )."/../../" );
ini_set( 'display_errors', 1 );

require_once( "commandLine.inc" );

if ( isset($options['help']) ) {
	die( "Usage: php maintenance.php [--help] [--age=123] [--dry-run]
	--age                          age_required value in metadata
	--dry-run                      dry run
	--help                         you are reading it right now\n\n" );
}

$dryRun = isset( $options['dry-run'] );

if ( !empty( $options['age'] ) && is_numeric( $options['age'] ) ) {
	$ageRequired = $options['age'];
} else {
	die( "Invalid age.\n" );
}

$apiPageSize = 100;
$nextPage = '';
$page = 1;
$total = 0;
$failed = 0;
$skipped = 0;

do {
	// connect to provider API
	$url = getApiAssets( $apiPageSize, $nextPage );
	echo "\nConnecting to $url...\n" ;

	$req = MWHttpRequest::factory( $url );
	$status = $req->execute();
	if ( $status->isGood() ) {
		$response = $req->getContent();
	} else {
		die ( "ERROR: problem downloading content (".$status->getMessage().").\n" );
	}

	// parse response
	$response = json_decode( $response, true );

	$videos = empty($response['items']) ? array() : $response['items'] ;
	$nextPage = empty($response['next_page']) ? '' : $response['next_page'] ;

	$total += count( $videos );

	$metadata = array( 'age_required' => $ageRequired );

	$cnt = 0;
	foreach( $videos as $video ) {
		$cnt++;
		$title = trim( $video['name'] );
		echo "[Page $page: $cnt of $total] Video: $title ({$video['embed_code']})\n";
		echo "\tMetadata for {$video['embed_code']}: \n";
		foreach( explode( "\n", var_export( $video['metadata'], TRUE ) ) as $line ) {
			echo "\t\t:: $line\n";
		}

		if ( empty( $video['metadata']['agegate']) ) {
			echo "\tSKIP: $title - agegate not found in Custom Metadata.\n";
			$skipped++;
			continue;
		}

		if ( !empty( $video['metadata']['age_required']) ) {
			echo "\tSKIP: $title - age_required is set in Custom Metadata.\n";
			$skipped++;
			continue;
		}

		if ( !$dryRun ) {
			$resp = updateMetadata( $video['embed_code'], $metadata );
			if ( !$resp ) {
				$failed++;
			}
		}
	}

	$page++;
} while( !empty( $nextPage ) );

echo "\nTotal videos: ".$total.", Success: ".( $total - $failed - $skipped ).", Failed: $failed, Skipped: $skipped\n\n";
