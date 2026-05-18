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

/**
 * Job to notify a remote server about page updates
 *
 * @ingroup JobQueue
 */
class RagUpdateJob extends Job {

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

		$request = $services->getHttpRequestFactory()
			->create( $url, [
				'method' => 'POST',
				'postData' => json_encode( $data ),
			] );
		$request->setHeader( 'Content-Type', 'application/json' );
		$status = $request->execute();
		if ( !$status->isOK() ) {
			$this->setLastError( 'HTTP request to RAG endpoint failed: ' . $status->getMessage()->text() );
			$logger->error( 'Pingback to RAG endpoint failed', [
				'page_title' => $this->getTitle()->getPrefixedText(),
				'url' => $url,
				'status' => $request->getStatus(),
				'data' => $data
			] );
			return false;
		}

		$logger->info( 'Pingback to RAG endpoint successful', [
			'page_title' => $this->getTitle()->getPrefixedText(),
			'data' => $data
		] );

		return true;
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
