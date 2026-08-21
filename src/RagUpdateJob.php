<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace MediaWiki\Extension\ChatbotRagContent;

use Job;
use MediaWiki\Config\Config;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Job to notify a remote server about page updates
 *
 * The RAG backend fails a small but steady fraction of notifications with a
 * transient 5xx. A notification that is not delivered is not merely delayed:
 * nothing else ever re-sends it, so the page's RAG content stays stale until
 * somebody happens to edit it again. This job therefore owns its own bounded
 * retry rather than delegating failure to the job queue, which cannot tell a
 * transient 500 from a permanent 404 and — with `daemonized` JobQueueRedis —
 * cannot be relied upon to re-run a failed job at all.
 *
 * @ingroup JobQueue
 */
class RagUpdateJob extends Job {

	/**
	 * Job parameter holding the 1-based queued-attempt (cohort) number.
	 *
	 * Its presence also keeps a retry from being de-duplicated against the
	 * original job: $removeDuplicates hashes the whole params array.
	 */
	public const ATTEMPT_PARAM = 'ragPingAttempt';

	/**
	 * 4xx statuses that are worth retrying anyway: the server is telling us
	 * "not now", not "never".
	 */
	private const RETRIABLE_CLIENT_ERRORS = [ 408, 425, 429 ];

	/** @inheritDoc */
	public function __construct( Title $title, array $params = [] ) {
		parent::__construct( 'ragUpdate', $title, (array)$params );

		$this->removeDuplicates = true;
	}

	/** @inheritDoc
	 * @throws \MWException
	 */
	public function run(): bool {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$url = $config->get( 'ChatbotRagContentPingURL' );
		$logger = LoggerFactory::getInstance( 'ChatbotRagContent' );

		// Use revision data from params if provided (e.g., for deletions)
		// Otherwise fetch from the current title (for normal updates)
		if ( isset( $this->params['revision_id'] ) && isset( $this->params['revision_date'] ) ) {
			$revId = $this->params['revision_id'];
			$revTimestamp = $this->params['revision_date'];
			$pageId = $this->params['page_id'];
		} else {
			$title = $this->getTitle();
			// canExist() is false for link-target-only Titles (e.g. special pages, or
			// makeTitle()'d shells). Skip the DB lookups in that case — the validation
			// below will catch the zero values and bail with a proper error.
			$revId = $title->canExist() ? $title->getLatestRevID() : 0;
			$revTimestamp = $revId ? $services->getRevisionLookup()->getTimestampFromId( $revId ) : false;
			$pageId = $title->canExist() ? $title->getId() : 0;
		}

		// Validate that we have valid revision data before sending
		if ( !$revId || !$revTimestamp || !$pageId ) {
			$this->setLastError( 'Unable to get valid revision data' );
			$logger->error( 'Unable to get valid revision data for page', [
				'page_title' => $this->getTitle()->getPrefixedText(),
				'page_id' => $pageId,
				'revision_id' => $revId,
				'revision_date' => $revTimestamp
			] );
			return false;
		}

		// Build data to append to request
		$data = [
			'page_id' => $pageId,
			'revision_id' => $revId,
			'revision_date' => $revTimestamp,
			'callback_url' => $this->getRestApiUrl( $config ),
		];

		$attempt = $this->getAttempt();
		$httpStatus = $this->post( $services, $config, $url, $data, $logger, $attempt );

		if ( $httpStatus === null ) {
			$logger->info( 'Pingback to RAG endpoint successful', [
				'page_title' => $this->getTitle()->getPrefixedText(),
				'attempt' => $attempt,
				'data' => $data
			] );

			return true;
		}

		return $this->handleFailure( $services, $config, $logger, $url, $data, $attempt, $httpStatus );
	}

	/**
	 * POST the notification, retrying at once on a retriable status.
	 *
	 * The in-run retries are what make this fix work on the currently deployed
	 * stack: they need nothing from the job queue, so they cannot be lost by it.
	 *
	 * @param MediaWikiServices $services
	 * @param Config $config
	 * @param string $url
	 * @param array $data
	 * @param LoggerInterface $logger
	 * @param int $attempt Queued-attempt (cohort) number, for logging
	 * @return int|null Null on success, otherwise the HTTP status of the last try
	 *   (0 when the transport failed before any status was received)
	 */
	private function post(
		MediaWikiServices $services,
		Config $config,
		string $url,
		array $data,
		LoggerInterface $logger,
		int $attempt
	): ?int {
		$immediateRetries = (int)$config->get( 'ChatbotRagContentPingImmediateRetries' );
		$immediateDelay = (int)$config->get( 'ChatbotRagContentPingImmediateRetryDelay' );
		$httpStatus = 0;

		for ( $try = 0; $try <= $immediateRetries; $try++ ) {
			if ( $try > 0 && $immediateDelay > 0 ) {
				sleep( $immediateDelay );
			}

			$request = $services->getHttpRequestFactory()
				->create( $url, [
					'method' => 'POST',
					'postData' => json_encode( $data ),
				] );
			$request->setHeader( 'Content-Type', 'application/json' );
			$status = $request->execute();

			if ( $status->isOK() ) {
				return null;
			}

			$httpStatus = $this->normaliseStatus( (int)$request->getStatus() );
			$this->setLastError(
				'HTTP request to RAG endpoint failed: ' . $status->getMessage()->text()
			);
			$logger->error( 'Pingback to RAG endpoint failed', [
				'page_title' => $this->getTitle()->getPrefixedText(),
				'url' => $url,
				'status' => $httpStatus,
				'attempt' => $attempt,
				'immediate_try' => $try + 1,
				'data' => $data
			] );

			if ( !$this->isRetriable( $httpStatus ) ) {
				break;
			}
		}

		return $httpStatus;
	}

	/**
	 * Decide what to do with a delivery that failed every in-run try.
	 *
	 * @param MediaWikiServices $services
	 * @param Config $config
	 * @param LoggerInterface $logger
	 * @param string $url
	 * @param array $data
	 * @param int $attempt
	 * @param int $httpStatus
	 * @return bool Job return value
	 */
	private function handleFailure(
		MediaWikiServices $services,
		Config $config,
		LoggerInterface $logger,
		string $url,
		array $data,
		int $attempt,
		int $httpStatus
	): bool {
		$context = [
			'page_title' => $this->getTitle()->getPrefixedText(),
			'url' => $url,
			'status' => $httpStatus,
			'attempts' => $attempt,
			'data' => $data
		];

		if ( !$this->isRetriable( $httpStatus ) ) {
			// The backend rejected the notification itself. Re-sending an
			// identical request can only produce an identical rejection, so
			// stop and make the page visible instead of burning the queue.
			$this->setLastError( "RAG pingback rejected with HTTP $httpStatus; not retriable" );
			$logger->critical( 'RAG pingback abandoned', $context + [ 'reason' => 'non-retriable-status' ] );
			return true;
		}

		$delays = $this->getRetryDelays( $config );
		if ( $attempt > count( $delays ) ) {
			$this->setLastError(
				"RAG pingback abandoned after $attempt attempts (last HTTP $httpStatus)"
			);
			$logger->critical( 'RAG pingback abandoned', $context + [ 'reason' => 'retries-exhausted' ] );
			return true;
		}

		$delay = $delays[$attempt - 1];
		$queued = $this->queueRetry( $services, $logger, $context, $attempt, $delay );
		if ( !$queued ) {
			// Re-queuing is the only thing standing between this page and
			// permanent staleness, so its failure must not be swallowed:
			// hand the job back to the queue as failed and say so loudly.
			return false;
		}

		$logger->warning( 'RAG pingback failed; retry scheduled', $context + [
			'next_attempt' => $attempt + 1,
			'retry_in_seconds' => $delay
		] );

		return true;
	}

	/**
	 * Push the next attempt as a delayed copy of this job.
	 *
	 * @param MediaWikiServices $services
	 * @param LoggerInterface $logger
	 * @param array $context
	 * @param int $attempt
	 * @param int $delay Seconds to wait before the next attempt
	 * @return bool Whether the retry was successfully queued
	 */
	private function queueRetry(
		MediaWikiServices $services,
		LoggerInterface $logger,
		array $context,
		int $attempt,
		int $delay
	): bool {
		// Deliberately keep the ORIGINAL params: a job pushed without revision
		// data must keep re-resolving the current revision, so a retry that
		// lands after a further edit notifies about the newer content.
		$params = $this->getParams();
		$params[self::ATTEMPT_PARAM] = $attempt + 1;
		$params['jobReleaseTimestamp'] = time() + $delay;

		try {
			// A queue that cannot hold a delayed job rejects this push. That is
			// a misconfiguration rather than a runtime condition, and it is
			// reported below like any other push failure — never swallowed.
			$services->getJobQueueGroup()->push( new self( $this->getTitle(), $params ) );
		} catch ( Throwable $e ) {
			$this->setLastError( 'Could not queue RAG pingback retry: ' . $e->getMessage() );
			$logger->critical( 'RAG pingback retry could not be queued', $context + [
				'exception' => $e
			] );
			return false;
		}

		return true;
	}

	/**
	 * Seconds to wait before each queued retry. The number of entries is the
	 * retry ceiling.
	 *
	 * @param Config $config
	 * @return int[]
	 */
	private function getRetryDelays( Config $config ): array {
		$delays = $config->get( 'ChatbotRagContentPingRetryDelays' );

		return array_values( array_map( 'intval', (array)$delays ) );
	}

	/**
	 * @return int 1-based number of this queued attempt
	 */
	private function getAttempt(): int {
		return max( 1, (int)( $this->params[self::ATTEMPT_PARAM] ?? 1 ) );
	}

	/**
	 * A fatal Status carrying a 2xx/3xx code means no real response was ever
	 * parsed (MWHttpRequest starts out holding "200 Ok"). Report those as 0 so
	 * they are never mistaken for a deliberate rejection by the backend.
	 *
	 * @param int $httpStatus
	 * @return int
	 */
	private function normaliseStatus( int $httpStatus ): int {
		return $httpStatus >= 400 ? $httpStatus : 0;
	}

	/**
	 * Whether another identical request could plausibly succeed.
	 *
	 * Anything we cannot positively identify as a rejection is retriable: the
	 * cost of one extra POST is trivial next to a page silently dropping out
	 * of the RAG index.
	 *
	 * @param int $httpStatus
	 * @return bool
	 */
	private function isRetriable( int $httpStatus ): bool {
		if ( $httpStatus >= 500 || $httpStatus < 400 ) {
			return true;
		}

		return in_array( $httpStatus, self::RETRIABLE_CLIENT_ERRORS, true );
	}

	/**
	 * Compose a full URL to the REST API endpoint, so we can send it with the pingback
	 *
	 * @param Config $config
	 * @return string
	 */
	private function getRestApiUrl( Config $config ): string {
		$server = $config->get( 'Server' );
		$path = $config->get( 'RestPath' );

		return $server . $path . '/cbragcontent/v0/page_id/';
	}
}
