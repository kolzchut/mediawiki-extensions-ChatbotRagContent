<?php

namespace MediaWiki\Extension\ChatbotRagContent\Tests\Integration;

use MediaWiki\Extension\ChatbotRagContent\RagUpdateJob;
use MediaWiki\Http\HttpRequestFactory;
use MediaWikiIntegrationTestCase;
use MWHttpRequest;
use Status;
use Title;

/**
 * @covers \MediaWiki\Extension\ChatbotRagContent\RagUpdateJob
 * @group Database
 */
class RagUpdateJobTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->setMwGlobals( [
			'wgChatbotRagContentPingURL' => 'https://example.com/ping',
			'wgServer' => 'https://wiki.example.com',
			'wgRestPath' => '/rest.php'
		] );
	}

	public function testJobUsesRevisionDataFromParamsForDeletion() {
		$title = Title::makeTitle( NS_MAIN, 'TestPage' );
		$params = [
			'page_id' => 123,
			'revision_id' => 456,
			'revision_date' => '20231215120000'
		];

		// Mock HTTP request
		$mockRequest = $this->createMock( MWHttpRequest::class );
		$mockRequest->method( 'execute' )->willReturn( Status::newGood() );
		$mockRequest->expects( $this->once() )
			->method( 'setHeader' )
			->with( 'Content-Type', 'application/json' );

		$mockHttpFactory = $this->createMock( HttpRequestFactory::class );
		$mockHttpFactory->expects( $this->once() )
			->method( 'create' )
			->with(
				'https://example.com/ping',
				$this->callback( static function ( $options ) {
					$data = json_decode( $options['postData'], true );
					return $data['page_id'] === 123 &&
						$data['revision_id'] === 456 &&
						$data['revision_date'] === '20231215120000' &&
						isset( $data['callback_url'] );
				} )
			)
			->willReturn( $mockRequest );

		$this->setService( 'HttpRequestFactory', $mockHttpFactory );

		$job = new RagUpdateJob( $title, $params );
		$result = $job->run();

		$this->assertTrue( $result, 'Job should succeed when valid revision data is provided' );
	}

	public function testJobFailsGracefullyWithInvalidRevisionData() {
		$title = Title::makeTitle( NS_MAIN, 'DeletedPage' );

		// Simulate a deleted page scenario where we can't get revision data
		$params = [];

		// Mock the title to return invalid data (as if deleted)
		$mockTitle = $this->createMock( Title::class );
		$mockTitle->method( 'getLatestRevID' )->willReturn( 0 );
		$mockTitle->method( 'getId' )->willReturn( 0 );
		$mockTitle->method( 'getPrefixedText' )->willReturn( 'Main:DeletedPage' );

		$job = new RagUpdateJob( $mockTitle, $params );
		$result = $job->run();

		$this->assertFalse( $result, 'Job should fail when revision data is invalid' );
		$this->assertStringContainsString(
			'Unable to get valid revision data',
			$job->getLastError(),
			'Job should set proper error message'
		);
	}

	public function testJobFailsGracefullyWithZeroPageId() {
		$title = Title::makeTitle( NS_MAIN, 'TestPage' );
		$params = [
			'page_id' => 0,
			'revision_id' => 0,
			'revision_date' => false
		];

		$job = new RagUpdateJob( $title, $params );
		$result = $job->run();

		$this->assertFalse( $result, 'Job should fail when page_id is 0' );
		$this->assertStringContainsString(
			'Unable to get valid revision data',
			$job->getLastError(),
			'Job should set proper error message'
		);
	}

	public function testJobHandlesHttpFailure() {
		$title = Title::makeTitle( NS_MAIN, 'TestPage' );
		$params = [
			'page_id' => 123,
			'revision_id' => 456,
			'revision_date' => '20231215120000'
		];

		// Mock HTTP request failure
		$failureStatus = Status::newFatal( 'http-request-error' );
		$mockRequest = $this->createMock( MWHttpRequest::class );
		$mockRequest->method( 'execute' )->willReturn( $failureStatus );
		$mockRequest->method( 'getStatus' )->willReturn( 500 );

		$mockHttpFactory = $this->createMock( HttpRequestFactory::class );
		$mockHttpFactory->method( 'create' )->willReturn( $mockRequest );

		$this->setService( 'HttpRequestFactory', $mockHttpFactory );

		$job = new RagUpdateJob( $title, $params );
		$result = $job->run();

		$this->assertFalse( $result, 'Job should fail when HTTP request fails' );
		$this->assertStringContainsString(
			'HTTP request to RAG endpoint failed',
			$job->getLastError(),
			'Job should set proper error message for HTTP failures'
		);
	}

	public function testJobSucceedsWithValidData() {
		// Create a real page
		$this->insertPage( 'TestSuccessPage', 'Test content' );
		$title = Title::newFromText( 'TestSuccessPage' );

		// Mock successful HTTP request
		$mockRequest = $this->createMock( MWHttpRequest::class );
		$mockRequest->method( 'execute' )->willReturn( Status::newGood() );

		$mockHttpFactory = $this->createMock( HttpRequestFactory::class );
		$mockHttpFactory->expects( $this->once() )
			->method( 'create' )
			->with(
				'https://example.com/ping',
				$this->callback( static function ( $options ) use ( $title ) {
					$data = json_decode( $options['postData'], true );
					return $data['page_id'] === $title->getId() &&
						$data['revision_id'] === $title->getLatestRevID() &&
						!empty( $data['revision_date'] ) &&
						isset( $data['callback_url'] );
				} )
			)
			->willReturn( $mockRequest );

		$this->setService( 'HttpRequestFactory', $mockHttpFactory );

		$job = new RagUpdateJob( $title );
		$result = $job->run();

		$this->assertTrue( $result, 'Job should succeed with valid page data' );
	}

	public function testJobIncludesCallbackUrl() {
		$title = Title::makeTitle( NS_MAIN, 'TestPage' );
		$params = [
			'page_id' => 123,
			'revision_id' => 456,
			'revision_date' => '20231215120000'
		];

		// Mock HTTP request
		$mockRequest = $this->createMock( MWHttpRequest::class );
		$mockRequest->method( 'execute' )->willReturn( Status::newGood() );

		$mockHttpFactory = $this->createMock( HttpRequestFactory::class );
		$mockHttpFactory->expects( $this->once() )
			->method( 'create' )
			->with(
				'https://example.com/ping',
				$this->callback( static function ( $options ) {
					$data = json_decode( $options['postData'], true );
					return isset( $data['callback_url'] ) &&
						$data['callback_url'] === 'https://wiki.example.com/rest.php/cbragcontent/v0/page_id/';
				} )
			)
			->willReturn( $mockRequest );

		$this->setService( 'HttpRequestFactory', $mockHttpFactory );

		$job = new RagUpdateJob( $title, $params );
		$result = $job->run();

		$this->assertTrue( $result, 'Job should include callback URL' );
	}
}
