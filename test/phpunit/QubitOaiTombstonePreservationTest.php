<?php

use AccessToMemory\test\TransactionTestCase;

/**
 * Real-database regression coverage for the UNIL OAI preservation hooks.
 *
 * Run only with the project's test bootstrap and a disposable fixture database.
 * Persistence uses the actual models and is rolled back by TransactionTestCase.
 * Search indexing is disabled through the application API, not mocked.
 *
 * @internal
 *
 * @covers \QubitInformationObject::delete
 * @covers \QubitInformationObject::setPublicationStatus
 * @covers \QubitOaiDeletedRecord
 */
class QubitOaiTombstonePreservationTest extends TransactionTestCase
{
    private $previousSearchInstance;
    private $previousSearchEnabled;
    private $searchInstanceProperty;
    private $searchEnabledProperty;

    protected function setUp(): void
    {
        $this->assertSame('test', sfConfig::get('sf_environment'), 'Use the test application and a disposable database.');
        parent::setUp();

        // Restore the existing search singleton and state after every test.
        // No Elasticsearch service or persistence substitute is needed.
        $this->searchInstanceProperty = new ReflectionProperty(QubitSearch::class, 'instance');
        $this->searchInstanceProperty->setAccessible(true);
        $this->previousSearchInstance = $this->searchInstanceProperty->getValue();
        if (null !== $this->previousSearchInstance) {
            $this->searchEnabledProperty = new ReflectionProperty(arElasticSearchPlugin::class, 'enabled');
            $this->searchEnabledProperty->setAccessible(true);
            $this->previousSearchEnabled = $this->searchEnabledProperty->getValue($this->previousSearchInstance);
        }

        QubitSearch::disable();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if (null !== $this->searchInstanceProperty) {
                if (null !== $this->previousSearchInstance) {
                    $this->searchEnabledProperty->setValue($this->previousSearchInstance, $this->previousSearchEnabled);
                }
                $this->searchInstanceProperty->setValue(null, $this->previousSearchInstance);
            }
        }
    }

    public function testPublicationLifecycleAndDeletionPreserveBothTopLevelTombstones(): void
    {
        $resource = $this->createResource(QubitInformationObject::ROOT_ID, QubitTerm::PUBLICATION_STATUS_PUBLISHED_ID);
        $localId = (int) $resource->getOaiLocalIdentifier();
        $identifier = $this->oaiIdentifier($localId);

        $this->assertSame([], $this->tombstoneRows($localId));
        foreach (['oai_dc', 'oai_ead'] as $prefix) {
            $this->assertNull(QubitOaiDeletedRecord::getActiveByOaiLocalIdentifier($localId, $prefix));
        }

        $resource = $this->changeStatus($resource, QubitTerm::PUBLICATION_STATUS_DRAFT_ID);
        $rows = $this->tombstoneRows($localId);
        $this->assertCount(2, $rows);
        $tombstoneIds = [];
        foreach (['oai_dc', 'oai_ead'] as $prefix) {
            $tombstone = $this->assertActiveTombstone($localId, $prefix, 'unpublished', $identifier, true);
            $tombstoneIds[$prefix] = (int) $tombstone->id;
            $this->assertSame(1, (int) $rows[$prefix]->active);
            $this->assertNull($rows[$prefix]->restored_at);
        }

        $resource = $this->changeStatus($resource, QubitTerm::PUBLICATION_STATUS_PUBLISHED_ID);
        $rows = $this->tombstoneRows($localId);
        $this->assertCount(2, $rows, 'Republishing must retain history rather than remove tombstone rows.');
        foreach (['oai_dc', 'oai_ead'] as $prefix) {
            $this->assertNull(QubitOaiDeletedRecord::getActiveByOaiLocalIdentifier($localId, $prefix));
            $this->assertSame($tombstoneIds[$prefix], (int) $rows[$prefix]->id);
            $this->assertSame(0, (int) $rows[$prefix]->active);
            $this->assertNotNull($rows[$prefix]->restored_at);
        }

        // Physical deletion after republishing must reactivate the same
        // persistent rows and preserve them after the live object is gone.
        $id = (int) $resource->id;
        $resource->delete($this->connection);

        $this->assertNull(QubitInformationObject::getById($id));
        $this->assertSame(0, (int) QubitPdo::fetchColumn('SELECT COUNT(*) FROM information_object WHERE id = :id', [':id' => $id]));
        $rows = $this->tombstoneRows($localId);
        $this->assertCount(2, $rows, 'Deleting the live object must not cascade away its harvestable history.');
        foreach (['oai_dc', 'oai_ead'] as $prefix) {
            $tombstone = $this->assertActiveTombstone($localId, $prefix, 'deleted', $identifier, true);
            $this->assertSame($tombstoneIds[$prefix], (int) $tombstone->id);
            $this->assertSame(1, (int) $rows[$prefix]->active);
            $this->assertNull($rows[$prefix]->restored_at);
        }
    }

    public function testDeletingHierarchyRecordsPublishedChildrenAndOmitsNeverPublishedDrafts(): void
    {
        $collection = $this->createResource(QubitInformationObject::ROOT_ID, QubitTerm::PUBLICATION_STATUS_PUBLISHED_ID);
        $collectionId = (int) $collection->id;
        $collectionLocalId = (int) $collection->getOaiLocalIdentifier();
        $collectionIdentifier = $this->oaiIdentifier($collectionLocalId);

        $publishedChild = $this->createResource($collectionId, QubitTerm::PUBLICATION_STATUS_PUBLISHED_ID);
        $publishedChildId = (int) $publishedChild->id;
        $publishedChildLocalId = (int) $publishedChild->getOaiLocalIdentifier();

        $draftChild = $this->createResource($collectionId, QubitTerm::PUBLICATION_STATUS_DRAFT_ID);
        $draftChildId = (int) $draftChild->id;
        $draftChildLocalId = (int) $draftChild->getOaiLocalIdentifier();

        // Reload after insertion of children so nested-set boundaries come
        // from the database, as in a later user request.
        $collection = QubitInformationObject::getById($collectionId);
        $this->assertSame(3, $collection->deleteFullHierarchy());

        foreach ([$collectionId, $publishedChildId, $draftChildId] as $id) {
            $this->assertNull(QubitInformationObject::getById($id));
        }
        foreach (['oai_dc', 'oai_ead'] as $prefix) {
            $this->assertActiveTombstone($collectionLocalId, $prefix, 'deleted', $collectionIdentifier, true);
        }

        $this->assertActiveTombstone($publishedChildLocalId, 'oai_dc', 'deleted', $collectionIdentifier, false);
        $this->assertNull(QubitOaiDeletedRecord::getActiveByOaiLocalIdentifier($publishedChildLocalId, 'oai_ead'));
        $this->assertCount(1, $this->tombstoneRows($publishedChildLocalId), 'Non-top-level EAD records are not individually advertised.');

        $this->assertSame([], $this->tombstoneRows($draftChildLocalId));
        $this->assertNull(QubitOaiDeletedRecord::getActiveByOaiLocalIdentifier($draftChildLocalId));
    }

    private function createResource(int $parentId, int $statusId): QubitInformationObject
    {
        $suffix = bin2hex(random_bytes(8));
        $resource = new QubitInformationObject();
        $resource->parentId = $parentId;
        $resource->title = 'OAI preservation fixture '.$suffix;
        $resource->identifier = 'OAI-preservation-'.$suffix;
        $resource->indexOnSave = false;
        $resource->setPublicationStatus($statusId);
        $resource->save($this->connection);

        $resource = QubitInformationObject::getById($resource->id);
        $resource->indexOnSave = false;
        $this->assertGreaterThan(0, (int) $resource->getOaiLocalIdentifier());
        $this->assertSame($statusId, (int) $resource->getPublicationStatus()->statusId);

        return $resource;
    }

    private function changeStatus(QubitInformationObject $resource, int $statusId): QubitInformationObject
    {
        $id = (int) $resource->id;
        $resource->setPublicationStatus($statusId);
        $resource->save($this->connection);
        $resource = QubitInformationObject::getById($id);
        $resource->indexOnSave = false;
        $this->assertSame($statusId, (int) $resource->getPublicationStatus()->statusId);

        return $resource;
    }

    private function assertActiveTombstone(int $localId, string $prefix, string $reason, string $setSpec, bool $topLevel): QubitOaiDeletedRecord
    {
        $record = QubitOaiDeletedRecord::getActiveByOaiLocalIdentifier($localId, $prefix);
        $this->assertInstanceOf(QubitOaiDeletedRecord::class, $record);
        $this->assertTrue($record->isDeleted());
        $this->assertSame($localId, (int) $record->oaiLocalIdentifier);
        $this->assertSame($this->oaiIdentifier($localId), $record->getOaiIdentifier());
        $this->assertSame($prefix, $record->metadataPrefix);
        $this->assertSame($reason, $record->reason);
        $this->assertSame($setSpec, $record->getSetSpec());
        $this->assertSame($topLevel, (bool) $record->isTopLevel);
        $this->assertNotEmpty($record->getUpdatedAt());

        return $record;
    }

    private function tombstoneRows(int $localId): array
    {
        $rows = QubitPdo::fetchAll(
            'SELECT id, metadata_prefix, active, restored_at FROM oai_deleted_record WHERE oai_local_identifier = :id ORDER BY metadata_prefix',
            [':id' => $localId]
        );
        $byPrefix = [];
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey($row->metadata_prefix, $byPrefix, 'Each prefix has one persistent row per OAI identifier.');
            $byPrefix[$row->metadata_prefix] = $row;
        }

        return $byPrefix;
    }

    private function oaiIdentifier(int $localId): string
    {
        return 'oai:'.QubitOai::getRepositoryIdentifier().'_'.$localId;
    }
}
