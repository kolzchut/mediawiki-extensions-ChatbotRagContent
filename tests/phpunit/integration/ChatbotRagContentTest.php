<?php

namespace MediaWiki\Extension\ChatbotRagContent\Tests\Integration;

use HashConfig;
use Language;
use MediaWiki\Extension\ChatbotRagContent\ChatbotRagContent;
use MediaWikiIntegrationTestCase;
use Title;

/**
 * @covers \MediaWiki\Extension\ChatbotRagContent\ChatbotRagContent::isRelevantTitle
 */
class ChatbotRagContentTest extends MediaWikiIntegrationTestCase {

	/**
	 * @throws \Exception
	 */
	protected function setUp(): void {
		parent::setUp();

		// Set up the test configuration
		$testConfig = new HashConfig( [
			'ChatbotRagContentTitleAllowlist' => [ 'Allowed Page' ],
			'ChatbotRagContentNamespaces' => [ NS_MAIN, NS_HELP ],
			'ChatbotRagContentArticleTypeBlocklist' => [ 'blocked_type' ]
		] );

		// Set wiki language to English
		$this->setMwGlobals( [
			'wgLanguageCode' => 'en',
			'wgChatbotRagContentTitleAllowlist' => [ 'Allowed Page' ],
			'wgChatbotRagContentNamespaces' => [ NS_MAIN, NS_HELP ],
			'wgChatbotRagContentArticleTypeBlocklist' => [ 'blocked_type' ]
		] );
	}

	protected function tearDown(): void {
		parent::tearDown();
	}

	public function testIsRelevantTitleReturnsFalseForNonexistentPage() {
		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( false );

		$this->assertFalse(
			ChatbotRagContent::isRelevantTitle( $title ),
			'Non-existent pages should not be relevant'
		);
	}

	public function testIsRelevantTitleReturnsFalseForRedirect() {
		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( true );
		$title->method( 'isRedirect' )->willReturn( true );

		$this->assertFalse(
			ChatbotRagContent::isRelevantTitle( $title ),
			'Redirect pages should not be relevant'
		);
	}

	public function testIsRelevantTitleReturnsFalseForDifferentLanguage() {
		$frLanguage = Language::factory( 'fr' );

		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( true );
		$title->method( 'isRedirect' )->willReturn( false );
		$title->method( 'getPageLanguage' )->willReturn( $frLanguage );

		$this->assertFalse(
			ChatbotRagContent::isRelevantTitle( $title ),
			'Pages in different languages should not be relevant'
		);
	}

	public function testIsRelevantTitleReturnsFalseForNonWikitextPage() {
		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( true );
		$title->method( 'isRedirect' )->willReturn( false );
		$title->method( 'getPageLanguage' )
			->willReturn( Language::factory( 'en' ) );
		$title->method( 'isWikitextPage' )->willReturn( false );

		$this->assertFalse(
			ChatbotRagContent::isRelevantTitle( $title ),
			'Non-wikitext pages should not be relevant'
		);
	}

	public function testIsRelevantTitleReturnsTrueForAllowlistedTitle() {
		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( true );
		$title->method( 'isRedirect' )->willReturn( false );
		$title->method( 'getPageLanguage' )
			->willReturn( Language::factory( 'en' ) );
		$title->method( 'isWikitextPage' )->willReturn( true );
		$title->method( 'getFullText' )->willReturn( 'Allowed Page' );

		$this->assertTrue(
			ChatbotRagContent::isRelevantTitle( $title ),
			'Allowlisted pages should be relevant'
		);
	}

	public function testIsRelevantTitleReturnsFalseForDisallowedNamespace() {
		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( true );
		$title->method( 'isRedirect' )->willReturn( false );
		$title->method( 'getPageLanguage' )
			->willReturn( Language::factory( 'en' ) );
		$title->method( 'isWikitextPage' )->willReturn( true );
		$title->method( 'getFullText' )->willReturn( 'Some Page' );
		$title->method( 'getNamespace' )->willReturn( NS_TEMPLATE );

		$this->assertFalse(
			ChatbotRagContent::isRelevantTitle( $title ),
			'Pages in disallowed namespaces should not be relevant'
		);
	}

	public function testIsRelevantTitleWithIgnoredNamespaceCheck() {
		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( true );
		$title->method( 'isRedirect' )->willReturn( false );
		$title->method( 'getPageLanguage' )
			->willReturn( Language::factory( 'en' ) );
		$title->method( 'isWikitextPage' )->willReturn( true );
		$title->method( 'getFullText' )->willReturn( 'Template Page' );
		$title->method( 'getNamespace' )->willReturn( NS_TEMPLATE );

		$this->assertTrue(
			ChatbotRagContent::isRelevantTitle( $title, true ),
			'Pages should be relevant when namespace check is ignored'
		);
	}
}
