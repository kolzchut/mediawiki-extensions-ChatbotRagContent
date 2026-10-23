<?php

namespace MediaWiki\Extension\ChatbotRagContent\Tests\Integration;

use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutput;
use MediaWikiIntegrationTestCase;

/**
 * The "excluded from RAG" tracking category must follow the
 * __EXCLUDE_FROM_RAG__ behaviour switch, and nothing else.
 *
 * @covers \MediaWiki\Extension\ChatbotRagContent\Hooks::onParserAfterParse
 * @group Database
 */
class TrackingCategoryTest extends MediaWikiIntegrationTestCase {

	private const MSG_KEY = 'chatbotragcontent-tracking-category-exclude-from-rag';

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			'LanguageCode' => 'en',
			// The hooks must not try to ping anything while parsing.
			'ChatbotRagContentPingURL' => '',
		] );
	}

	private function parse( string $wikitext ): ParserOutput {
		$services = $this->getServiceContainer();
		$title = $services->getTitleFactory()->newFromText( 'TrackingCategoryTestPage' );
		$options = ParserOptions::newFromAnon();

		return $services->getParserFactory()->create()
			->parse( $wikitext, $title, $options );
	}

	private function trackingCategoryName(): string {
		$name = wfMessage( self::MSG_KEY )->inContentLanguage()->text();

		return $this->getServiceContainer()->getTitleFactory()
			->makeTitle( NS_CATEGORY, $name )->getDBkey();
	}

	public function testPageWithoutMagicWordIsNotTracked() {
		$output = $this->parse( 'An ordinary page.' );

		$this->assertNull( $output->getPageProperty( 'exclude_from_rag' ) );
		$this->assertNotContains(
			$this->trackingCategoryName(),
			$output->getCategoryNames(),
			'A page without __EXCLUDE_FROM_RAG__ must not get the tracking category'
		);
	}

	public function testPageWithMagicWordIsTracked() {
		$output = $this->parse( "An excluded page.\n__EXCLUDE_FROM_RAG__" );

		$this->assertNotNull( $output->getPageProperty( 'exclude_from_rag' ) );
		$this->assertContains(
			$this->trackingCategoryName(),
			$output->getCategoryNames(),
			'A page with __EXCLUDE_FROM_RAG__ must get the tracking category'
		);
	}
}
