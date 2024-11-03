<?php

namespace MediaWiki\Extension\ChatbotRagContent;

use DOMDocument;
use DOMXPath;
use MediaWiki\Extension\ArticleContentArea\ArticleContentArea;
use MediaWiki\Extension\ArticleType\ArticleType;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Revision\RevisionRenderer;
use MediaWiki\Storage\RevisionRecord;
use MWException;
use RequestContext;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Title;
use User;
use Wikimedia\Message\MessageValue;
use Wikimedia\Message\ParamType;
use Wikimedia\Message\ScalarParam;
use Wikimedia\ParamValidator\ParamValidator;
use WikiPage;

/**
 * Example class to echo a path parameter
 */
class RestApiGetContent extends SimpleHandler {

	/** @var PermissionManager */
	private $permissionManager;

	/** @var RevisionRenderer */
	private $revisionRenderer;

	/** @var User */
	private $user;

	/**
	 * @var Title|bool|null
	 */
	private $title = null;

	/**
	 * @var WikiPage|null
	 */
	private $wikiPage = null;

	/**
	 * @var RevisionRecord|null
	 */
	private $revisionRecord	= null;

	/**
	 * @var DOMDocument
	 */
	private DOMDocument $dom;

	/**
	 * @param PermissionManager $permissionManager
	 * @param RevisionRenderer $revisionRenderer
	 */
	public function __construct(
		PermissionManager $permissionManager,
		RevisionRenderer $revisionRenderer
	) {
		$this->permissionManager = $permissionManager;
		$this->revisionRenderer = $revisionRenderer;
		// @todo Inject this, when there is a good way to do that
		$this->user = RequestContext::getMain()->getUser();
	}

	/**
	 * @return Title|bool Title or false if unable to retrieve title
	 */
	private function getTitle() {
		if ( $this->title === null ) {
			$this->title = Title::newFromID( $this->getValidatedParams()['identifier'] ) ?? false;
		}
		return $this->title;
	}

	/**
	 * Get a wikipage record for this title
	 * @return \WikiCategoryPage|\WikiFilePage|WikiPage|null
	 * @throws MWException
	 */
	private function getWikiPage() {
		if ( $this->wikiPage === null ) {
			if ( method_exists( MediaWikiServices::class, 'getWikiPageFactory' ) ) {
				// MW 1.36+
				$mwServices = MediaWikiServices::getInstance();
				/** @noinspection PhpUndefinedMethodInspection */
				$this->wikiPage = $mwServices->getWikiPageFactory()->newFromTitle( $this->getTitle() );
			} else {
				$this->wikiPage = WikiPage::factory( $this->getTitle() );
			}
		}
		return $this->wikiPage;
	}

	/**
	 * @return RevisionRecord|null
	 * @throws MWException
	 */
	private function getRevisionRecord(): ?RevisionRecord {
		if ( $this->revisionRecord === null ) {
			$this->revisionRecord = $this->getWikiPage()->getRevisionRecord();
		}
		return $this->revisionRecord;
	}

	/** @inheritDoc */
	public function run( $page_id ) {
		$titleObj = $this->getTitle();
		if ( !$titleObj || !$titleObj->getArticleID() || !ChatbotRagContent::isRelevantTitle( $titleObj ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'rest-nonexistent-title',
					[ new ScalarParam( ParamType::PLAINTEXT, $page_id ) ]
				),
				404
			);
		}
		if ( !$this->permissionManager->userCan( 'read', $this->user, $titleObj ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'rest-permission-denied-title',
					[ new ScalarParam( ParamType::PLAINTEXT, $page_id ) ] ),
				403
			);
		}

		return $this->getPageData();
	}

	/** @inheritDoc */
	public function getParamSettings(): array {
		return [
			'identifier' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			]
		];
	}

	/** @inheritDoc */
	public function needsWriteAccess(): bool {
		return false;
	}

	/**
	 * Reformat an HTML snippet into plain text
	 *
	 * @param string $html The HTML string to search.
	 * @return string the re-formatted text
	 */
	private function convertHtmlToText( string $html ): string {
		// Strip everything but links, which we will re-format
		$text = strip_tags( $html, '<a>' );
		$text = $this->reformatEmailAndPhoneLinks( $text );
		$text = $this->reformatLinks( $text );
		// Now strip the remaining tags, because who knows what's left
		$text = strip_tags( $text );
		return trim( $text );
	}

	/**
	 * Reformat '<a href="mailto:{email}">{email}</a>' links to ({email}).
	 * This is a special case, where the text of the link is the email address itself.
	 *
	 * @param string $html The HTML string to search.
	 * @return string the re-formatted text
	 */
	private function reformatEmailAndPhoneLinks( string $html ): string {
		return preg_replace( '/<a\s+.*?href="(?:mailto|tel):([^"]+)"[^>]*>\1<\/a>/i', '(\1)', $html );
	}

	/**
	 * Reformat <a href=""> links to Link_Text (URL) format
	 *
	 * @param string $html The HTML string to search.
	 * @return string the re-formatted text
	 */
	private function reformatLinks( string $html ): string {
		return preg_replace_callback( '/<a\s+.*?href="([^"]+)"[^>]*>([^<]*)<\/a>/i',
			'self::reformatLinksCallback', $html
		);
	}

	/**
	 * Reformat how links are displayed
	 * @param array $matches
	 * @return string
	 */
	public static function reformatLinksCallback( $matches ): string {
		$url = str_replace( 'mailto:', '', $matches[1] );
		return $matches[2] . ' (' . urldecode( $url ) . ')';
	}

	/**
	 * Make a DOMDocument from a fragment of HTML
	 *
	 * @param string $html The HTML fragment
	 * @return DOMDocument $dom The DOMDocument instance.
	 */
	private function getDomDocumentFromFragment( string $html ): DOMDocument {
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = true;
		// Unicode-compatibility - see:
		// https://stackoverflow.com/questions/8218230/php-domdocument-loadhtml-not-encoding-utf-8-correctly
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		return $dom;
	}

	/**
	 * Remove empty elements from a DOMDocument
	 * @return void
	 */
	private function removeEmptyElements() {
		$xpath = new \DOMXPath( $this->dom );
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( ( $node_list = $xpath->query( '//*[not(*) and not(@*) and not(text()[normalize-space()])]' ) )
			&& $node_list->length
		) {
			foreach ( $node_list as $node ) {
				$node->parentNode->removeChild( $node );
			}
		}
	}

	/**
	 * Get the content (including child elements) of an element identified by a CSS selector.
	 *
	 * @param string $selector The CSS selector to use to find the element.
	 * @return string The content of the matched element, including child elements.
	 */
	private function getElementContentBySelector( string $selector ): string {
		$converter = new CssSelectorConverter();
		$xpath = new \DOMXpath( $this->dom );
		$elements = $xpath->query( $converter->toXPath( $selector ) );
		if ( $elements->length > 0 ) {
			$element = $elements->item( 0 );
			$content = $this->dom->saveHTML( $element );
			return $content;
		}

		return '';
	}

	/**
	 * Remove elements from a DOMDocument based on a CSS selector.
	 *
	 * @param string $selector The CSS selector to use to find the elements to remove.
	 */
	private function removeElementsBySelector( string $selector ): void {
		$converter = new CssSelectorConverter();
		$xpath = new DOMXpath( $this->dom );
		$elements = $xpath->query( $converter->toXPath( $selector ) );
		foreach ( $elements as $element ) {
			$element->parentNode->removeChild( $element );
		}
	}

	/**
	 * @return array of category names
	 */
	private function getOnlyVisibleCategories() {
		$categories = iterator_to_array( $this->getWikiPage()->getCategories() );
		$hiddenCategories = $this->getWikiPage()->getHiddenCategories();
		$visibleCategories = array_diff( $categories, $hiddenCategories );

		$plainNames = [];
		foreach ( $visibleCategories as $category ) {
			$plainNames[] = $category->getText();
		}

		return $plainNames;
	}

	/**
	 * Gather everything we need to send
	 *
	 * @return array|string
	 */
	private function getPageData() {
		$renderedRevision = $this->revisionRenderer->getRenderedRevision( $this->getRevisionRecord() );
		$parserOutput = $renderedRevision->getRevisionParserOutput();
		$pageHtml = $parserOutput->getText( [ 'allowTOC' => false, 'enableSectionEditLinks' => false ] );

		$categories = $this->getOnlyVisibleCategories();

		// Remove comments before further processing using DOM
		$pageHtml = preg_replace( '/<!--[\s\S]*?-->/', '', $pageHtml );
		$this->dom = $this->getDomDocumentFromFragment( $pageHtml );

		// Extract the summary content
		$summary = $this->getElementContentBySelector( '.article-summary' );
		$summary = $this->convertHtmlToText( $summary );

		// Remove the summary from the document
		$this->removeElementsBySelector( '.article-summary' );

		$this->removeElementsBySelector( '.share-links' );

		// Remove other useless elements
		$this->removeElementsBySelector( '.toc-box' );

		// Remove maps - rare, probably only a single page, but still annoying
		$this->removeElementsBySelector( '.maps-map' );

		$this->removeEmptyElements();

		// Extract the main content
		$processedHtml = $this->dom->saveHTML();
		$processedHtml = html_entity_decode( $processedHtml, ENT_COMPAT | ENT_HTML401, 'UTF-8' );
		$mainContent = $this->convertHtmlToText( $processedHtml );

		$articleTypeCode = ArticleType::getArticleType( $this->getTitle() );
		$articleType = ArticleType::getReadableArticleTypeFromCode( $articleTypeCode, 2 );
		$articleContentArea = ArticleContentArea::getArticleContentArea( $this->getTitle() ) ?? 'unknown';

		return [
			'page_id' => $this->getWikiPage()->getId(),
			'title' => $this->getTitle()->getFullText(),
			'namespace' => $this->getTitle()->getNamespace(),
			'url' => urldecode( $this->getTitle()->getFullURL() ),
			'articleType' => $articleType,
			'articleContentArea' => $articleContentArea,
			'summary' => trim( $summary ),
			'content' => trim( $mainContent ),
			'contentHtml' => trim( $processedHtml ),
			'categories' => $categories
		];
	}

}
