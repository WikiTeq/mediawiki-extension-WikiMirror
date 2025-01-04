<?php

namespace MediaWiki\Extension\WikiMirror\Tests\Integration\Mirror;

use MediaWiki\Page\PageIdentityValue;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \WikiMirror\Mirror\Mirror
 */
class MirrorTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Don't rely on the site's actual configuration
		$this->overrideConfigValue(
			'WikiMirrorNamespaces',
			[ NS_MAIN ]
		);
	}

	public function addDBDataOnce(): void {
		// Add pages:
		// [[Cancer]], [[Talk:Cancer]], [[Cancers]]
		$this->db->newInsertQueryBuilder()
			->insertInto( 'remote_page' )
			->row(
				[
					'rp_id' => 105219,
					'rp_namespace' => NS_MAIN,
					'rp_title' => 'Cancer'
				]
			)
			->row(
				[
					'rp_id' => 21009840,
					'rp_namespace' => NS_TALK,
					'rp_title' => 'Cancer'
				]
			)
			->row(
				[
					'rp_id' => 1280131,
					'rp_namespace' => NS_MAIN,
					'rp_title' => 'Cancers'
				]
			)
			->caller( __METHOD__ )
			->execute();
	}

	public function testCanMirror() {
		$mirror = $this->getServiceContainer()->getService( 'Mirror' );

		$this->assertFalse(
			$mirror->canMirror(
				PageIdentityValue::localIdentity( 0, NS_TALK, 'Example' )
			),
			'Wrong namespace, not in remote_page'
		);
		$this->assertFalse(
			$mirror->canMirror(
				PageIdentityValue::localIdentity( 0, NS_TALK, 'Cancer' )
			),
			'Wrong namespace, in remote_page'
		);
		$this->assertFalse(
			$mirror->canMirror(
				PageIdentityValue::localIdentity( 0, NS_MAIN, 'Example' )
			),
			'Right namespace but not in remote_page'
		);
		$this->assertTrue(
			$mirror->canMirror(
				PageIdentityValue::localIdentity( 0, NS_MAIN, 'Cancer' ),
				// Need to be fast to not try and make a request
				true
			),
			'Right namespace, in remote_page (fast)'
		);
	}
}
