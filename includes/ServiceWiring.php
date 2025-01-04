<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use WikiMirror\Mirror\LazyMirror;
use WikiMirror\Mirror\Mirror;
use WikiMirror\Mirror\RemoteApiHandler;

return [
	'LazyMirror' => static function ( MediaWikiServices $services ): LazyMirror {
		return new LazyMirror( static fn () => $services->getService( 'Mirror' ) );
	},
	'Mirror' => static function ( MediaWikiServices $services ): Mirror {
		return new Mirror(
			$services->getInterwikiLookup(),
			$services->getMainWANObjectCache(),
			$services->getDBLoadBalancer(),
			$services->getRedirectLookup(),
			$services->getGlobalIdGenerator(),
			$services->getLanguageFactory(),
			$services->getTitleFormatter(),
			new ServiceOptions(
				Mirror::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			$services->getService( 'WikiMirror.RemoteApiHandler' )
		);
	},
	'WikiMirror.RemoteApiHandler' => static function ( MediaWikiServices $services ): RemoteApiHandler {
		return new RemoteApiHandler(
			new ServiceOptions(
				RemoteApiHandler::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			LoggerFactory::getInstance( 'WikiMirror' ),
			$services->getInterwikiLookup(),
			$services->getHttpRequestFactory()
		);
	},
];
