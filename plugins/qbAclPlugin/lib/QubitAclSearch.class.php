<?php

/*
 * This file is part of the Access to Memory (AtoM) software.
 *
 * Access to Memory (AtoM) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Access to Memory (AtoM) is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Access to Memory (AtoM).  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Filter search query objects based in the access lists. The queries are
 * instances of the \Elastica\Query class.
 */
class QubitAclSearch
{
    /**
     * Filter search query by repository.
     *
     * @param \Elastica\Query $query  Search query object
     * @param string          $action Action
     *
     * @return \Elastica\Query Filtered query
     */
    public static function filterByRepository(Elastica\Query $query, $action)
    {
        $repositoryAccess = QubitAcl::getRepositoryAccess($action);
        if (1 == count($repositoryAccess)) {
            // If all repositories are denied access, re-route user to login
            if (QubitAcl::DENY == $repositoryAccess[0]['access']) {
                QubitAcl::forwardUnauthorized();
            }
        } else {
            while ($repo = array_shift($repositoryAccess)) {
                if ('*' == $repo['id']) {
                    $queryBool = new \Elastica\Query\BoolQuery();

                    $query = new \Elastica\Query\Term();
                    $query->setTerm('repositoryId', $repo['id']);

                    if (QubitAcl::DENY == $repo['access']) {
                        // Require repos to be specifically allowed (all others prohibited)
                        // (ZSL) $query->addSubquery(QubitSearch::getInstance()->addTerm($repo['id'], 'repositoryId'), true);
                        $queryBool->addMust($query);
                    } else {
                        // Prohibit specified repos (all others allowed)
                        // (ZSL) $query->addSubquery(QubitSearch::getInstance()->addTerm($repo['id'], 'repositoryId'), false);
                        $queryBool->addMustNot($query);
                    }

                    $query->setPostFilter($queryBool);
                }
            }
        }

        return $query;
    }

    /**
     * Filter search query by resource specific ACL.
     *
     * @param \Elastica\Query $query Search query object
     * @param mixed           $root  Root object for list
     *
     * @return \Elastica\Query Filtered query
     */
    public static function filterByResource(Elastica\Query $query, $root)
    {
        $user = sfContext::getInstance()->user;

        $permissions = QubitAcl::getUserPermissionsByAction($user, get_class($root), 'read');

        // Build access control list
        $grants = 0;
        if (0 < count($permissions)) {
            foreach ($permissions as $permission) {
                if (!isset($resourceAccess[$permission->objectId])) {
                    $resourceAccess[$permission->objectId] = QubitAcl::isAllowed($user, $permission->objectId, 'read');

                    if ($resourceAccess[$permission->objectId]) {
                        ++$grants;
                    }
                }
            }
        }

        // If no grants then user can't see anything
        if (0 == $grants) {
            QubitAcl::forwardUnauthorized();
        }

        // If global deny is default, then list allowed resources
        elseif (!QubitAcl::isAllowed($user, $root->id, 'read')) {
            $allows = array_keys($resourceAccess, true, true);

            $ids = [];
            while ($resourceId = array_shift($allows)) {
                // (ZSL) $query->addSubquery(QubitSearch::getInstance()->addTerm($resourceId, 'id'), true);
                $ids[] = $resourceId;
            }

            if (0 < count($ids)) {
                $queryIds = new \Elastica\Query\Ids();
                $queryIds->setIds($ids);

                $query->setPostFilter($queryIds);
            }
        }

        // Otherwise, build a list of banned resources
        else {
            $bans = array_keys($resourceAccess, false, true);

            $ids = [];
            while ($resourceId = array_shift($bans)) {
                // (ZSL) $query->addSubquery(QubitSearch::getInstance()->addTerm($resourceId, 'id'), false);
                $ids[] = $resourceId;
            }

            if (0 < count($ids)) {
                $queryIds = new \Elastica\Query\Ids();
                $queryIds->setIds($ids);

                $queryBool = new \Elastica\Query\BoolQuery();
                $queryBool->addMustNot($ids);

                $query->setPostFilter($queryIds);
            }
        }

        return $query;
    }

    /**
     * Historical entry point: visibility requires read AND, for drafts, viewDraft.
     * Apply this inside the query, before totals, facets and pagination.
     */
    public static function filterDrafts(Elastica\Query\BoolQuery $queryBool)
    {
        self::filterReadable($queryBool);
    }

    public static function filterReadable(Elastica\Query\BoolQuery $queryBool)
    {
        $allowed = new \Elastica\Query\BoolQuery();
        $allowed->setMinimumShouldMatch(1);
        $count = 0;

        foreach (self::getReadRegions() as $region) {
            if (!$region['read']) {
                continue;
            }

            $scope = self::subtreeQuery($region['id']);
            foreach ($region['exclude'] as $id) {
                $scope->addMustNot(self::subtreeQuery($id));
            }
            if (!$region['viewDraft']) {
                $scope->addMust(new \Elastica\Query\Term([
                    'publicationStatusId' => QubitTerm::PUBLICATION_STATUS_PUBLISHED_ID,
                ]));
            }
            $allowed->addShould($scope);
            ++$count;
        }

        // An empty bool query means match-all in Elasticsearch: deny explicitly.
        if (0 === $count) {
            $queryBool->addMustNot(new \Elastica\Query\MatchAll());
        } else {
            $queryBool->addFilter($allowed);
        }
    }

    /**
     * Partition the hierarchy only where effective ACLs can change: explicit
     * read/viewDraft/all-action permissions and repository assignments when
     * conditional permissions exist. Let the existing Zend ACL resolve grants,
     * denials, user/group precedence and repository conditions on real resources.
     * No per-record ID whitelist, persistent permission cache or index migration.
     *
     * Regions include their root and descendants, excluding child regions with
     * different access. A child grant can therefore override an ancestor denial.
     * All permission owners are included when finding boundaries; irrelevant
     * rules merely create redundant boundaries, which are folded below.
     */
    public static function getReadRegions()
    {
        $user = sfContext::getInstance()->user;
        $conditional = (bool) QubitPdo::fetchColumn(
            "SELECT COUNT(*) FROM acl_permission WHERE conditional IS NOT NULL"
            ." AND (action IN ('read', 'viewDraft') OR action IS NULL)"
        );
        $sql = "SELECT io.id, io.lft, io.rgt FROM information_object io WHERE io.id = ?"
            ." OR EXISTS (SELECT 1 FROM acl_permission ap WHERE ap.object_id = io.id"
            ." AND (ap.action IN ('read', 'viewDraft') OR ap.action IS NULL))";
        if ($conditional) {
            $sql .= ' OR io.repository_id IS NOT NULL';
        }
        $sql .= ' ORDER BY io.lft';
        $nodes = QubitPdo::fetchAll($sql, [QubitInformationObject::ROOT_ID]);
        $regions = $stack = [];

        foreach ($nodes as $node) {
            while ($stack && $regions[end($stack)]['rgt'] < (int) $node->lft) {
                array_pop($stack);
            }
            $resource = QubitInformationObject::getById($node->id);
            $read = QubitAcl::isAllowed($user, $resource, 'read');
            $draft = $read && QubitAcl::isAllowed($user, $resource, 'viewDraft');
            if ($stack) {
                $parent = end($stack);
                if ($regions[$parent]['read'] === $read && $regions[$parent]['viewDraft'] === $draft) {
                    continue;
                }
                $regions[$parent]['exclude'][] = (int) $node->id;
            }
            $regions[] = [
                'id' => (int) $node->id, 'lft' => (int) $node->lft,
                'rgt' => (int) $node->rgt, 'read' => $read,
                'viewDraft' => $draft, 'exclude' => [],
            ];
            $stack[] = count($regions) - 1;
        }

        return $regions;
    }

    /** Apply the same readable regions to SQL tree navigation before LIMIT/count. */
    public static function addReadCriteria(Criteria $criteria)
    {
        $regions = self::getReadRegions();
        $byId = [];
        foreach ($regions as $region) {
            $byId[$region['id']] = $region;
        }
        $allowed = [];
        foreach ($regions as $region) {
            if (!$region['read']) {
                continue;
            }
            $parts = [sprintf('(information_object.lft BETWEEN %d AND %d)', $region['lft'], $region['rgt'])];
            foreach ($region['exclude'] as $id) {
                $child = $byId[$id];
                $parts[] = sprintf('(information_object.lft NOT BETWEEN %d AND %d)', $child['lft'], $child['rgt']);
            }
            if (!$region['viewDraft']) {
                $parts[] = sprintf(
                    'EXISTS (SELECT 1 FROM status acl_status WHERE acl_status.object_id = information_object.id'
                    .' AND acl_status.type_id = %d AND acl_status.status_id = %d)',
                    QubitTerm::STATUS_TYPE_PUBLICATION_ID,
                    QubitTerm::PUBLICATION_STATUS_PUBLISHED_ID
                );
            }
            $allowed[] = '('.implode(' AND ', $parts).')';
        }
        $criteria->addAnd('', $allowed ? '('.implode(' OR ', $allowed).')' : '1 = 0', Criteria::CUSTOM);

        return $criteria;
    }

    private static function subtreeQuery($id)
    {
        $scope = new \Elastica\Query\BoolQuery();
        $scope->setMinimumShouldMatch(1);
        $scope->addShould(new \Elastica\Query\Ids([(string) $id]));
        $scope->addShould(new \Elastica\Query\Term(['ancestors' => $id]));

        return $scope;
    }
}
