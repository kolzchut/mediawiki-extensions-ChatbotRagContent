<?php

namespace MediaWiki\Extension\ChatbotRagContent;


use DOMDocument;
use DOMXPath;
use MediaWiki\Extension\ArticleContentArea\ArticleContentArea;
use MediaWiki\Extension\ArticleType\ArticleType;
use MediaWiki\Revision\RevisionRenderer;
use Symfony\Component\CssSelector\CssSelectorConverter;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\SimpleHandler;
use RequestContext;
use Title;
use User;
use Wikimedia\Message\MessageValue;
use Wikimedia\Message\ParamType;
use Wikimedia\Message\ScalarParam;
use Wikimedia\ParamValidator\ParamValidator;

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

	private DOMDocument $dom;


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
			$this->title = Title::newFromID( $this->getValidatedParams()['page_id'] ) ?? false;
		}
		return $this->title;
	}

	/** @inheritDoc */
	public function run( $page_id ) {
		$titleObj = $this->getTitle();
		if ( !$titleObj || !$titleObj->getArticleID() ) {
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

		return $this->getPageData( $titleObj );

	}
	/** @inheritDoc */
	public function getParamSettings() {
		return [
			'page_id' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			]
		];
	}

	/** @inheritDoc */
	public function needsWriteAccess() {
		return false;
	}

	/**
	 * Reformat an HTML snippet into plain text
	 *
	 * @param string $html The HTML string to search.
	 * @return string the re-formatted text
	 */
	function convertHtmlToText(string $html): string
	{
		// Strip everything but links, which we will re-format
		$text = strip_tags($html, '<a>');
		$text = $this->reformatEmailAndPhoneLinks($text);
		$text = $this->reformatLinks($text);
		// Now strip the remaining tags, because who knows what's left
		$text = strip_tags($text);
		return trim($text);
	}

	/**
	 * Reformat '<a href="mailto:{email}">{email}</a>' links to ({email}).
	 * This is a special case, where the text of the link is the email address itself.
	 *
	 * @param string $html The HTML string to search.
	 * @return string the re-formatted text
	 */
	function reformatEmailAndPhoneLinks(string $html): string
	{
		return preg_replace('/<a\s+.*?href="(?:mailto|tel):([^"]+)"[^>]*>\1<\/a>/i', '(\1)', $html);
	}

	/**
	 * Reformat <a href=""> links to Link_Text (URL) format
	 *
	 * @param string $html The HTML string to search.
	 * @return string the re-formatted text
	 */
	function reformatLinks(string $html): string
	{
		return preg_replace_callback('/<a\s+.*?href="([^"]+)"[^>]*>([^<]*)<\/a>/i', 'reformatLinksCallback', $html);
	}

	function reformatLinksCallback($matches): string
	{
		$url = str_replace('mailto:', '', $matches[1]);
		return $matches[2] . ' (' . urldecode($url) . ')';
	}

	/**
	 * Make a DOMDocument from a fragment of HTML
	 *
	 * @param string $html The HTML fragment
	 * @return DOMDocument $dom The DOMDocument instance.
	 */
	function getDomDocumentFromFragment(string $html): DOMDocument
	{
		libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = true;
		// Unicode-compatibility - see:
		// https://stackoverflow.com/questions/8218230/php-domdocument-loadhtml-not-encoding-utf-8-correctly
		$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

		return $dom;
	}

	function removeEmptyElements()
	{
		$xpath = new \DOMXPath( $this->dom );
		while (($node_list = $xpath->query('//*[not(*) and not(@*) and not(text()[normalize-space()])]')) && $node_list->length) {
			foreach ($node_list as $node) {
				$node->parentNode->removeChild($node);
			}
		}
	}

	/**
	 * Get the content (including child elements) of an element identified by a CSS selector.
	 *
	 * @param string $selector The CSS selector to use to find the element.
	 * @return string The content of the matched element, including child elements.
	 */
	function getElementContentBySelector( string $selector ): string
	{
		$converter = new CssSelectorConverter();
		$xpath = new \DOMXpath( $this->dom );
		$elements = $xpath->query($converter->toXPath($selector));
		if ($elements->length > 0) {
			$element = $elements->item(0);
			$content = $this->dom->saveHTML($element);
			return $content;
		}

		return '';
	}

	/**
	 * Remove elements from a DOMDocument based on a CSS selector.
	 *
	 * @param string $selector The CSS selector to use to find the elements to remove.
	 */
	function removeElementsBySelector( string $selector): void
	{
		$converter = new CssSelectorConverter();
		$xpath = new DOMXpath( $this->dom );
		$elements = $xpath->query($converter->toXPath($selector));
		foreach ($elements as $element) {
			$element->parentNode->removeChild($element);
		}
	}

	function getOnlyVisibleCategories( \WikiPage $wikiPage ) {
		// @todo only get visible categories, by somehow subtracting $hiddenCategories

		$categories = iterator_to_array( $wikiPage->getCategories() );
		$hiddenCategories = $wikiPage->getHiddenCategories();
		$visibleCategories = array_diff( $categories, $hiddenCategories );

		$plainNames = [];
		foreach ( $visibleCategories as $category ) {
			$plainNames[] = $category->getText();
		}

		return $plainNames;
	}

	function getPageData( Title $title ) {
		// Ignore non-Hebrew pages
		if ( $title->getPageLanguage()->getCode() !== 'he') {
			// @todo decide on error format
			return 'error';
		};

		$wikiPage = new \WikiPage( $title );
		$pageContent = $wikiPage->getContent();
		$renderedRevision = $this->revisionRenderer->getRenderedRevision( $wikiPage->getRevisionRecord() );
		$parserOutput = $renderedRevision->getRevisionParserOutput();
		$pageHtml = $parserOutput->getText( [ 'allowTOC' => false, 'enableSectionEditLinks' => false, 'unwrap' => true ] );

		// @todo only get visible categories, by somehow subtracting $hiddenCategories
		$categories = $this->getOnlyVisibleCategories( $wikiPage );

		// Remove comments before further processing using DOM
		$pageHtml = preg_replace('/<!--[\s\S]*?-->/', '', $pageHtml);
		$this->dom = $this->getDomDocumentFromFragment( $pageHtml );

		// Extract the summary content
		$summary = $this->getElementContentBySelector( '.article-summary');
		$summary = $this->convertHtmlToText($summary);

		// Remove the summary from the document
		$this->removeElementsBySelector( '.article-summary');

		// Remove other useless elements
		$this->removeElementsBySelector( '.toc-box');

		// Remove maps - rare, probably only a single page, but still annoying
		$this->removeElementsBySelector( '.maps-map');

		$this->removeEmptyElements();

		// Extract the main content
		$processedHtml = $this->dom->saveHTML();
		$processedHtml = html_entity_decode($processedHtml, ENT_COMPAT | ENT_HTML401, 'UTF-8');
		$mainContent = $this->convertHtmlToText($processedHtml);

		$articleTypeCode = ArticleType::getArticleType( $title );
		$articleType = ArticleType::getReadableArticleTypeFromCode( $articleTypeCode, 2 );
		$articleContentArea = ArticleContentArea::getArticleContentArea( $title ) ?? 'unknown';

		return [
			'id' => $wikiPage->getId(),
			'title' => $title->getFullText(),
			'url' => urldecode( $title->getFullURL() ),
			'articleType' => $articleType,
			'articleContentArea' => $articleContentArea,
			'summary' => trim($summary),
			'content' => trim($mainContent),
			'contentHtml' => trim($processedHtml),
			'categories' => implode(PHP_EOL, $categories)
		];
	}

}
