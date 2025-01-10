<?php

namespace WikiMirror\Maintenance;

use Exception;
use Maintenance;
use Wikimedia\AtEase\AtEase;
use Wikimedia\Rdbms\IMaintainableDatabase;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

class LoadMainspacePages extends Maintenance {

	private const SQL_START = <<<END
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `wikimirror_page`
--

DROP TABLE IF EXISTS `wikimirror_page`;
/*!40101 SET @saved_cs_client	 = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `wikimirror_page` (
`page_id` int(8) unsigned NOT NULL,
`page_namespace` int(11) NOT NULL,
`page_title` varbinary(255) NOT NULL,
PRIMARY KEY (`page_id`),
UNIQUE KEY `page_name_title` (`page_namespace`,`page_title`)
) ENGINE=InnoDB DEFAULT CHARSET=binary;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wikimirror_page`
--

/*!40000 ALTER TABLE `wikimirror_page` DISABLE KEYS */;
END;

	private const SQL_END = <<<END
/*!40000 ALTER TABLE `wikimirror_page` ENABLE KEYS */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
END;

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'WikiMirror' );
		$this->setBatchSize( 50000 );
		$this->addDescription(
			'Update locally-stored tracking data about which NS_MAIN pages exist on the remote wiki.'
			. ' This script is designed to be invoked twice; first with --page and --out,'
			. ' which will generate a .sql file that should'
			. ' be read in via the mysql cli. Then, this script should be invoked a second time with --finish'
			. ' after that sql file has been read in.'
		);

		$this->addOption(
			'page',
			'File path of the dump containing the list of page titles',
			false,
			true
		);

		$this->addOption(
			'out',
			'File path to write SQL output to',
			false,
			true
		);

		$this->addOption(
			'finish',
			'Perform cleanup after independently reading in SQL files',
			false,
			false
		);
	}

	/**
	 * Update remote_page with page titles from the main namespace
	 * @return bool
	 * @throws Exception
	 */
	public function execute() {
		$config = $this->getConfig();
		$db = $this->getDB( DB_PRIMARY );
		$db->setSchemaVars( [
			'wgDBTableOptions' => $config->get( 'DBTableOptions' ),
			'now' => wfTimestampNow()
		] );

		$pagePath = $this->getOption( 'page' );

		$out = $this->getOption( 'out' );
		$finish = $this->hasOption( 'finish' );
		if ( $pagePath ) {
			if ( $out === null ) {
				$this->fatalError( 'The --out option is required when specifying --page' );
			} elseif ( $finish ) {
				$this->fatalError( 'You cannot specify --finish along with --page' );
			}
		} elseif ( !$finish ) {
			$this->fatalError( 'One of --page or --finish is needed' );
		}

		if ( $pagePath ) {
			$this->outputChanneled( "Loading page data..." );
			$this->fetchFromDump( $pagePath, $out, $db );
			$this->outputChanneled( "page data loaded successfully!" );
		}

		if ( $finish ) {
			$this->outputChanneled( 'Finishing up...' );
			$db->sourceFile( __DIR__ . '/sql/updateRemotePage.sql' );
		}

		$this->outputChanneled( 'Complete!' );

		return true;
	}

	/**
	 * Fetches the list of pages from the passed-in file and creates the SQL
	 * file to create a `wikimirror_page` table where
	 *  - Page IDs are based on an auto-increment integer
	 *  - Page namespace is always 0
	 *  - Page title is from the file
	 * 
	 * The dump can be gzipped, and will be extracted before being loaded.
	 * The data is loaded to an output file to execute via the mysql cli, as
	 * MediaWiki's facilities to execute SQL files choke on large files.
	 *
	 * @param string $path File (possibly gzipped) containing the page titles
	 * @param string $out File to write the resultant SQL to
	 * @param IMaintainableDatabase $db
	 * @throws Exception on error
	 */
	private function fetchFromDump(
		string $path,
		string $out,
		IMaintainableDatabase $db
	) {
		// these definitions don't do anything but exist to make phan happy
		$gfh = null;
		$tfh = null;

		$tableName = $db->tableName( 'wikimirror_page' );

		$batchSize = $this->getBatchSize();
		if ( $batchSize === null ) {
			$this->fatalError( 'Batch must not be null' );
		}

		try {
			// extract gzipped file to a temporary file
			$gfh = gzopen( $path, 'rb' );
			$tfh = fopen( $out, 'wb' );
			if ( $gfh === false ) {
				$this->fatalError( 'Could not open dump file for reading' );
			}

			if ( $tfh === false ) {
				$this->fatalError( 'Could not open temp file for writing' );
			}

			fwrite( $tfh, self::SQL_START . "\n" );

			$id = 0;
			// Batching: each statement inserts $batchSize rows
			$lineStart = "INSERT INTO $tableName VALUES";
			$currentLine = $lineStart;
			while ( !gzeof( $gfh ) ) {
				$line = gzgets( $gfh );
				if ( $line === false ) {
					$this->fatalError( 'Error reading dump file' );
				}
				if ( $id === 0 && trim( $line ) === 'page_title' ) {
					continue;
				}
				$id++;
				$pageTitle = $db->addQuotes( trim( $line ) );

				if ( $currentLine !== $lineStart ) {
					// Comma before new values
					$currentLine .= ",";
				}
				$currentLine .= "($id, 0, $pageTitle)";
				if ( $id % $batchSize !== 0 ) {
					continue;
				}
				$currentLine .= ";";
				fwrite( $tfh, $currentLine . "\n" );
				$currentLine = $lineStart;
			}
			if ( $currentLine !== $lineStart ) {
				$currentLine .= ";";
				fwrite( $tfh, $currentLine . "\n" );
			}

			fwrite( $tfh, self::SQL_END . "\n" );
		} finally {
			AtEase::suppressWarnings();
			if ( $gfh !== false && $gfh !== null ) {
				gzclose( $gfh );
			}

			if ( $tfh !== false && $tfh !== null ) {
				fclose( $tfh );
			}

			AtEase::restoreWarnings();
		}
	}
}

$maintClass = LoadMainspacePages::class;
require RUN_MAINTENANCE_IF_MAIN;
