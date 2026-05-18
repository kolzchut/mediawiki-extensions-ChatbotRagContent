<?php

namespace MediaWiki\Extension\ChatbotRagContent\Tests\Integration;

use JobQueueGroup;
use MediaWiki\Extension\ChatbotRagContent\Hooks;
use MediaWiki\Extension\ChatbotRagContent\RagUpdateJob;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\TitleFactory;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\ChatbotRagContent\Hooks
 * @group Database
 */
class HooksTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			'ChatbotRagContentPingURL' => 'https://example.com/ping',
			'ChatbotRagContentNamespaces' => [ NS_MAIN ],
			'ChatbotRagContentArticleTypeBlocklist' => [],
			'ChatbotRagContentTitleAllowlist' => [],
		] );
	}

	private function newHooks(): Hooks {
		$services = $this->getServiceContainer();

		return new Hooks(
			$services->getMainConfig(),
			$services->getJobQueueGroup(),
			$services->getTitleFactory(),
			$services->getPageProps(),
			$services->getContentLanguage()
		);
	}

	private function getJobQueueGroup(): JobQueueGroup {
		return $this->getServiceContainer()->getJobQueueGroup();
	}

	private function getTitleFactory(): TitleFactory {
		return $this->getServiceContainer()->getTitleFactory();
	}

	public function testOnPageDeletionDataUpdatesPassesRevisionDataToJob() {
		// Create a test page
		$this->insertPage( 'TestPageForDeletion', 'Content to be deleted' );
		$title = $this->getTitleFactory()->newFromText( 'TestPageForDeletion' );

		$revisionRecord = $this->getServiceContainer()->getRevisionLookup()
			->getRevisionByTitle( $title );

		$this->assertInstanceOf(
			RevisionRecord::class,
			$revisionRecord,
			'Should have a valid revision'
		);

		$jobQueue = $this->getJobQueueGroup();

		// Clear any existing jobs
		$jobQueue->get( 'ragUpdate' )->delete();

		// Call the hook
		$hooks = $this->newHooks();
		$updates = [];
		$hooks->onPageDeletionDataUpdates( $title, $revisionRecord, $updates );

		// Verify the job was queued
		$job = $jobQueue->get( 'ragUpdate' )->pop();
		$this->assertInstanceOf(
			RagUpdateJob::class,
			$job,
			'Should create a RagUpdateJob'
		);

		// Use reflection to access job params
		$reflection = new \ReflectionClass( $job );
		$paramsProperty = $reflection->getProperty( 'params' );
		$paramsProperty->setAccessible( true );
		$params = $paramsProperty->getValue( $job );

		$this->assertArrayHasKey( 'page_id', $params, 'Job should have page_id param' );
		$this->assertArrayHasKey( 'revision_id', $params, 'Job should have revision_id param' );
		$this->assertArrayHasKey( 'revision_date', $params, 'Job should have revision_date param' );

		$this->assertEquals(
			$revisionRecord->getPageId(),
			$params['page_id'],
			'page_id should match revision page ID'
		);
		$this->assertEquals(
			$revisionRecord->getId(),
			$params['revision_id'],
			'revision_id should match revision ID'
		);
		$this->assertEquals(
			$revisionRecord->getTimestamp(),
			$params['revision_date'],
			'revision_date should match revision timestamp'
		);
	}

	public function testOnPageDeletionDataUpdatesHandlesNullRevision() {
		// Create a real page first, so it passes the exists() check
		$this->insertPage( 'PageWithNullRevision', 'Content' );
		$title = $this->getTitleFactory()->newFromText( 'PageWithNullRevision' );

		// Get job queue
		$jobQueue = $this->getJobQueueGroup();

		// Clear any existing jobs (including one from insertPage)
		$jobQueue->get( 'ragUpdate' )->delete();

		// Call the hook with null revision
		$hooks = $this->newHooks();
		$updates = [];
		$hooks->onPageDeletionDataUpdates( $title, null, $updates );

		// Verify a job was still queued (but with empty params)
		$job = $jobQueue->get( 'ragUpdate' )->pop();
		$this->assertInstanceOf(
			RagUpdateJob::class,
			$job,
			'Should create a RagUpdateJob even with null revision'
		);

		// Verify our custom params are not set when revision is null
		$reflection = new \ReflectionClass( $job );
		$paramsProperty = $reflection->getProperty( 'params' );
		$paramsProperty->setAccessible( true );
		$params = $paramsProperty->getValue( $job );

		// Our custom params should not be present (Job framework adds its own params)
		$this->assertArrayNotHasKey( 'page_id', $params, 'page_id should not be set' );
		$this->assertArrayNotHasKey( 'revision_id', $params, 'revision_id should not be set' );
		$this->assertArrayNotHasKey( 'revision_date', $params, 'revision_date should not be set' );
	}

	public function testOnPageDeletionDataUpdatesRespectsNamespaceConfiguration() {
		// Set config to only allow NS_HELP
		$this->overrideConfigValues( [
			'ChatbotRagContentNamespaces' => [ NS_HELP ],
		] );

		// Create a page in NS_MAIN (not allowed)
		$this->insertPage( 'MainPageForDeletion', 'Content' );
		$title = $this->getTitleFactory()->newFromText( 'MainPageForDeletion' );
		$revisionRecord = $this->getServiceContainer()->getRevisionLookup()
			->getRevisionByTitle( $title );

		// Get job queue
		$jobQueue = $this->getJobQueueGroup();

		// Clear any existing jobs
		$jobQueue->get( 'ragUpdate' )->delete();

		// Call the hook
		$hooks = $this->newHooks();
		$updates = [];
		$hooks->onPageDeletionDataUpdates( $title, $revisionRecord, $updates );

		// Verify no job was pushed (page is in disallowed namespace)
		$job = $jobQueue->get( 'ragUpdate' )->pop();
		$this->assertFalse( $job, 'Should not create a job for disallowed namespace' );
	}

	public function testOnRevisionDataUpdatesCreatesJob() {
		// Create a test page
		$this->insertPage( 'TestPageForUpdate', 'Updated content' );
		$title = $this->getTitleFactory()->newFromText( 'TestPageForUpdate' );

		// Get job queue
		$jobQueue = $this->getJobQueueGroup();

		// Clear any existing jobs
		$jobQueue->get( 'ragUpdate' )->delete();

		// Call the hook
		$hooks = $this->newHooks();
		$updates = [];
		$hooks->onRevisionDataUpdates( $title, null, $updates );

		// Verify the job was queued
		$job = $jobQueue->get( 'ragUpdate' )->pop();
		$this->assertInstanceOf(
			RagUpdateJob::class,
			$job,
			'Should create a RagUpdateJob on revision update'
		);
	}

	public function testOnPageMoveCompleteCreatesJobWhenMovingInOrOutOfAllowedNamespace() {
		// Create a page in NS_MAIN (allowed)
		$this->insertPage( 'PageToMove', 'Content to move' );
		$oldTitle = $this->getTitleFactory()->newFromText( 'PageToMove' );

		// Create the destination page to simulate post-move state
		// (The hook is called after the move is complete, so the new page exists)
		$this->insertPage( 'Template:MovedPage', 'Content to move' );
		$newTitle = $this->getTitleFactory()->newFromText( 'Template:MovedPage' );

		// Get job queue
		$jobQueue = $this->getJobQueueGroup();

		// Clear any existing jobs (from both insertPage calls)
		$jobQueue->get( 'ragUpdate' )->delete();

		// Call the hook
		$hooks = $this->newHooks();
		$hooks->onPageMoveComplete(
			$oldTitle,
			$newTitle,
			$this->getTestUser()->getUser(),
			$oldTitle->getId(),
			// no redirect
			0,
			'test move',
			null
		);

		// Verify the job was queued
		$job = $jobQueue->get( 'ragUpdate' )->pop();
		$this->assertInstanceOf(
			RagUpdateJob::class,
			$job,
			'Should create a RagUpdateJob when page moves between namespaces'
		);
	}

	public function testHooksDoNotCreateJobWhenPingUrlNotConfigured() {
		// Disable ping URL
		$this->overrideConfigValues( [
			'ChatbotRagContentPingURL' => '',
		] );

		$this->insertPage( 'TestPageNoPing', 'Content' );
		$title = $this->getTitleFactory()->newFromText( 'TestPageNoPing' );

		// Get job queue
		$jobQueue = $this->getJobQueueGroup();

		// Clear any existing jobs
		$jobQueue->get( 'ragUpdate' )->delete();

		// Call the hook
		$hooks = $this->newHooks();
		$updates = [];
		$hooks->onRevisionDataUpdates( $title, null, $updates );

		// Verify no job was pushed (ping URL is not configured)
		$job = $jobQueue->get( 'ragUpdate' )->pop();
		$this->assertFalse( $job, 'Should not create a job when ping URL is not configured' );
	}
}
