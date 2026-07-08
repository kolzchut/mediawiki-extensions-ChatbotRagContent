<?php
/**
 * @license GPL-2.0-or-later
 *
 * @file
 */

namespace MediaWiki\Extension\ChatbotRagContent;

use JobQueueGroup;
use Language;
use MediaWiki\Config\Config;
use MediaWiki\Hook\GetDoubleUnderscoreIDsHook;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Hook\ParserAfterParseHook;
use MediaWiki\Page\Hook\PageDeletionDataUpdatesHook;
use MediaWiki\Storage\Hook\RevisionDataUpdatesHook;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Page\PageProps;

class Hooks implements
	RevisionDataUpdatesHook,
	PageDeletionDataUpdatesHook,
	PageMoveCompleteHook,
	GetDoubleUnderscoreIDsHook,
	ParserAfterParseHook
{
	public function __construct(
		private readonly Config $config,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly TitleFactory $titleFactory,
		private readonly PageProps $pageProps,
		private readonly Language $contentLanguage
	) {
	}

	/** @inheritDoc */
	public function onRevisionDataUpdates( $title, $renderedRevision, &$updates ) {
		$this->pushNewJob( $title );
	}

	/** @inheritDoc */
	public function onPageDeletionDataUpdates( $title, $revision, &$updates ) {
		$url = $this->config->get( 'ChatbotRagContentPingURL' );

		if ( !$url ) {
			return;
		}

		// For deletions, we can only reliably check namespace and allowlist.
		// We can't check exists(), redirects, language, magic words, or article type
		// since the page is being deleted. The RAG backend will handle spurious
		// notifications gracefully: when it fetches the page and gets a 404, it will
		// either remove it from the index (if indexed) or ignore it (if not).
		$allowlist = $this->config->get( 'ChatbotRagContentTitleAllowlist' );
		$inAllowlist = in_array( $title->getFullText(), $allowlist, true );
		$inAllowedNamespace = ChatbotRagContent::isAllowedNamespace(
			$title->getNamespace(),
			$this->config
		);

		if ( !$inAllowlist && !$inAllowedNamespace ) {
			return;
		}

		// Pass the revision data since we can't fetch it after deletion
		$params = [];
		if ( $revision ) {
			$params['page_id'] = $revision->getPageId();
			$params['revision_id'] = $revision->getId();
			$params['revision_date'] = $revision->getTimestamp();
		}

		// Create job directly without going through pushNewJob to avoid exists() check.
		$this->jobQueueGroup->push( new RagUpdateJob( $title, $params ) );
	}

	/** @inheritDoc */
	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ) {
		$oldNamespaceAllowed = ChatbotRagContent::isAllowedNamespace( $old->getNamespace(), $this->config );
		$newNamespaceAllowed = ChatbotRagContent::isAllowedNamespace( $new->getNamespace(), $this->config );

		if ( $oldNamespaceAllowed || $newNamespaceAllowed ) {
			// Page moved in or out of an allowed namespace
			$this->pushNewJob( $this->titleFactory->newFromLinkTarget( $new ), true );
		}
	}

	/**
	 * Register the EXCLUDE_FROM_RAG magic word as a behavior switch
	 *
	 * @param string[] &$doubleUnderscoreIDs
	 */
	public function onGetDoubleUnderscoreIDs( &$doubleUnderscoreIDs ) {
		$doubleUnderscoreIDs[] = 'exclude_from_rag';
	}

	/** @inheritDoc */
	public function onParserAfterParse( $parser, &$text, $stripState ) {
		// Check if the property exists and is not false
		// getProperty() returns false when property doesn't exist (not null)
		if ( $parser->getOutput()->getPageProperty( 'exclude_from_rag' ) !== false ) {
			$parser->addTrackingCategory( 'chatbotragcontent-tracking-category-exclude-from-rag' );
		}
	}

	private function pushNewJob(
		Title $title,
		bool $ignoreNamespaceCheck = false,
		array $params = []
	): bool {
		$url = $this->config->get( 'ChatbotRagContentPingURL' );

		if ( !$url || !ChatbotRagContent::isRelevantTitle(
			$title,
			$this->pageProps,
			$this->config,
			$this->contentLanguage,
			$ignoreNamespaceCheck
		) ) {
			return false;
		}

		$this->jobQueueGroup->push( new RagUpdateJob( $title, $params ) );

		return true;
	}
}
