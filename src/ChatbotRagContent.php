<?php

namespace MediaWiki\Extension\ChatbotRagContent;

use Language;
use MediaWiki\Config\Config;
use MediaWiki\Title\Title;
use MediaWiki\Page\PageProps;

class ChatbotRagContent {
	public static function isRelevantTitle(
		Title $title,
		PageProps $pageProps,
		Config $config,
		Language $contentLanguage,
		bool $ignoreNamespaceCheck = false
	): bool {
		if ( !$title->exists() ||
			$title->isRedirect() ||
			!self::isInWikiLanguage( $title, $contentLanguage ) ||
			!$title->isWikitextPage()
		) {
			return false;
		}

		// Exclude if the EXCLUDE_FROM_RAG magic word is set (via page property)
		$propArray = $pageProps->getProperties( $title, 'exclude_from_rag' );
		$property = empty( $propArray ) ? null : array_values( $propArray )[0];
		if ( $property !== null ) {
			return false;
		}

		$allowlist = $config->get( 'ChatbotRagContentTitleAllowlist' );
		if ( in_array( $title->getFullText(), $allowlist, true ) ) {
			return true;
		}

		if ( !$ignoreNamespaceCheck &&
			!self::isAllowedNamespace( $title->getNamespace(), $config )
		) {
			return false;
		}

		return self::isTitleAllowedArticleType( $title, $config );
	}

	public static function isInWikiLanguage( Title $title, Language $contentLanguage ): bool {
		return ( $title->getPageLanguage()->getCode() === $contentLanguage->getCode() );
	}

	public static function isAllowedNamespace( int $namespaceId, Config $config ): bool {
		$allowedNamespaces = $config->get( 'ChatbotRagContentNamespaces' );
		return in_array( $namespaceId, $allowedNamespaces, true );
	}

	public static function isTitleAllowedArticleType( Title $title, Config $config ): bool {
		if ( !\ExtensionRegistry::getInstance()->isLoaded( 'ArticleType' ) ) {
			return true;
		}

		$articleType = \MediaWiki\Extension\ArticleType\ArticleType::getArticleType( $title );
		$blocklist = $config->get( 'ChatbotRagContentArticleTypeBlocklist' );
		return !in_array( $articleType, (array)$blocklist, true );
	}
}
