<?php

namespace MediaWiki\Extension\ChatbotRagContent\Tests\Integration;

use Language;
use MediaWiki\Config\Config;
use MediaWiki\Extension\ChatbotRagContent\ChatbotRagContent;
use MediaWiki\Page\PageProps;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\ChatbotRagContent\ChatbotRagContent::isRelevantTitle
 */
class ChatbotRagContentTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			'LanguageCode' => 'en',
			'ChatbotRagContentTitleAllowlist' => [ 'Allowed Page' ],
			'ChatbotRagContentNamespaces' => [ NS_MAIN, NS_HELP ],
			'ChatbotRagContentArticleTypeBlocklist' => [ 'blocked_type' ],
		] );
	}

	private function getTestConfig(): Config {
		return $this->getServiceContainer()->getMainConfig();
	}

	private function getTestContentLanguage(): Language {
		return $this->getServiceContainer()->getContentLanguage();
	}

	private function newPagePropsMock( array $properties = [] ): PageProps {
		$pageProps = $this->createMock( PageProps::class );
		$pageProps->method( 'getProperties' )->willReturn( $properties );

		return $pageProps;
	}

	public function testIsRelevantTitleReturnsFalseForNonexistentPage() {
		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( false );

		$this->assertFalse(
			ChatbotRagContent::isRelevantTitle(
				$title,
				$this->newPagePropsMock(),
				$this->getTestConfig(),
				$this->getTestContentLanguage()
			),
			'Non-existent pages should not be relevant'
		);
	}

	public function testIsRelevantTitleReturnsFalseForRedirect() {
		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( true );
		$title->method( 'isRedirect' )->willReturn( true );

		$this->assertFalse(
			ChatbotRagContent::isRelevantTitle(
				$title,
				$this->newPagePropsMock(),
				$this->getTestConfig(),
				$this->getTestContentLanguage()
			),
			'Redirect pages should not be relevant'
		);
	}

	public function testIsRelevantTitleReturnsFalseForDifferentLanguage() {
		$frLanguage = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'fr' );

		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( true );
		$title->method( 'isRedirect' )->willReturn( false );
		$title->method( 'getPageLanguage' )->willReturn( $frLanguage );

		$this->assertFalse(
			ChatbotRagContent::isRelevantTitle(
				$title,
				$this->newPagePropsMock(),
				$this->getTestConfig(),
				$this->getTestContentLanguage()
			),
			'Pages in different languages should not be relevant'
		);
	}

	public function testIsRelevantTitleReturnsFalseForNonWikitextPage() {
		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( true );
		$title->method( 'isRedirect' )->willReturn( false );
		$title->method( 'getPageLanguage' )
			->willReturn( $this->getTestContentLanguage() );
		$title->method( 'isWikitextPage' )->willReturn( false );

		$this->assertFalse(
			ChatbotRagContent::isRelevantTitle(
				$title,
				$this->newPagePropsMock(),
				$this->getTestConfig(),
				$this->getTestContentLanguage()
			),
			'Non-wikitext pages should not be relevant'
		);
	}

	public function testIsRelevantTitleReturnsTrueForAllowlistedTitle() {
		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( true );
		$title->method( 'isRedirect' )->willReturn( false );
		$title->method( 'getPageLanguage' )
			->willReturn( $this->getTestContentLanguage() );
		$title->method( 'isWikitextPage' )->willReturn( true );
		$title->method( 'getFullText' )->willReturn( 'Allowed Page' );

		$this->assertTrue(
			ChatbotRagContent::isRelevantTitle(
				$title,
				$this->newPagePropsMock(),
				$this->getTestConfig(),
				$this->getTestContentLanguage()
			),
			'Allowlisted pages should be relevant'
		);
	}

	public function testIsRelevantTitleReturnsFalseForDisallowedNamespace() {
		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( true );
		$title->method( 'isRedirect' )->willReturn( false );
		$title->method( 'getPageLanguage' )
			->willReturn( $this->getTestContentLanguage() );
		$title->method( 'isWikitextPage' )->willReturn( true );
		$title->method( 'getFullText' )->willReturn( 'Some Page' );
		$title->method( 'getNamespace' )->willReturn( NS_TEMPLATE );

		$this->assertFalse(
			ChatbotRagContent::isRelevantTitle(
				$title,
				$this->newPagePropsMock(),
				$this->getTestConfig(),
				$this->getTestContentLanguage()
			),
			'Pages in disallowed namespaces should not be relevant'
		);
	}

	public function testIsRelevantTitleWithIgnoredNamespaceCheck() {
		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( true );
		$title->method( 'isRedirect' )->willReturn( false );
		$title->method( 'getPageLanguage' )
			->willReturn( $this->getTestContentLanguage() );
		$title->method( 'isWikitextPage' )->willReturn( true );
		$title->method( 'getFullText' )->willReturn( 'Template Page' );
		$title->method( 'getNamespace' )->willReturn( NS_TEMPLATE );

		$this->assertTrue(
			ChatbotRagContent::isRelevantTitle(
				$title,
				$this->newPagePropsMock(),
				$this->getTestConfig(),
				$this->getTestContentLanguage(),
				true
			),
			'Pages should be relevant when namespace check is ignored'
		);
	}

	public function testIsRelevantTitleReturnsFalseIfExcludeFromRagMagicWordIsSet() {
		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( true );
		$title->method( 'isRedirect' )->willReturn( false );
		$title->method( 'getPageLanguage' )
			->willReturn( $this->getTestContentLanguage() );
		$title->method( 'isWikitextPage' )->willReturn( true );
		$title->method( 'getFullText' )->willReturn( 'Some Page' );
		$title->method( 'getNamespace' )->willReturn( NS_MAIN );

		$this->assertFalse(
			ChatbotRagContent::isRelevantTitle(
				$title,
				$this->newPagePropsMock( [ 'exclude_from_rag' => true ] ),
				$this->getTestConfig(),
				$this->getTestContentLanguage()
			),
			'Pages with __EXCLUDE_FROM_RAG__ magic word should not be relevant'
		);
	}
}
