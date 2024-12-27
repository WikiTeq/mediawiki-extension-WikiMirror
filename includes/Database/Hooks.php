<?php

/** @noinspection PhpMissingParamTypeInspection */
// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace WikiMirror\Database;

use DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class Hooks implements LoadExtensionSchemaUpdatesHook {
	/**
	 * Define extension tables.
	 *
	 * Do not use this hook with a handler that uses a "services" option in
	 * its ObjectFactory spec. It is called in a context where the global
	 * service locator is not initialised.
	 *
	 * @param DatabaseUpdater $updater DatabaseUpdater subclass
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$sqlDir = __DIR__ . '/../../schema/sql';
		$updater->addExtensionTable( 'forked_titles', $sqlDir . '/forked_titles.sql' );
		$updater->addExtensionTable( 'remote_page', $sqlDir . '/remote_page.sql' );

		$updater->dropExtensionField( 'remote_page', 'rp_updated', $sqlDir . '/patch-remote_page_drop_updated.sql' );
		$updater->dropExtensionTable( 'remote_redirect' );
	}
}
