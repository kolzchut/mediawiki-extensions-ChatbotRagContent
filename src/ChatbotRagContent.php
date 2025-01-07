<?php

namespace MediaWiki\Extension\ChatbotRagContent;

use MediaWiki\MediaWikiServices;
use Title;

class ChatbotRagContent {
	/**
	 * @param Title $title
	 * @param bool $ignoreNamespaceCheck
	 * @return bool
	 */
	public static function isRelevantTitle( Title $title, bool $ignoreNamespaceCheck = false ): bool {
		if ( !$title->exists() ||
			$title->isRedirect() ||
			!self::isInWikiLanguage( $title ) ||
			!$title->isWikitextPage()
		) {
			return false;
		}

		$config = MediaWikiServices::getInstance()->getMainConfig();

		$allowlist = $config->get( 'ChatbotRagContentTitleAllowlist' );
		if ( in_array( $title->getFullText(), $allowlist ) ) {
			return true;
		}

		if ( !$ignoreNamespaceCheck ) {
			$allowedNamespaces = $config->get( 'ChatbotRagContentNamespaces' );
			if ( !in_array( $title->getNamespace(), $allowedNamespaces ) ) {
				return false;
			}
		}

		return self::isTitleAllowedArticleType( $title );
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

	/**
	 * Check if the article type is in a configured blocklist
	 *
	 * @param Title $title
	 * @return bool
	 */
	public static function isTitleAllowedArticleType( Title $title ): bool {
		if ( !\ExtensionRegistry::getInstance()->isLoaded( 'ArticleType' ) ) {
			return true;
		}

		$articleType = \MediaWiki\Extension\ArticleType\ArticleType::getArticleType( $title );
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$blocklist = $config->get( 'ChatbotRagContentArticleTypeBlocklist' );
		return !in_array( $articleType, (array)$blocklist );
	}
}
