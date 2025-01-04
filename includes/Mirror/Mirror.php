<?php

namespace WikiMirror\Mirror;

use InvalidArgumentException;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Interwiki\InterwikiLookup;
use MediaWiki\Languages\LanguageFactory;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\RedirectLookup;
use MediaWiki\Title\TitleFormatter;
use Status;
use Title;
use WANObjectCache;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\UUID\GlobalIdGenerator;
use WikiMirror\API\PageInfoResponse;
use WikiMirror\API\ParseResponse;
use WikiMirror\API\SiteInfoResponse;

class Mirror {
	/** @var string[] */
	public const CONSTRUCTOR_OPTIONS = [
		'TranscludeCacheExpiry',
		'WikiMirrorRemote',
		'WikiMirrorNamespaces',
	];

	/** @var string Group for process cache (600 entries stored) */
	public const PC_GROUP = 'mirror:600';

	/** @var int Version of data stored in process cache (increment to invalidate all existing cache entries) */
	public const PC_VERSION = 3;

	/** @var ServiceOptions */
	protected ServiceOptions $options;

	/** @var InterwikiLookup */
	protected InterwikiLookup $interwikiLookup;

	/** @var WANObjectCache */
	protected WANObjectCache $cache;

	/** @var RedirectLookup */
	protected RedirectLookup $redirectLookup;

	/** @var ILoadBalancer */
	protected ILoadBalancer $loadBalancer;

	/** @var GlobalIdGenerator */
	protected GlobalIdGenerator $globalIdGenerator;

	/** @var LanguageFactory */
	protected LanguageFactory $languageFactory;

	protected TitleFormatter $titleFormatter;

	/** @var array<string, string> */
	private array $titleCache = [];

	/**
	 * @var array<string, ?MirrorPageRecord>
	 */
	private array $pageRecordCache = [];

	private RemoteApiHandler $remoteApiHandler;

	/**
	 * Mirror constructor.
	 *
	 * @param InterwikiLookup $interwikiLookup
	 * @param WANObjectCache $wanObjectCache
	 * @param ILoadBalancer $loadBalancer
	 * @param RedirectLookup $redirectLookup
	 * @param GlobalIdGenerator $globalIdGenerator
	 * @param LanguageFactory $languageFactory
	 * @param TitleFormatter $titleFormatter
	 * @param ServiceOptions $options
	 * @param RemoteApiHandler $remoteApiHandler
	 */
	public function __construct(
		InterwikiLookup $interwikiLookup,
		WANObjectCache $wanObjectCache,
		ILoadBalancer $loadBalancer,
		RedirectLookup $redirectLookup,
		GlobalIdGenerator $globalIdGenerator,
		LanguageFactory $languageFactory,
		TitleFormatter $titleFormatter,
		ServiceOptions $options,
		RemoteApiHandler $remoteApiHandler
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->interwikiLookup = $interwikiLookup;
		$this->cache = $wanObjectCache;
		$this->loadBalancer = $loadBalancer;
		$this->redirectLookup = $redirectLookup;
		$this->globalIdGenerator = $globalIdGenerator;
		$this->languageFactory = $languageFactory;
		$this->titleFormatter = $titleFormatter;
		$this->options = $options;
		$this->remoteApiHandler = $remoteApiHandler;
	}

	/**
	 * Retrieve remote URL (for end user viewing) of the given title.
	 *
	 * @param string $title
	 * @return string
	 */
	public function getPageUrl( string $title ) {
		$remoteWiki = $this->options->get( 'WikiMirrorRemote' );
		$interwiki = $this->interwikiLookup->fetch( $remoteWiki );

		return $interwiki->getURL( $title );
	}

	/**
	 * Retrieve wiki id of the remote wiki for internal use.
	 *
	 * @return string
	 */
	public function getWikiId() {
		$remoteWiki = $this->options->get( 'WikiMirrorRemote' );
		$interwiki = $this->interwikiLookup->fetch( $remoteWiki );

		return $interwiki->getWikiID();
	}

	/**
	 * Retrieve cached page information, refreshing the cache as necessary.
	 * The data is cached for $wgTranscludeCacheExpiry time, although stale data
	 * may be returned if we are unable to contact the remote wiki.
	 *
	 * @param PageIdentity $page Page to fetch
	 * @return Status On success, page data from remote API as a PageInfoResponse
	 */
	public function getCachedPage( PageIdentity $page ) {
		$pageName = $this->titleFormatter->getPrefixedText( $page );
		if ( !$page->canExist() ) {
			return Status::newFatal( 'wikimirror-no-mirror', $pageName );
		}

		$id = hash( 'sha256', $pageName );
		wfDebugLog( 'WikiMirror', "Retrieving cached info for {$pageName}." );
		$value = $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'mirror', 'remote-info', self::PC_VERSION, $id ),
			$this->options->get( 'TranscludeCacheExpiry' ),
			function ( $oldValue, &$ttl, &$setOpts, $oldAsOf ) use ( $page, $pageName ) {
				wfDebugLog( 'WikiMirror', "{$pageName}: Info not found in cache." );
				return $this->getLivePage( $page );
			},
			[
				'pcTTL' => $this->cache::TTL_PROC_LONG,
				'pcGroup' => self::PC_GROUP,
				'staleTTL' => $this->cache::TTL_DAY
			]
		);

		if ( !$value ) {
			return Status::newFatal( 'wikimirror-no-mirror', $pageName );
		}

		return Status::newGood( new PageInfoResponse( $this, $value ) );
	}

	/**
	 * Retrieve cached page text, refreshing the cache as necessary.
	 * The data is cached for $wgTranscludeCacheExpiry time, although stale data
	 * may be returned if we are unable to contact the remote wiki.
	 *
	 * @param PageIdentity $page Title to fetch
	 * @return Status On success, page text from remote API as a ParseResponse
	 */
	public function getCachedText( PageIdentity $page ) {
		$pageName = $this->titleFormatter->getPrefixedText( $page );
		$id = hash( 'sha256', $pageName );
		wfDebugLog( 'WikiMirror', "Retrieving cached text for {$pageName}." );
		$value = $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'mirror', 'remote-text', self::PC_VERSION, $id ),
			$this->options->get( 'TranscludeCacheExpiry' ),
			function ( $oldValue, &$ttl, &$setOpts, $oldAsOf ) use ( $page, $pageName ) {
				wfDebugLog( 'WikiMirror', "{$pageName}: Text not found in cache." );
				return $this->getLiveText( $page );
			},
			[
				'pcTTL' => WANObjectCache::TTL_PROC_LONG,
				'pcGroup' => self::PC_GROUP,
				'staleTTL' => WANObjectCache::TTL_DAY,
				'lockTSE' => 10,
			]
		);

		$pageInfo = $this->getCachedPage( $page );

		if ( !$value || !$pageInfo->isOK() ) {
			return Status::newFatal( 'wikimirror-no-mirror', $pageName );
		}

		return Status::newGood( new ParseResponse(
			$this,
			$value,
			$pageInfo->getValue(),
			$this->globalIdGenerator,
			$this->languageFactory
		) );
	}

	/**
	 * Retrieve meta information about the remote wiki, potentially from cache.
	 *
	 * @return Status
	 */
	public function getCachedSiteInfo() {
		wfDebugLog( 'WikiMirror', "Retrieving cached site info." );
		$value = $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'mirror', 'remote-site-info' ),
			$this->options->get( 'TranscludeCacheExpiry' ),
			function ( $oldValue, &$ttl, &$setOpts, $oldAsOf ) {
				wfDebugLog( 'WikiMirror', "Site info not found in cache." );
				return $this->remoteApiHandler->getSiteInfo();
			},
			[
				'pcTTL' => $this->cache::TTL_PROC_LONG,
				'pcGroup' => self::PC_GROUP,
				'staleTTL' => $this->cache::TTL_DAY
			]
		);

		if ( !$value ) {
			return Status::newFatal( 'wikimirror-api-error' );
		}

		return Status::newGood( new SiteInfoResponse( $this, $value ) );
	}

	/**
	 * Determine whether this page is in a namespace that might be mirrored,
	 * based only on $wgWikiMirrorNamespaces - there are additional restrictions
	 * from isLegalTitleForMirroring(), this is just to support the added
	 * configuration option.
	 *
	 * @param PageIdentity|int $page the page or the namespace
	 * @return bool True if the page might be mirrored
	 */
	public function inMirroredNamespace( PageIdentity|int $page ) {
		$allowed = $this->options->get( 'WikiMirrorNamespaces' );
		if ( $allowed === [] ) {
			// No limitation
			return true;
		}
		$ns = is_int( $page ) ? $page : $page->getNamespace();
		return in_array( $ns, $allowed, true );
	}

	/**
	 * Determine whether this Title is completely ineligible for mirroring,
	 * without checking if it exists anywhere.
	 *
	 * @param PageIdentity $page
	 * @return bool True if the Title is legal for mirroring, false otherwise
	 */
	private function isLegalTitleForMirroring( PageIdentity $page ) {
		$title = Title::newFromPageIdentity( $page );
		$illegal = $title->isExternal()
			|| $title->getNamespace() < 0
			|| $title->getNamespace() === NS_MEDIAWIKI
			|| $title->getNamespace() === NS_FILE
			|| $title->getNamespace() === NS_PROJECT
			|| $title->getNamespace() === NS_PROJECT_TALK
			|| $title->isUserConfigPage();

		return !$illegal;
	}

	/**
	 * Determine whether the given title is currently forked.
	 *
	 * @param PageIdentity $page
	 * @return bool
	 */
	public function isForked( PageIdentity $page ) {
		if ( !$this->inMirroredNamespace( $page ) ) {
			return false;
		}
		// prime the title cache
		$this->canMirror( $page, true );

		$cacheKey = $this->titleFormatter->getPrefixedText( $page );
		return $this->titleCache[$cacheKey] === 'forked';
	}

	/**
	 * Retrieve a PageRecord for a mirrored page. This will still return records for forked pages,
	 * and must be paired with a call to canMirror() to determine whether the returned record
	 * should be used.
	 *
	 * @param int $namespace Namespace ID
	 * @param string $dbKey DB key, assumed to be valid
	 * @return MirrorPageRecord|null The record, or null if the page does not exist remotely
	 */
	public function getMirrorPageRecord( int $namespace, string $dbKey ): ?MirrorPageRecord {
		$cacheKey = "{$namespace}:{$dbKey}";
		if ( !$this->inMirroredNamespace( $namespace ) ) {
			throw new InvalidArgumentException(
				"Called for $cacheKey, but mirroring is not enabled for that namespace"
			);
		}

		if ( array_key_exists( $cacheKey, $this->pageRecordCache ) ) {
			return $this->pageRecordCache[$cacheKey];
		}

		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$row = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'remote_page' )
			->where( [
				'rp_namespace' => $namespace,
				'rp_title' => $dbKey
			] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $row === false ) {
			$this->pageRecordCache[$cacheKey] = null;
			return null;
		}

		$record = new MirrorPageRecord( $row, $this );
		$this->pageRecordCache[$cacheKey] = $record;
		return $record;
	}

	/**
	 * Determine whether or not the given title is eligible to be mirrored.
	 *
	 * @param PageIdentity $page
	 * @param bool $fast If true, skip expensive checks
	 * @return bool True if the title can be mirrored, false if not.
	 */
	public function canMirror( PageIdentity $page, bool $fast = false ) {
		if ( !$this->inMirroredNamespace( $page ) ) {
			return false;
		}
		$cacheKey = $this->titleFormatter->getPrefixedText( $page );

		if ( isset( $this->titleCache[$cacheKey] ) ) {
			$cachedResult = $this->titleCache[$cacheKey];
			if ( $fast || !str_starts_with( $cachedResult, 'fast_' ) ) {
				return $cachedResult === 'valid' || $cachedResult === 'fast_valid';
			}
		}

		// Force recursive calls to exit early (e.g. from Title::exists) with a false value
		// so that fallbacks to core MW logic are run instead in such situations.
		// Would be better if we could refactor to not need recursion at all, but that's more complicated
		// while we're still using Titles here.
		$this->titleCache[$cacheKey] = 'recursion_guard';

		if ( !$this->isLegalTitleForMirroring( $page ) ) {
			$this->titleCache[$cacheKey] = 'illegal_title';
			return false;
		}

		// Ignore titles from WikiPage::getTitle() with a WikiRemotePage
		if ( !( $page instanceof WikiRemoteTitle ) && $page->exists() ) {
			// page exists locally
			$this->titleCache[$cacheKey] = 'forked';
			return false;
		}

		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$result = $dbr->selectField( 'forked_titles', 'COUNT(1)', [
			'ft_namespace' => $page->getNamespace(),
			'ft_title' => $page->getDBkey()
		], __METHOD__ );

		if ( $result > 0 ) {
			// title has been forked locally despite the page not existing
			$this->titleCache[$cacheKey] = 'forked';
			return false;
		}

		// ONLY pages that are in `remote_page` are mirrored, $fast just
		// determines if we also try to check the actual page
		$record = $this->getMirrorPageRecord( $page->getNamespace(), $page->getDBkey() );
		if ( $record === null ) {
			$this->titleCache[$cacheKey] = 'not_mirrored';
			return false;
		}
		$this->titleCache[$cacheKey] = 'fast_valid';
		if ( $fast ) {
			return true;
		}
		if ( !$this->getCachedPage( $page )->isOK() ) {
			// not able to successfully fetch the mirrored page
			$this->titleCache[$cacheKey] = 'errored';
			return false;
		}

		$this->titleCache[$cacheKey] = 'valid';
		return true;
	}

	/**
	 * Mark that a Title is about to be imported, preventing it from being mirrored.
	 *
	 * @param PageIdentity $page
	 * @return void
	 */
	public function markForImport( PageIdentity $page ) {
		$cacheKey = $this->titleFormatter->getPrefixedText( $page );
		$this->titleCache[$cacheKey] = 'forked';
	}

	/**
	 * Convenience function to retrieve the redirect target of a potentially mirrored page.
	 *
	 * @param Title $title Title to retrieve redirect target for
	 * @return LinkTarget|null Redirect target, or null if this Title is not a redirect.
	 */
	public function getRedirectTarget( Title $title ) {
		if ( !$this->canMirror( $title ) ) {
			// page is local, call WikiPage::getRedirectTarget if it exists
			if ( !$title->exists() ) {
				return null;
			}

			return $this->redirectLookup->getRedirectTarget( $title );
		}

		$status = $this->getCachedPage( $title );
		if ( !$status->isOK() ) {
			return null;
		}

		/** @var PageInfoResponse $pageInfo */
		$pageInfo = $status->getValue();
		return $pageInfo->redirect;
	}

	/**
	 * Fetch rendered HTML and raw wikitext from remote wiki API.
	 * Do not directly call this; call getCachedText() instead.
	 *
	 * On success, the returned array looks like the following:
	 * @code
	 * [
	 *    "title" => "Page title",
	 *    "pageid" => 1234,
	 *    "text" => "Rendered HTML of page"
	 *    "langlinks" => [
	 *      [
	 *        "lang" => "de",
	 *        "url" => "URL of remote language page",
	 *        "langname" => "German",
	 *        "autonym" => "Not sure what this is",
	 *        "title" => "Name of remote language page"
	 *      ],
	 *      ...
	 *    ],
	 *    "categories" => [
	 *      [
	 *        "sortkey" => "Sort key or empty string",
	 *        "hidden" => true, // omitted if category is not hidden
	 *        "category" => "Category db key (unprefixed)"
	 *      ],
	 *      ...
	 *    ],
	 *    "modules" => [
	 *      "ext.module1",
	 *      ...
	 *    ],
	 *    "modulescripts" => [
	 *      "ext.module1.scripts",
	 *      ...
	 *    ],
	 *    "modulestyles" => [
	 *      "ext.module1.styles",
	 *      ...
	 *    ],
	 *    "jsconfigvars" => [
	 *      "var1" => "value",
	 *      ...
	 *    ],
	 *    "indicators" => [
	 *      "Indicator name" => "Indicator HTML",
	 *      ...
	 *    ],
	 *    "wikitext" => "Wikitext",
	 *    "properties" => [
	 *      "Property name" => "Property value",
	 *      ...
	 *    ]
	 * ]
	 * @endcode
	 *
	 * @param PageIdentity $page Title to fetch
	 * @return array|bool|null False upon transient failure,
	 * 		null if page can't be mirrored,
	 * 		array of information from remote wiki on success
	 * @see Mirror::getCachedText()
	 */
	private function getLiveText( PageIdentity $page ) {
		if ( !$this->inMirroredNamespace( $page ) ) {
			throw new InvalidArgumentException(
				"Called for $page, but mirroring is not enabled for that namespace"
			);
		}
		$status = $this->getCachedPage( $page );
		if ( !$status->isOK() ) {
			return null;
		}

		/** @var PageInfoResponse $pageInfo */
		$pageInfo = $status->getValue();

		return $this->remoteApiHandler->getParsedRevision(
			$pageInfo->lastRevisionId
		);
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
	 * @param PageIdentity $page
	 * @return array|bool|null False upon transient failure,
	 * 		null if page can't be mirrored,
	 * 		array of information from remote wiki on success
	 * @see Mirror::getCachedPage()
	 */
	private function getLivePage( PageIdentity $page ) {
		if ( !$this->inMirroredNamespace( $page ) ) {
			throw new InvalidArgumentException(
				"Called for $page, but mirroring is not enabled for that namespace"
			);
		}
		// We say that the title can be mirrored if:
		// 1. The title exists on the remote wiki
		// 2. It is not a sensitive page (MediaWiki:*, user css/js/json pages)
		// 3. It is not in a special namespace (Special, Media); remote Media
		//    pages are handled via InstantCommons instead of this extension.
		// If any of these checks fail, we do not cache any values

		$pageName = $this->titleFormatter->getPrefixedText( $page );
		if ( !$this->isLegalTitleForMirroring( $page ) ) {
			// title refers to an interwiki page or a sensitive page
			// cache a null value here so we don't need to continually carry out these checks
			wfDebugLog( 'WikiMirror', "{$pageName} is an external or sensitive page; not mirroring." );
			return null;
		}

		return $this->remoteApiHandler->getLivePageInfo( $pageName );
	}

}
