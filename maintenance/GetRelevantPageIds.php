<?php

namespace MediaWiki\Extension\ChatbotRagContent\Maintenance;

use Maintenance;
use MediaWiki\Extension\ChatbotRagContent\ChatbotRagContent;
use MediaWiki\MediaWikiServices;
use Title;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Maintenance script to output all page IDs that are relevant to the chatbot RAG system.
 *
 * Usage:
 *   php extensions/WikiRights/ChatbotRagContent/maintenance/GetRelevantPageIds.php
 *   php extensions/WikiRights/ChatbotRagContent/maintenance/GetRelevantPageIds.php --output=json
 */
class GetRelevantPageIds extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Outputs all page IDs that are relevant to the chatbot RAG content system' );
		$this->requireExtension( 'ChatbotRagContent' );
		$this->addOption(
			'output',
			'Output format: "list" (one ID per line, default) or "json"',
			false,
			true
		);
		$this->addOption(
			'with-titles',
			'Include page titles in the output alongside page IDs'
		);
	}

	public function execute() {
		$dbr = $this->getDB( DB_REPLICA );
		$config = MediaWikiServices::getInstance()->getMainConfig();

		$allowedNamespaces = $config->get( 'ChatbotRagContentNamespaces' );
		$titleAllowlist = $config->get( 'ChatbotRagContentTitleAllowlist' );

		// Pre-filter in SQL for performance — this is intentionally over-inclusive.
		// isRelevantTitle() is the authoritative check and is called on every candidate below.
		// The SQL only avoids loading obviously irrelevant pages (redirects, non-wikitext,
		// wrong namespace, excluded prop) into PHP. If isRelevantTitle() gains new filters,
		// correctness is preserved; at worst, the SQL passes through some extra rows.
		$conditions = [
			'page_is_redirect' => 0,
			// page_content_model is NULL when the page uses its namespace's default model
			// (the common case for ordinary wikitext articles), so we must let NULL through.
			// isRelevantTitle()'s isWikitextPage() is the authoritative content-model check.
			$dbr->makeList( [
				'page_content_model' => CONTENT_MODEL_WIKITEXT,
				'page_content_model IS NULL',
			], LIST_OR ),
			// Pages with exclude_from_rag prop are excluded via the LEFT JOIN below
			'pp_propname IS NULL',
		];

		// Build namespace OR allowlist condition
		$nsOrAllowlistConds = [];

		if ( !empty( $allowedNamespaces ) ) {
			$nsOrAllowlistConds[] = $dbr->makeList(
				[ 'page_namespace' => $allowedNamespaces ],
				LIST_OR
			);
		}

		foreach ( $titleAllowlist as $titleText ) {
			$t = Title::newFromText( $titleText );
			if ( $t !== null ) {
				$nsOrAllowlistConds[] = $dbr->makeList(
					[
						'page_namespace' => $t->getNamespace(),
						'page_title' => $t->getDBkey(),
					],
					LIST_AND
				);
			}
		}

		if ( !empty( $nsOrAllowlistConds ) ) {
			$conditions[] = $dbr->makeList( $nsOrAllowlistConds, LIST_OR );
		}

		$res = $dbr->select(
			[ 'page', 'page_props' ],
			[ 'page_id', 'page_namespace', 'page_title', 'page_is_redirect', 'page_content_model' ],
			$conditions,
			__METHOD__,
			[],
			[
				'page_props' => [
					'LEFT JOIN',
					[ 'pp_page = page_id', 'pp_propname' => 'exclude_from_rag' ],
				],
			]
		);

		// For each SQL-filtered candidate, call isRelevantTitle() to handle the remaining
		// checks (language, article type blocklist) that can't easily be done in SQL.
		$withTitles = $this->hasOption( 'with-titles' );
		$relevant = [];
		foreach ( $res as $row ) {
			$title = Title::newFromRow( $row );
			if ( ChatbotRagContent::isRelevantTitle( $title ) ) {
				if ( $withTitles ) {
					$relevant[] = [ 'id' => (int)$row->page_id, 'title' => $title->getPrefixedText() ];
				} else {
					$relevant[] = (int)$row->page_id;
				}
			}
		}

		$format = $this->getOption( 'output', 'list' );
		if ( $format === 'json' ) {
			$this->output( json_encode( $relevant ) . "\n" );
		} else {
			foreach ( $relevant as $entry ) {
				if ( $withTitles ) {
					$this->output( $entry['id'] . "\t" . $entry['title'] . "\n" );
				} else {
					$this->output( $entry . "\n" );
				}
			}
		}
	}
}

$maintClass = GetRelevantPageIds::class;
require_once RUN_MAINTENANCE_IF_MAIN;
