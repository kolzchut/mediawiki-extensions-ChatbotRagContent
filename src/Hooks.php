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
use MediaWiki\Hook\GetDoubleUnderscoreIDsHook;
use MediaWiki\Hook\ParserAfterParseHook;

class Hooks implements
	\MediaWiki\Storage\Hook\RevisionDataUpdatesHook,
	\MediaWiki\Page\Hook\PageDeletionDataUpdatesHook,
	\MediaWiki\Hook\PageMoveCompleteHook,
	GetDoubleUnderscoreIDsHook,
	ParserAfterParseHook
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
	 * Register the EXCLUDE_FROM_RAG magic word as a behavior switch
	 * @param string[] &$ids
	 */
	public function onGetDoubleUnderscoreIDs( &$ids ) {
		$ids[] = 'exclude_from_rag';
	}

	/**
	 * Add tracking category for pages using __EXCLUDE_FROM_RAG__
	 * @inheritDoc
	 */
	public function onParserAfterParse( $parser, &$text, $stripState ) {
		// Check if the property exists and is not false
		// getProperty() returns false when property doesn't exist (not null)
		if ($parser->getOutput()->getProperty('exclude_from_rag') !== false) {
			$parser->addTrackingCategory('chatbotragcontent-tracking-category-exclude-from-rag');
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
