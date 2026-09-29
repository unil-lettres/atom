<?php

use AccessToMemory\test\TransactionTestCase;

/**
 * Real-model ACL/query parity. The separate HTTP/Elasticsearch integration
 * fixture also exercises counts, facets, pagination and generated ZIP files.
 *
 * @internal
 * @covers \QubitAclSearch
 */
class QubitAclSearchTest extends TransactionTestCase
{
    private $search;
    private $searchEnabled;
    private $enabledProperty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('test', sfConfig::get('sf_environment'));
        $this->search = QubitSearch::getInstance();
        $this->enabledProperty = new ReflectionProperty(arElasticSearchPlugin::class, 'enabled');
        $this->enabledProperty->setAccessible(true);
        $this->searchEnabled = $this->enabledProperty->getValue($this->search);
        QubitSearch::disable();
        sfContext::getInstance()->user->signOut();
        QubitAcl::destruct();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->enabledProperty->setValue($this->search, $this->searchEnabled);
            sfContext::getInstance()->user->signOut();
            QubitAcl::destruct();
            Qubit::clearClassCaches();
        }
    }

    public function testSqlFilteringHonoursNestedOverridesAndDrafts(): void
    {
        $parent = $this->node();
        $denied = $this->node($parent);
        $child = $this->node($denied);
        $exception = $this->node($denied);
        $exceptionChild = $this->node($exception);
        $draft = $this->node($parent, true);
        $draftAllowed = $this->node($parent, true);
        $draftChild = $this->node($draftAllowed, true);
        $draftOverride = $this->node($draftAllowed, true);
        $this->rule($parent, true);
        $this->rule($parent, false, 'viewDraft');
        $this->rule($denied, false);
        $this->rule($exception, true);
        $this->rule($draftAllowed, true, 'viewDraft');
        $this->rule($draftOverride, false, 'viewDraft');
        $ids = array_map(fn ($r) => (int) $r->id, [$parent, $denied, $child, $exception, $exceptionChild, $draft, $draftAllowed, $draftChild, $draftOverride]);
        $expected = array_map(fn ($r) => (int) $r->id, [$parent, $exception, $exceptionChild, $draftAllowed, $draftChild]);
        $this->assertParity($ids, $expected);

        // Revoke the formerly allowed exception without an index refresh.
        $this->rule($exception, false);
        $this->assertParity($ids, [(int) $parent->id, (int) $draftAllowed->id, (int) $draftChild->id]);
    }

    public function testRepositoryConditionAndExplicitChildGrant(): void
    {
        $repository = new QubitRepository();
        $repository->sourceCulture = 'en';
        $repository->authorizedFormOfName = 'ACL query test repository';
        $repository->save($this->connection);
        $parent = $this->node();
        $parent->repositoryId = $repository->id;
        $parent->save($this->connection);
        $child = $this->node($parent);
        $exception = $this->node($parent);
        $outside = $this->node();
        $this->rule($outside, true);
        $rule = $this->rule(QubitInformationObject::getRoot(), false);
        $rule->setRepository($repository);
        $rule->save($this->connection);
        $this->rule($exception, true);
        $this->assertParity(
            [(int) $parent->id, (int) $child->id, (int) $exception->id, (int) $outside->id],
            [(int) $exception->id, (int) $outside->id]
        );
    }

    public function testDenyAllDoesNotBecomeAnEmptyMatchAllQuery(): void
    {
        $node = $this->node();
        $this->rule(QubitInformationObject::getRoot(), false);
        $this->assertParity([(int) $node->id], []);
        $query = new Elastica\Query\BoolQuery();
        QubitAclSearch::filterReadable($query);
        $this->assertArrayHasKey('must_not', $query->toArray()['bool']);
    }

    private function node($parent = null, bool $draft = false): QubitInformationObject
    {
        $resource = new QubitInformationObject();
        $resource->parentId = $parent ? $parent->id : QubitInformationObject::ROOT_ID;
        $resource->sourceCulture = 'en';
        $resource->title = 'ACL query synthetic record';
        $resource->setPublicationStatus($draft ? QubitTerm::PUBLICATION_STATUS_DRAFT_ID : QubitTerm::PUBLICATION_STATUS_PUBLISHED_ID);
        $resource->save($this->connection);

        return $resource;
    }

    private function rule($resource, bool $allow, ?string $action = 'read'): QubitAclPermission
    {
        $permission = new QubitAclPermission();
        $permission->objectId = $resource->id;
        $permission->groupId = QubitAclGroup::ANONYMOUS_ID;
        $permission->action = $action;
        $permission->grantDeny = $allow ? 1 : 0;
        $permission->save($this->connection);

        return $permission;
    }

    private function assertParity(array $ids, array $expected): void
    {
        Qubit::clearClassCaches();
        QubitAcl::destruct();
        $direct = [];
        foreach ($ids as $id) {
            if (QubitAcl::check(QubitInformationObject::getById($id), 'read')) {
                $direct[] = $id;
            }
        }
        sort($direct);
        sort($expected);
        $this->assertSame($expected, $direct, 'The fixture has the intended effective ACL.');
        $criteria = new Criteria();
        $criteria->add(QubitInformationObject::ID, $ids, Criteria::IN);
        QubitAclSearch::addReadCriteria($criteria);
        $actual = [];
        foreach (QubitInformationObject::get($criteria) as $row) {
            $actual[] = (int) $row->id;
        }
        sort($actual);
        $this->assertSame($expected, $actual, 'SQL visibility equals direct consultation.');
    }
}
