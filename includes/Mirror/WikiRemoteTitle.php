<?php

namespace WikiMirror\Mirror;

use ReflectionClass;
use Title;

class WikiRemoteTitle extends Title {

	/**
	 * @param Title $original
	 */
	public function __construct( Title $original ) {
		// not calling parent::__construct is very intentional here,
		// the following code makes us a clone of $original instead
		$reflection = new ReflectionClass( parent::class );
		foreach ( $reflection->getProperties() as $property ) {
			$property->setValue( $this, $property->getValue( $original ) );
		}
	}

}
