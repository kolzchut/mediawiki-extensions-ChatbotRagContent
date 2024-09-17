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
use MediaWiki\MediaWikiServices;
use Title;

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
		$url = $services->getMainConfig()->get( 'ChatbotRagContentPingURL' );

		// Build data to append to request
		$data = [
			'page_id' => $this->getTitle()->getId(),
			'callback_uri' => $this->getRestApiUrl()
		];

		$request = MediaWikiServices::getInstance()->getHttpRequestFactory()
			->create( $url, [
				'method' => 'POST',
				'postData' => json_encode( $data )
			] );
		$request->setHeader( 'Content-Type', 'application/json' );
		$status = $request->execute();
		if ( !$status->isOK() ) {
			$this->error = 'http';
			wfDebug( "Pingback error: {$request->getStatus()}", 'ChatbotRagContent' );
			return false;
		}

		return true;
	}

	/**
	 * Compose a full URL to the REST API endpoint, so we can send it with the pingback
	 *
	 * @return string
	 */
	private function getRestApiUrl(): string {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$server = $config->get( 'Server' );
		$path = $config->get( 'RestPath' );

		return $server . $path . '/cbragcontent/v0/page_id/' . $this->getTitle()->getId();
	}
}
