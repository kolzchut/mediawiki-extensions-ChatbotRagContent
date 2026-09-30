<?php

namespace MediaWiki\Extension\ChatbotRagContent\Tests\Integration;

use JobQueueGroup;
use MediaWiki\Extension\ChatbotRagContent\RagUpdateJob;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWikiIntegrationTestCase;
use MWHttpRequest;
use Status;
use TestLogger;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\ChatbotRagContent\RagUpdateJob
 * @group Database
 */
class RagUpdateJobTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			'ChatbotRagContentPingURL' => 'https://example.com/ping',
			'Server' => 'https://wiki.example.com',
			'RestPath' => '/rest.php',
			// Pinned so the tests never actually sleep, and so the retry
			// ceiling under test is stated here rather than inherited.
			'ChatbotRagContentPingImmediateRetries' => 1,
			'ChatbotRagContentPingImmediateRetryDelay' => 0,
			'ChatbotRagContentPingRetryDelays' => [ 300, 1800 ],
		] );
	}

	/**
	 * Build an HttpRequestFactory whose successive create() calls yield the
	 * given HTTP outcomes. An outcome is null for success, or an int status.
	 *
	 * A 3xx outcome is modelled the way MWHttpRequest really reports one:
	 * setStatus() maps 300-399 to an OK Status, and redirects are not followed.
	 *
	 * @param array $outcomes
	 * @return HttpRequestFactory
	 */
	private function mockHttpOutcomes( array $outcomes ): HttpRequestFactory {
		$requests = [];
		foreach ( $outcomes as $outcome ) {
			$isRedirect = $outcome !== null && $outcome >= 300 && $outcome < 400;
			$request = $this->createMock( MWHttpRequest::class );
			$request->method( 'execute' )->willReturn(
				$outcome === null || $isRedirect
					? Status::newGood()
					: Status::newFatal( 'http-bad-status' )
			);
			$request->method( 'getStatus' )->willReturn( $outcome === null ? 200 : $outcome );
			$request->method( 'getResponseHeader' )->willReturnCallback(
				static fn ( $header ) => $isRedirect && strtolower( $header ) === 'location'
					? 'https://example.com/moved/ping'
					: null
			);
			$requests[] = $request;
		}

		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->expects( $this->exactly( count( $requests ) ) )
			->method( 'create' )
			->willReturnOnConsecutiveCalls( ...$requests );
		$this->setService( 'HttpRequestFactory', $factory );

		return $factory;
	}

	/**
	 * Capture jobs pushed during the run, with a queue that supports delays.
	 *
	 * @return array Reference-backed list of pushed jobs
	 */
	private function &captureQueuedJobs(): array {
		$pushed = [];

		$group = $this->createMock( JobQueueGroup::class );
		$group->method( 'push' )->willReturnCallback(
			static function ( $jobs ) use ( &$pushed ) {
				foreach ( is_array( $jobs ) ? $jobs : [ $jobs ] as $job ) {
					$pushed[] = $job;
				}
			}
		);
		$this->setService( 'JobQueueGroup', $group );

		return $pushed;
	}

	private function pingParams(): array {
		return [
			'page_id' => 123,
			'revision_id' => 456,
			'revision_date' => '20231215120000',
		];
	}

	private function getTitleFactory(): TitleFactory {
		return $this->getServiceContainer()->getTitleFactory();
	}

	public function testJobUsesRevisionDataFromParamsForDeletion() {
		$title = $this->getTitleFactory()->makeTitle( NS_MAIN, 'TestPage' );
		$params = [
			'page_id' => 123,
			'revision_id' => 456,
			'revision_date' => '20231215120000',
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
		$title = $this->getTitleFactory()->makeTitle( NS_MAIN, 'DeletedPage' );

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
		$title = $this->getTitleFactory()->makeTitle( NS_MAIN, 'TestPage' );
		$params = [
			'page_id' => 0,
			'revision_id' => 0,
			'revision_date' => false,
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

	public function testRetriableFailureQueuesADelayedRetry() {
		$title = $this->getTitleFactory()->makeTitle( NS_MAIN, 'TestPage' );
		$this->mockHttpOutcomes( [ 500, 500 ] );
		$pushed =& $this->captureQueuedJobs();

		$job = new RagUpdateJob( $title, $this->pingParams() );
		$before = time();
		$result = $job->run();

		$this->assertTrue(
			$result,
			'A 500 must not leave the job for a queue that will never re-run it'
		);
		$this->assertCount( 1, $pushed, 'Exactly one retry should be queued' );

		$retry = $pushed[0];
		$this->assertInstanceOf( RagUpdateJob::class, $retry );
		$params = $retry->getParams();
		$this->assertSame(
			2,
			$params[RagUpdateJob::ATTEMPT_PARAM],
			'The retry must carry the next attempt number'
		);
		$this->assertGreaterThanOrEqual(
			$before + 270,
			$params['jobReleaseTimestamp'],
			'The first retry must be delayed by the configured backoff, less the jitter'
		);
		$this->assertNotSame(
			$job->getDeduplicationInfo(),
			$retry->getDeduplicationInfo(),
			'A retry must not be de-duplicated against the job that spawned it'
		);
	}

	public function testRetriesAreBoundedAndExhaustionIsReported() {
		$title = $this->getTitleFactory()->makeTitle( NS_MAIN, 'TestPage' );
		$this->mockHttpOutcomes( [ 500, 500 ] );
		$pushed =& $this->captureQueuedJobs();

		$logger = new TestLogger( true, null, true );
		$this->setLogger( 'ChatbotRagContent', $logger );

		// Third and final attempt: two delays are configured, so attempt 3 is
		// past the ceiling.
		$params = $this->pingParams() + [ RagUpdateJob::ATTEMPT_PARAM => 3 ];
		$job = new RagUpdateJob( $title, $params );
		$result = $job->run();

		$this->assertTrue( $result, 'An exhausted job must be acknowledged, not left claimed' );
		$this->assertSame( [], $pushed, 'No further retry may be queued past the ceiling' );

		$abandoned = array_filter(
			$logger->getBuffer(),
			static fn ( $entry ) => $entry[1] === 'RAG pingback abandoned'
		);
		$this->assertCount( 1, $abandoned, 'Exhaustion must be reported, not silently dropped' );
		$entry = array_values( $abandoned )[0];
		$this->assertSame( 'critical', $entry[0], 'A permanently stale page is a critical event' );
		$this->assertSame( 'retries-exhausted', $entry[2]['reason'] );
		$this->assertSame( 3, $entry[2]['attempts'] );
	}

	public function testNonRetriableStatusIsNotRetried() {
		$title = $this->getTitleFactory()->makeTitle( NS_MAIN, 'TestPage' );
		// A single request: a 400 must not even be retried in-run.
		$this->mockHttpOutcomes( [ 400 ] );
		$pushed =& $this->captureQueuedJobs();

		$logger = new TestLogger( true, null, true );
		$this->setLogger( 'ChatbotRagContent', $logger );

		$job = new RagUpdateJob( $title, $this->pingParams() );
		$result = $job->run();

		$this->assertTrue( $result, 'A rejected notification must be acknowledged' );
		$this->assertSame( [], $pushed, 'A 4xx must not be retried' );

		$abandoned = array_filter(
			$logger->getBuffer(),
			static fn ( $entry ) => $entry[1] === 'RAG pingback abandoned'
		);
		$this->assertCount( 1, $abandoned );
		$this->assertSame( 'non-retriable-status', array_values( $abandoned )[0][2]['reason'] );
	}

	/**
	 * @return array[]
	 */
	public static function provideRedirectStatuses(): array {
		return [
			'301' => [ 301 ],
			'302' => [ 302 ],
			'307' => [ 307 ],
			'308' => [ 308 ],
		];
	}

	/**
	 * @dataProvider provideRedirectStatuses
	 */
	public function testRedirectIsNotReportedAsASuccessfulPingback( int $redirect ) {
		$title = $this->getTitleFactory()->makeTitle( NS_MAIN, 'TestPage' );
		// Both in-run tries must happen: a redirect is not a delivery.
		$this->mockHttpOutcomes( [ $redirect, $redirect ] );
		$pushed =& $this->captureQueuedJobs();

		$logger = new TestLogger( true, null, true );
		$this->setLogger( 'ChatbotRagContent', $logger );

		$job = new RagUpdateJob( $title, $this->pingParams() );

		$this->assertTrue( $job->run() );
		$messages = array_column( $logger->getBuffer(), 1 );
		$this->assertNotContains(
			'Pingback to RAG endpoint successful',
			$messages,
			'The backend never processed a redirected notification'
		);
		$this->assertCount( 1, $pushed, 'A redirect is classified like any other failure, and retried' );

		$failed = array_values( array_filter(
			$logger->getBuffer(),
			static fn ( $entry ) => $entry[1] === 'Pingback to RAG endpoint failed'
		) );
		$this->assertCount( 2, $failed );
		$this->assertSame( $redirect, $failed[0][2]['status'], 'The real 3xx must be logged, not 0' );
		$this->assertSame( 'https://example.com/moved/ping', $failed[0][2]['location'] );
		$this->assertStringContainsString( "HTTP $redirect", $job->getLastError() );
	}

	public function testPersistentRedirectEndsInACriticalReport() {
		$title = $this->getTitleFactory()->makeTitle( NS_MAIN, 'TestPage' );
		$this->mockHttpOutcomes( [ 301, 301 ] );
		$pushed =& $this->captureQueuedJobs();

		$logger = new TestLogger( true, null, true );
		$this->setLogger( 'ChatbotRagContent', $logger );

		$params = $this->pingParams() + [ RagUpdateJob::ATTEMPT_PARAM => 3 ];
		$job = new RagUpdateJob( $title, $params );

		$this->assertTrue( $job->run() );
		$this->assertSame( [], $pushed );
		$abandoned = array_values( array_filter(
			$logger->getBuffer(),
			static fn ( $entry ) => $entry[1] === 'RAG pingback abandoned'
		) );
		$this->assertCount( 1, $abandoned, 'A redirect that never clears must surface, not stay silent' );
		$this->assertSame( 'critical', $abandoned[0][0] );
		$this->assertSame( 'retries-exhausted', $abandoned[0][2]['reason'] );
		$this->assertSame( 301, $abandoned[0][2]['status'] );
	}

	/**
	 * @return array[]
	 */
	public static function provideTransientClientErrors(): array {
		return [
			'404 during a rolling restart' => [ 404 ],
			'403 from an auth blip' => [ 403 ],
		];
	}

	/**
	 * @dataProvider provideTransientClientErrors
	 */
	public function testTransientClientErrorIsRetried( int $httpStatus ) {
		$title = $this->getTitleFactory()->makeTitle( NS_MAIN, 'TestPage' );
		// Two requests: the status must be retried in-run as well.
		$this->mockHttpOutcomes( [ $httpStatus, $httpStatus ] );
		$pushed =& $this->captureQueuedJobs();

		$logger = new TestLogger( true, null, true );
		$this->setLogger( 'ChatbotRagContent', $logger );

		$job = new RagUpdateJob( $title, $this->pingParams() );

		$this->assertTrue( $job->run() );
		$this->assertCount( 1, $pushed, "A $httpStatus must not abandon the page at once" );
		$this->assertSame( [], array_filter(
			$logger->getBuffer(),
			static fn ( $entry ) => $entry[1] === 'RAG pingback abandoned'
		) );
	}

	/**
	 * @dataProvider provideTransientClientErrors
	 */
	public function testTransientClientErrorRecoversInRun( int $httpStatus ) {
		$title = $this->getTitleFactory()->makeTitle( NS_MAIN, 'TestPage' );
		$this->mockHttpOutcomes( [ $httpStatus, null ] );
		$pushed =& $this->captureQueuedJobs();

		$job = new RagUpdateJob( $title, $this->pingParams() );

		$this->assertTrue( $job->run() );
		$this->assertSame( [], $pushed );
		$this->assertSame( '', $job->getLastError() );
	}

	/**
	 * @return array[]
	 */
	public static function provideRejectionStatuses(): array {
		return [
			'400' => [ 400 ],
			'413' => [ 413 ],
		];
	}

	/**
	 * @dataProvider provideRejectionStatuses
	 */
	public function testRejectionStatusStaysTerminal( int $httpStatus ) {
		$title = $this->getTitleFactory()->makeTitle( NS_MAIN, 'TestPage' );
		$this->mockHttpOutcomes( [ $httpStatus ] );
		$pushed =& $this->captureQueuedJobs();

		$job = new RagUpdateJob( $title, $this->pingParams() );

		$this->assertTrue( $job->run() );
		$this->assertSame( [], $pushed, 'An identical payload cannot become acceptable' );
		$this->assertStringContainsString( 'not retriable', $job->getLastError() );
	}

	public function testSucceedsOnALaterAttempt() {
		$title = $this->getTitleFactory()->makeTitle( NS_MAIN, 'TestPage' );
		// First POST 500s, the immediate retry succeeds.
		$this->mockHttpOutcomes( [ 500, null ] );
		$pushed =& $this->captureQueuedJobs();

		$job = new RagUpdateJob( $title, $this->pingParams() );
		$result = $job->run();

		$this->assertTrue( $result, 'A flaky 500 followed by a 200 is a success' );
		$this->assertSame( [], $pushed, 'A success must not queue a retry' );
	}

	public function testTransportFailureWithNoHttpStatusIsRetried() {
		$title = $this->getTitleFactory()->makeTitle( NS_MAIN, 'TestPage' );
		// GuzzleHttpRequest reports "0 Error" when it never got a response;
		// an unclassifiable failure must not be mistaken for a rejection.
		$this->mockHttpOutcomes( [ 0, 0 ] );
		$pushed =& $this->captureQueuedJobs();

		$job = new RagUpdateJob( $title, $this->pingParams() );

		$this->assertTrue( $job->run() );
		$this->assertCount( 1, $pushed, 'A connection-level failure is retriable' );
	}

	public function testFailureToQueueARetryIsReportedAsAFailedJob() {
		$title = $this->getTitleFactory()->makeTitle( NS_MAIN, 'TestPage' );
		$this->mockHttpOutcomes( [ 500, 500 ] );

		$group = $this->createMock( JobQueueGroup::class );
		$group->method( 'push' )->willThrowException( new \RuntimeException( 'redis down' ) );
		$this->setService( 'JobQueueGroup', $group );

		$job = new RagUpdateJob( $title, $this->pingParams() );

		$this->assertFalse(
			$job->run(),
			'If the retry cannot be queued the job must not claim to have succeeded'
		);
		$this->assertStringContainsString( 'Could not queue RAG pingback retry', $job->getLastError() );
	}

	public function testJobSucceedsWithValidData() {
		// Create a real page
		$this->insertPage( 'TestSuccessPage', 'Test content' );
		$title = $this->getTitleFactory()->newFromText( 'TestSuccessPage' );

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
		$title = $this->getTitleFactory()->makeTitle( NS_MAIN, 'TestPage' );
		$params = [
			'page_id' => 123,
			'revision_id' => 456,
			'revision_date' => '20231215120000',
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

	/**
	 * Build a job whose jitter is pinned to one edge of its window, so the
	 * scheduled timestamp is exactly predictable.
	 *
	 * @param Title $title
	 * @param array $params
	 * @param bool $useMax Pin to the late edge rather than the early one
	 * @return RagUpdateJob
	 */
	private function jobWithPinnedJitter( Title $title, array $params, bool $useMax ): RagUpdateJob {
		return new class( $title, $params + [ 'testJitterUseMax' => $useMax ] ) extends RagUpdateJob {
			/** @inheritDoc */
			protected function randomInt( int $min, int $max ): int {
				return $this->params['testJitterUseMax'] ? $max : $min;
			}
		};
	}

	public function testLastErrorIsClearedWhenALaterTrySucceeds() {
		$title = $this->getTitleFactory()->makeTitle( NS_MAIN, 'TestPage' );
		// First POST 500s, the immediate retry succeeds.
		$this->mockHttpOutcomes( [ 500, null ] );
		$this->captureQueuedJobs();

		$job = new RagUpdateJob( $title, $this->pingParams() );

		$this->assertTrue( $job->run(), 'A flaky 500 followed by a 200 is a success' );
		$this->assertSame(
			'',
			$job->getLastError(),
			'JobRunner reads getLastError() unconditionally, so a delivery that '
				. 'recovered must not be reported to the runner as carrying an error'
		);
	}

	public function testQueuedRetryIsJitteredSoAnOutageDoesNotSynchroniseTheLadder() {
		$title = $this->getTitleFactory()->makeTitle( NS_MAIN, 'TestPage' );
		// Freeze the clock so every difference below comes from the jitter alone.
		ConvertibleTimestamp::setFakeTime( '20240101000000' );
		$now = (int)ConvertibleTimestamp::time();

		$delays = [];
		for ( $i = 0; $i < 12; $i++ ) {
			$this->mockHttpOutcomes( [ 500, 500 ] );
			$pushed =& $this->captureQueuedJobs();

			$job = new RagUpdateJob( $title, $this->pingParams() );
			$job->run();

			$delays[] = $pushed[0]->getParams()['jobReleaseTimestamp'] - $now;
			unset( $pushed );
		}

		$this->assertGreaterThan(
			1,
			count( array_unique( $delays ) ),
			'An outage fails every ping in the same window; their retries must not '
				. 'all land on the same second'
		);
		$this->assertSame(
			[],
			array_values( array_filter(
				$delays,
				static fn ( $delay ) => $delay < 270 || $delay > 330
			) ),
			'Jitter must stay inside +/-10% of the configured 300s step'
		);
	}

	public function testRetryJitterWindowEdgesAreExact() {
		$title = $this->getTitleFactory()->makeTitle( NS_MAIN, 'TestPage' );
		ConvertibleTimestamp::setFakeTime( '20240101000000' );
		$now = (int)ConvertibleTimestamp::time();

		foreach ( [ 270 => false, 330 => true ] as $expected => $useMax ) {
			$this->mockHttpOutcomes( [ 500, 500 ] );
			$pushed =& $this->captureQueuedJobs();

			$job = $this->jobWithPinnedJitter( $title, $this->pingParams(), $useMax );
			$job->run();

			$this->assertSame(
				$now + $expected,
				$pushed[0]->getParams()['jobReleaseTimestamp'],
				'Both edges of the jitter window must be exactly reachable'
			);
			unset( $pushed );
		}
	}
}
