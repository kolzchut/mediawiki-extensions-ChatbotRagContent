<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @file
 */

namespace MediaWiki\Extension\ChatbotRagContent;

use JobQueueGroup;
use MediaWiki\MediaWikiServices;
use Title;

class Hooks implements
	\MediaWiki\Storage\Hook\RevisionDataUpdatesHook,
	\MediaWiki\Page\Hook\PageDeletionDataUpdatesHook
{

	/**
	 * @inheritDoc
	 */
	public function onRevisionDataUpdates( $title, $renderedRevision, &$updates ) {
		$this->pushNewJob( $title );
	}

	/**
	 * @inheritDoc
	 */
	public function onPageDeletionDataUpdates( $title, $revision, &$updates ) {
		$this->pushNewJob( $title );
	}

	/**
	 * @param Title $title
	 * @return bool
	 */
	private static function isRelevantTitle( Title $title ) {
		$services = MediaWikiServices::getInstance();
		$url = $services->getMainConfig()->get( 'ChatbotRagContentPingURL' );

		// @todo: other checks	, such as namespaces
		return $url && $title->isWikitextPage();
	}

	/**
	 * @param Title $title
	 */
	private function pushNewJob( $title ): bool {
		if ( !self::isRelevantTitle( $title ) ) {
			return false;
		}

		if ( method_exists( MediaWikiServices::class, 'getJobQueueGroup' ) ) {
			// MW 1.37+
			$jobQueue = MediaWikiServices::getInstance()->getJobQueueGroup();
		} else {
			$jobQueue = JobQueueGroup::singleton();
		}

		$job = new RagUpdateJob( $title, [ 'rev_id' => $title->getLatestRevID() ] );
		$jobQueue->push( $job );

		return true;
	}

}
