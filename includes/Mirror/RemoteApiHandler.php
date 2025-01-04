<?php

namespace WikiMirror\Mirror;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Interwiki\InterwikiLookup;
use Psr\Log\LoggerInterface;
use Wikimedia\Assert\Assert;

/**
 * Handles all requests made to the remote API
 */
class RemoteApiHandler {
	/** @var string[] */
	public const CONSTRUCTOR_OPTIONS = [
		'ArticlePath',
		'ScriptPath',
		'Server',
		'WikiMirrorRemote',
	];

	private ServiceOptions $options;
	private LoggerInterface $logger;
	private InterwikiLookup $interwikiLookup;
	private HttpRequestFactory $httpRequestFactory;

	public function __construct(
		ServiceOptions $options,
		LoggerInterface $logger,
		InterwikiLookup $interwikiLookup,
		HttpRequestFactory $httpRequestFactory
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
		$this->logger = $logger;
		$this->interwikiLookup = $interwikiLookup;
		$this->httpRequestFactory = $httpRequestFactory;
	}

	/**
	 * Call remote VE API and retrieve the results from it.
	 * This is not cached.
	 *
	 * @param array $params Params to pass through to remote API
	 * @return array|false API response, or false on failure
	 */
	public function callVisualEditorApi( array $params ) {
		$params['action'] = 'visualeditor';

		$result = $this->getRemoteApiResponse( $params, __METHOD__ );
		if ( $result !== false ) {
			// fix <base> tag in $result['content']
			$base = $this->options->get( 'Server' ) .
				str_replace( '$1', '', $this->options->get( 'ArticlePath' ) );

			$result['content'] = preg_replace(
				'#<base href=".*?"#',
				"<base href=\"{$base}\"",
				$result['content']
			);

			// fix load.php URLs in $result['content']
			$script = $this->options->get( 'ScriptPath' );
			$result['content'] = preg_replace(
				'#="[^"]*?/load.php#',
				"=\"{$script}/load.php",
				$result['content']
			);
		}

		return $result;
	}

	/**
	 * Fetch rendered HTML and raw wikitext from remote wiki API for a given
	 * revision ID.
	 *
	 * On success, the returned array looks like the following:
	 * @code
	 * [
	 *	"title" => "Page title",
	 *	"pageid" => 1234,
	 *	"revid" => 12345,
	 *	"text" => "Rendered HTML of page"
	 *	"langlinks" => [
	 *	  [
	 *		"lang" => "de",
	 *		"url" => "URL of remote language page",
	 *		"langname" => "German",
	 *		"autonym" => "Not sure what this is",
	 *		"title" => "Name of remote language page"
	 *	  ],
	 *	  ...
	 *	],
	 *	"categories" => [
	 *	  [
	 *		"sortkey" => "Sort key or empty string",
	 *		"hidden" => true, // omitted if category is not hidden
	 *		"category" => "Category db key (unprefixed)"
	 *	  ],
	 *	  ...
	 *	],
	 *	"modules" => [
	 *	  "ext.module1",
	 *	  ...
	 *	],
	 *	"modulescripts" => [
	 *	  "ext.module1.scripts",
	 *	  ...
	 *	],
	 *	"modulestyles" => [
	 *	  "ext.module1.styles",
	 *	  ...
	 *	],
	 *	"jsconfigvars" => [
	 *	  "var1" => "value",
	 *	  ...
	 *	],
	 *	"indicators" => [
	 *	  "Indicator name" => "Indicator HTML",
	 *	  ...
	 *	],
	 *	"wikitext" => "Wikitext",
	 *	"properties" => [
	 *	  "Property name" => "Property value",
	 *	  ...
	 *	]
	 * ]
	 * @endcode
	 *
	 * @param int $revisionId
	 * @return array|false False upon transient failure,
	 * 		array of information from remote wiki on success
	 */
	public function getParsedRevision( int $revisionId ) {
		Assert::parameter( $revisionId > 0, '$revisionId', 'must be > 0' );

		$params = [
			'action' => 'parse',
			'oldid' => $revisionId,
			'prop' => 'text|langlinks|categories|modules|jsconfigvars|indicators|wikitext|properties',
			'disablelimitreport' => true,
			'disableeditsection' => true
		];

		return $this->getRemoteApiResponse( $params, __METHOD__ );
	}

	/**
	 * Fetch page information from remote wiki API.
	 * Do not directly call this; call getCachedPage() instead.
	 *
	 * On success, the returned array looks like the following:
	 * @code
	 * [
	 *   "pageid" => 1423,
	 *   "ns" => 0,
	 *   "title" => "Main Page",
	 *   "contentmodel" => "wikitext",
	 *   "pagelanguage" => "en",
	 *   "pagelanguagehtmlcode" => "en",
	 *   "pagelanguagedir" => "ltr",
	 *   "touched" => "2020-07-23T13:18:52Z",
	 *   "lastrevid" => 5875,
	 *   "length" => 23,
	 *   "redirect" => true,
	 *   "displaytitle" => "Main Page"
	 * ]
	 * @endcode
	 *
	 * @param string $pageName
	 * @return array|null
	 */
	public function getLivePageInfo( string $pageName ) {
		// check if the remote page exists
		$params = [
			'action' => 'query',
			'prop' => 'info|revisions',
			'indexpageids' => 1,
			'inprop' => 'displaytitle',
			'rvdir' => 'older',
			'rvlimit' => 1,
			'rvprop' => 'ids|timestamp|user|userid|size|slotsize|sha1|slotsha1|contentmodel'
				. '|flags|comment|parsedcomment|content|tags|roles',
			'rvslots' => '*',
			'titles' => $pageName
		];

		$data = $this->getRemoteApiResponse( $params, __METHOD__ );
		if ( $data === false ) {
			$this->logger->debug(
				"{pageName} could not be fetched from remote mirror.",
				[
					'pageName' => $pageName,
				]
			);
			return null;
		}

		if ( isset( $data['interwiki'] ) ) {
			// cache the failure since there's no reason to query for an interwiki multiple times.
			$this->logger->debug(
				"{pageName} is an interwiki on remote mirror.",
				[
					'pageName' => $pageName,
				]
			);
			return null;
		}

		if ( $data['pageids'][0] == '-1' ) {
			// == instead of === is intentional; right now the API returns a string for the page id
			// but I'd rather not rely on that behavior. This lets the -1 be coerced to int if required.
			// This indicates the page doesn't exist on the remote, so cache that failure result.
			$this->logger->debug(
				"{pageName} doesn't exist on remote mirror.",
				[
					'pageName' => $pageName,
				]
			);
			return null;
		}

		// have an actual page id, which means the title exists on the remote
		// cache the API response so we have the data available for future calls on the same title
		$pageInfo = $data['pages'][0];

		// check if this is a redirect, and if so fetch information about the redirect
		if ( array_key_exists( 'redirect', $pageInfo ) && $pageInfo['redirect'] ) {
			$params = [
				'action' => 'query',
				'prop' => 'info',
				'titles' => $pageName,
				'redirects' => true
			];

			$data = $this->getRemoteApiResponse( $params, __METHOD__ );
			if ( !$data ) {
				$this->logger->debug(
					"Unable to fetch redirect info for {pageName}.",
					[
						'pageName' => $pageName,
					]
				);
				return null;
			}

			$pageInfo['redirect'] = $data['pages'][0];
		}

		return $pageInfo;
	}

	/**
	 * Retrieve meta information about the remote wiki.
	 *
	 * @return false|array
	 */
	public function getSiteInfo() {
		$params = [
			'action' => 'query',
			'meta' => 'siteinfo',
			'siprop' => 'general|namespaces|namespacealiases'
		];

		return $this->getRemoteApiResponse( $params, __METHOD__ );
	}

	/**
	 * Execute a remote API request
	 *
	 * @param array $params API params
	 * @param string $caller Pass __METHOD__
	 * @param bool $topLevel If false (default), the returned array only includes results instead of all metadata
	 * @return false|array
	 */
	public function getRemoteApiResponse( array $params, string $caller, bool $topLevel = false ) {
		$remoteWiki = $this->options->get( 'WikiMirrorRemote' );
		if ( $remoteWiki === null ) {
			$this->logger->warning( '$wgWikiMirrorRemote not configured.' );
			return false;
		}

		$interwiki = $this->interwikiLookup->fetch( $remoteWiki );
		if ( $interwiki === null || $interwiki === false ) {
			// invalid interwiki configuration
			$this->logger->warning( 'Invalid interwiki configuration for $wgWikiMirrorRemote.' );
			return false;
		}

		$params['format'] = 'json';
		$params['formatversion'] = 2;

		$apiUrl = $interwiki->getAPI();
		$action = $params['action'];
		$res = $this->httpRequestFactory->get( wfAppendQuery( $apiUrl, $params ), [], $caller );

		if ( $res === null ) {
			// API error
			$this->logger->warning(
				'Error with remote mirror API action={action}.',
				[ 'action' => $action ]
			);
			return false;
		}

		$data = json_decode( $res, true );
		if ( !is_array( $data ) || !array_key_exists( $action, $data ) ) {
			$this->logger->warning(
				'Unexpected response from remote mirror API action={action}.',
				[ 'action' => $action, 'response' => $res ]
			);
			return false;
		}

		return $topLevel ? $data : $data[$action];
	}
}
