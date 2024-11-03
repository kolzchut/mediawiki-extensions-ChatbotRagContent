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
	\MediaWiki\Page\Hook\PageDeletionDataUpdatesHook,
	\MediaWiki\Hook\PageMoveCompleteHook
{

	/**
	 * @inheritDoc
	 */
	public function onRevisionDataUpdates( $title, $renderedRevision, &$updates ) {
		self::pushNewJob( $title );
	}

	/**
	 * @inheritDoc
	 */
	public function onPageDeletionDataUpdates( $title, $revision, &$updates ) {
		self::pushNewJob( $title );
	}

	/**
	 * @inheritDoc
	 */
	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ) {
		$oldNamespaceAllowed = ChatbotRagContent::isAllowedNamespace( $old->getNamespace() );
		$newNamespaceAllowed = ChatbotRagContent::isAllowedNamespace( $new->getNamespace() );

		if ( $oldNamespaceAllowed | $newNamespaceAllowed ) {
			// Page moved in or out of an allowed namespace
			self::pushNewJob( Title::newFromLinkTarget( $new ), true );
		}
	}

	/**
	 * @param Title $title
	 * @param bool $ignoreNamespaceCheck
	 * @return bool
	 */
	private static function pushNewJob( $title, bool $ignoreNamespaceCheck = false ): bool {
		$services = MediaWikiServices::getInstance();
		$url = $services->getMainConfig()->get( 'ChatbotRagContentPingURL' );

		if ( !$url || !ChatbotRagContent::isRelevantTitle( $title, $ignoreNamespaceCheck ) ) {
			return false;
		}

		if ( method_exists( MediaWikiServices::class, 'getJobQueueGroup' ) ) {
			// MW 1.37+
			$jobQueue = MediaWikiServices::getInstance()->getJobQueueGroup();
		} else {
			$jobQueue = JobQueueGroup::singleton();
		}

		$job = new RagUpdateJob( $title );
		$jobQueue->push( $job );

		return true;
	}
}
