<?php

namespace WikiMirror\Maintenance;

use Maintenance;
use MwSql;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

class ReloadEnwikiDump extends Maintenance {

	private const UPDATELOG_KEY = 'WikiMirror:ReloadEnwikiDump:Version';

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'WikiMirror' );
		$this->setBatchSize( 50000 );
		$this->addDescription(
			'Update locally-stored tracking data about which NS_MAIN pages exist on the English Wikipedia.'
		);

		$this->addOption(
			'dump',
			'Date for the dump to import from the English Wikipedia',
			true,
			true
		);
	}

	/**
	 * Update remote_page table contents if needed
	 * @return bool
	 */
	public function execute() {
		$dump = $this->getOption( 'dump' );
		$current = $this->getCurrentDumpVersion();
		if ( $dump === $current ) {
			$this->output( "Dump is already using version $dump" );
			return true;
		}
		$dumpPath = $this->downloadDump( $dump );
		// $dumpPath = "/tmp/WikiMirror-ReloadEnwikiDump-bHKsBr";
		$sqlPath = $this->runFirstLoad( $dumpPath );
		// $sqlPath = "/tmp/WikiMirror-ReloadEnwikiDump-sql-B0KyJV";
		$this->runSQLLoad( $sqlPath );
		$this->runFinish();

		unlink( $dumpPath );
		unlink( $sqlPath );

		$this->storeDumpVersion( $dump );
		return true;
	}

	/**
	 * Get the current version of the dump that is loaded based on the updatelog
	 * table, or null if not stored
	 *
	 * @return string|null
	 */
	private function getCurrentDumpVersion(): string|null {
		$query = $this->getPrimaryDB()
			->newSelectQueryBuilder()
			->select( 'ul_value' )
			->from( 'updatelog' )
			->where( [ 'ul_key' => self::UPDATELOG_KEY ] )
			->caller( __METHOD__ );
		$result = $query->fetchField();
		if ( $result === false ) {
			return null;
		}
		return $result;
	}

	/**
	 * Update the stored version of the dump that is loaded in updatelog
	 *
	 * @param string $version
	 */
	private function storeDumpVersion( string $version ) {
		$query = $this->getPrimaryDB()
			->newInsertQueryBuilder()
			->insertInto( 'updatelog' )
			->row( [
				'ul_key' => self::UPDATELOG_KEY,
				'ul_value' => $version
			] )
			->onDuplicateKeyUpdate()
			->uniqueIndexFields( [ 'ul_key' ] )
			->set( [ 'ul_value' => $version ] )
			->caller( __METHOD__ );
		$query->execute();
		$this->output( "updatelog table updated, dump is $version\n" );
	}

	/**
	 * Download the dump from
	 * `https://dumps.wikimedia.org/enwiki/{id}/enwiki-{id}-all-titles-in-ns0.gz`
	 * and put it in /tmp
	 */
	private function downloadDump( string $id ): string {
		$services = $this->getServiceContainer();
		$httpFactory = $services->getHTTPRequestFactory();
		$reqUrl = "https://dumps.wikimedia.org/enwiki/$id/enwiki-$id-all-titles-in-ns0.gz";
		$request = $httpFactory->create(
			$reqUrl,
			[
				'method' => 'GET',
				// 5 minutes should be enough? Got a timeout with 3
				'timeout' => 60 * 5,
				'maxTimeout' => 60 * 5,
			],
			__METHOD__
		);

		$outfile = tempnam( wfTempDir(), 'WikiMirror-ReloadEnwikiDump-download-' );
		$this->output( "Saving $reqUrl dump to $outfile\n" );
		$outhandle = fopen( $outfile, 'wb' );
		$callback = static function ( $curlResources, string $buffer ) use ( $outhandle ) {
			return fwrite( $outhandle, $buffer );
		};
		$request->setCallback( $callback );

		$status = $request->execute();
		if ( !$status->isGood() ) {
			$this->output( $status->__toString() );
			$this->fatalError( "Bad status" );
		}
		return $outfile;
	}

	/**
	 * Run the first round of the LoadMainspacePages maintenance script,
	 * converting the dump to an SQL file to run
	 *
	 * @param string $dumpPath
	 * @return string
	 */
	private function runFirstLoad( string $dumpPath ): string {
		$this->output( "Runing LoadMainspacePages with --page and --out\n" );
		$outfile = tempnam( wfTempDir(), 'WikiMirror-ReloadEnwikiDump-sql-' );
		$this->output( "Saving $dumpPath to SQL at $outfile\n" );

		$child = $this->createChild(
			LoadMainspacePages::class,
			__DIR__ . '/LoadMainspacePages.php'
		);
		$child->loadParamsAndArgs(
			LoadMainspacePages::class,
			[
				'page' => $dumpPath,
				'out' => $outfile
			]
		);
		$result = $child->execute();
		if ( $result !== true ) {
			$this->fatalError( 'LoadMainspacePages failure' );
		}
		return $outfile;
	}

	/**
	 * Run the sql CLI to load the `wikimirror_page` table, based on the
	 * output of the first LoadMainspacePages execution
	 *
	 * @param string $sqlPath
	 */
	private function runSQLLoad( string $sqlPath ): void {
		// phpcs:ignore MediaWiki.NamingConventions.ValidGlobalName.allowedPrefix
		global $IP;

		$this->output( "Runing SQL from $sqlPath\n" );

		$child = $this->createChild(
			MwSql::class,
			"$IP/maintenance/sql.php"
		);
		$child->loadParamsAndArgs(
			MwSql::class,
			[],
			[ $sqlPath ]
		);
		$result = $child->execute();
		if ( $result !== true && $result !== null ) {
			$this->fatalError( 'SQL failure' );
		}
	}

	/**
	 * Run the second round of the LoadMainspacePages maintenance script,
	 * moving `wikimirror_page` to replace `remote_page`
	 */
	private function runFinish() {
		$this->output( "Runing LoadMainspacePages with --finish\n" );

		$child = $this->createChild(
			LoadMainspacePages::class,
			__DIR__ . '/LoadMainspacePages.php'
		);
		$child->loadParamsAndArgs(
			LoadMainspacePages::class,
			[
				'finish' => true,
			]
		);
		$result = $child->execute();
		if ( $result !== true ) {
			$this->fatalError( 'LoadMainspacePages failure' );
		}
	}
}

$maintClass = ReloadEnwikiDump::class;
require RUN_MAINTENANCE_IF_MAIN;
