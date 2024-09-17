<?php

namespace MediaWiki\Extension\ChatbotRagContent;

use MediaWiki\MediaWikiServices;
use Title;

class ChatbotRagContent {
	/**
	 * @param Title $title
	 * @return bool
	 */
	public static function isRelevantTitle( Title $title, bool $ignoreNamespaceCheck = false ): bool {
		$services = MediaWikiServices::getInstance();
		$url = $services->getMainConfig()->get( 'ChatbotRagContentPingURL' );

		// @todo: other checks	, such as namespaces
		return $url
			&& self::isInWikiLanguage( $title )
			&& $title->isWikitextPage()
			&& ( $ignoreNamespaceCheck || self::isAllowedNamespace( $title->getNamespace() ) );
	}

	/**
	 * @param Title $title
	 * @return bool
	 */
	public static function isInWikiLanguage( Title $title ): bool {
		$contentLanguage = MediaWikiServices::getInstance()->getContentLanguage();
		return ( $title->getPageLanguage()->getCode() === $contentLanguage->getCode() );
	}

	/**
	 * Check if the configured allowed namespaces include the specified namespace
	 *
	 * @param int $namespaceId Namespace ID
	 * @return bool
	 */
	public static function isAllowedNamespace( int $namespaceId ): bool {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$allowedNamespaces = $config->get( 'ChatbotRagContentNamespaces' );
		return in_array( $namespaceId, $allowedNamespaces );
	}
}
