<?php

namespace WikiMirror\Service;

use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\RedirectStore;
use ReflectionClass;
use WikiMirror\API\PageInfoResponse;
use WikiMirror\Mirror\LazyMirror;

class RedirectStoreManipulator extends RedirectStore {

	private LazyMirror $mirror;

	/**
	 * @param RedirectStore $original
	 * @param LazyMirror $mirror
	 */
	public function __construct( RedirectStore $original, LazyMirror $mirror ) {
		$this->mirror = $mirror;

		// not calling parent::__construct is very intentional here,
		// the following code makes us a clone of $original instead
		$reflection = new ReflectionClass( parent::class );
		foreach ( $reflection->getProperties() as $property ) {
			$property->setValue( $this, $property->getValue( $original ) );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getRedirectTarget( PageIdentity $page ): ?LinkTarget {
		$mirror = $this->mirror->getMirror();
		$couldBeMirrored = $mirror->canMirror( $page );
		if ( !$couldBeMirrored ) {
			return parent::getRedirectTarget( $page );
		}
		$status = $mirror->getCachedPage( $page );
		if ( !$status->isOK() ) {
			return parent::getRedirectTarget( $page );
		}

		/** @var PageInfoResponse $pageData */
		$pageData = $status->getValue();

		return $pageData->redirect;
	}
}
