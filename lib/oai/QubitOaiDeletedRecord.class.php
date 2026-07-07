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

class QubitOaiDeletedRecord
{
    public const TABLE_NAME = 'oai_deleted_record';
    public const TOP_LEVEL_SET_SPEC = 'oai:virtual:top-level-records';

    public $id;
    public $objectId;
    public $oaiLocalIdentifier;
    public $oaiIdentifier;
    public $metadataPrefix;
    public $datestamp;
    public $setSpec;
    public $isTopLevel;
    public $reason;

    public function __construct(array $values = [])
    {
        foreach ($values as $key => $value) {
            $this->{$key} = $value;
        }
    }

    public function isDeleted()
    {
        return true;
    }

    public function getOaiIdentifier()
    {
        return $this->oaiIdentifier;
    }

    public function getUpdatedAt()
    {
        return $this->datestamp;
    }

    public function getSetSpec()
    {
        return $this->setSpec;
    }

    public static function getRecords(array $options = [])
    {
        $limit = empty($options['limit']) ? 10 : (int) $options['limit'];
        $offset = empty($options['offset']) ? 0 : (int) $options['offset'];
        $metadataPrefix = empty($options['metadataPrefix']) ? 'oai_dc' : $options['metadataPrefix'];

        $params = [];
        $unionSql = self::buildUpdatedRecordsUnionSql($options, $metadataPrefix, $params);

        $count = (int) QubitPdo::fetchColumn('SELECT COUNT(*) FROM ('.$unionSql.') oai_records', $params);
        $remaining = $count - ($offset + $limit);
        $remaining = ($remaining < 0) ? 0 : $remaining;

        $sql = $unionSql.'
            ORDER BY datestamp ASC, sort_id ASC
            LIMIT '.$offset.', '.$limit;

        $records = [];
        foreach (QubitPdo::fetchAll($sql, $params) as $row) {
            if ('active' == $row->oai_status) {
                if (null !== $record = QubitInformationObject::getById($row->object_id)) {
                    $records[] = $record;
                }

                continue;
            }

            $records[] = self::fromRow($row);
        }

        return [
            'data' => $records,
            'remaining' => $remaining,
        ];
    }

    public static function getActiveByOaiLocalIdentifier($oaiLocalIdentifier, $metadataPrefix = null)
    {
        $params = [
            ':oaiLocalIdentifier' => $oaiLocalIdentifier,
        ];

        $metadataSql = '';
        if (null !== $metadataPrefix) {
            $metadataSql = 'AND metadata_prefix = :metadataPrefix';
            $params[':metadataPrefix'] = $metadataPrefix;
        }

        $sql = '
            SELECT
                id,
                NULL AS object_id,
                oai_local_identifier,
                oai_identifier,
                metadata_prefix,
                datestamp,
                set_spec,
                is_top_level,
                reason
            FROM oai_deleted_record
            WHERE active = 1
            AND oai_local_identifier = :oaiLocalIdentifier
            '.$metadataSql.'
            ORDER BY datestamp DESC
            LIMIT 1
        ';

        if (false === $row = QubitPdo::fetchOne($sql, $params)) {
            return null;
        }

        return self::fromRow($row);
    }

    public static function recordDeletionForTree(QubitInformationObject $resource, $reason = 'deleted')
    {
        if (!isset($resource->id)) {
            return;
        }

        foreach (self::getPublishedRowsInRange($resource->lft, $resource->rgt, true) as $row) {
            self::upsertTombstoneFromRow($row, 'oai_dc', $reason);

            if ((int) $row->parent_id === (int) QubitInformationObject::ROOT_ID) {
                self::upsertTombstoneFromRow($row, 'oai_ead', $reason);
            }
        }
    }

    public static function recordDeletionForResource(QubitInformationObject $resource, $reason = 'deleted')
    {
        if (!isset($resource->id)) {
            return;
        }

        foreach (self::getPublishedRowsById($resource->id) as $row) {
            self::upsertTombstoneFromRow($row, 'oai_dc', $reason);

            if ((int) $row->parent_id === (int) QubitInformationObject::ROOT_ID) {
                self::upsertTombstoneFromRow($row, 'oai_ead', $reason);
            }
        }
    }

    public static function recordVisibilityChange(QubitInformationObject $resource, $newStatusId)
    {
        if (!isset($resource->id)) {
            return;
        }

        $oldStatus = $resource->getPublicationStatus();
        $oldStatusId = isset($oldStatus) ? (int) $oldStatus->statusId : null;
        $newStatusId = (int) $newStatusId;

        if ((int) QubitTerm::PUBLICATION_STATUS_PUBLISHED_ID === $oldStatusId && (int) QubitTerm::PUBLICATION_STATUS_PUBLISHED_ID !== $newStatusId) {
            self::recordDeletionForResource($resource, 'unpublished');
        }

        if ((int) QubitTerm::PUBLICATION_STATUS_PUBLISHED_ID !== $oldStatusId && (int) QubitTerm::PUBLICATION_STATUS_PUBLISHED_ID === $newStatusId) {
            self::restoreForResource($resource);
        }
    }

    public static function recordVisibilityChangeForDescendants(QubitInformationObject $resource, $newStatusId)
    {
        if (!isset($resource->id)) {
            return;
        }

        if ((int) QubitTerm::PUBLICATION_STATUS_PUBLISHED_ID === (int) $newStatusId) {
            self::restoreForRange($resource->lft, $resource->rgt, false);

            return;
        }

        foreach (self::getPublishedRowsInRange($resource->lft, $resource->rgt, false) as $row) {
            self::upsertTombstoneFromRow($row, 'oai_dc', 'unpublished');

            if ((int) $row->parent_id === (int) QubitInformationObject::ROOT_ID) {
                self::upsertTombstoneFromRow($row, 'oai_ead', 'unpublished');
            }
        }
    }

    public static function restoreForTree(QubitInformationObject $resource)
    {
        self::restoreForRange($resource->lft, $resource->rgt, true);
    }

    public static function restoreForResource(QubitInformationObject $resource)
    {
        $sql = '
            UPDATE oai_deleted_record
            SET
                active = 0,
                updated_at = :now,
                restored_at = :now
            WHERE active = 1
            AND oai_local_identifier = :oaiLocalIdentifier
        ';

        QubitPdo::modify($sql, [
            ':now' => date('Y-m-d H:i:s'),
            ':oaiLocalIdentifier' => $resource->getOaiLocalIdentifier(),
        ]);
    }

    private static function getPublishedRowsInRange($lft, $rgt, $includeSelf)
    {
        $operatorLeft = $includeSelf ? '>=' : '>';
        $operatorRight = $includeSelf ? '<=' : '<';

        $sql = '
            SELECT
                io.id,
                io.oai_local_identifier,
                io.parent_id,
                collection.oai_local_identifier AS collection_oai_local_identifier
            FROM information_object io
            JOIN status st
                ON st.object_id = io.id
                AND st.type_id = :publicationStatusType
                AND st.status_id = :publishedStatus
            LEFT JOIN information_object ancestor
                ON ancestor.lft <= io.lft
                AND ancestor.rgt >= io.rgt
                AND ancestor.parent_id = :rootId
            LEFT JOIN information_object collection
                ON collection.id = ancestor.id
            WHERE io.lft '.$operatorLeft.' :lft
            AND io.rgt '.$operatorRight.' :rgt
        ';

        return QubitPdo::fetchAll($sql, [
            ':publicationStatusType' => QubitTerm::STATUS_TYPE_PUBLICATION_ID,
            ':publishedStatus' => QubitTerm::PUBLICATION_STATUS_PUBLISHED_ID,
            ':rootId' => QubitInformationObject::ROOT_ID,
            ':lft' => $lft,
            ':rgt' => $rgt,
        ]);
    }

    private static function getPublishedRowsById($id)
    {
        $sql = '
            SELECT
                io.id,
                io.oai_local_identifier,
                io.parent_id,
                collection.oai_local_identifier AS collection_oai_local_identifier
            FROM information_object io
            JOIN status st
                ON st.object_id = io.id
                AND st.type_id = :publicationStatusType
                AND st.status_id = :publishedStatus
            LEFT JOIN information_object ancestor
                ON ancestor.lft <= io.lft
                AND ancestor.rgt >= io.rgt
                AND ancestor.parent_id = :rootId
            LEFT JOIN information_object collection
                ON collection.id = ancestor.id
            WHERE io.id = :id
        ';

        return QubitPdo::fetchAll($sql, [
            ':publicationStatusType' => QubitTerm::STATUS_TYPE_PUBLICATION_ID,
            ':publishedStatus' => QubitTerm::PUBLICATION_STATUS_PUBLISHED_ID,
            ':rootId' => QubitInformationObject::ROOT_ID,
            ':id' => $id,
        ]);
    }

    private static function restoreForRange($lft, $rgt, $includeSelf)
    {
        $operatorLeft = $includeSelf ? '>=' : '>';
        $operatorRight = $includeSelf ? '<=' : '<';

        $sql = '
            UPDATE oai_deleted_record odr
            JOIN information_object io ON io.oai_local_identifier = odr.oai_local_identifier
            SET
                odr.active = 0,
                odr.updated_at = :now,
                odr.restored_at = :now
            WHERE odr.active = 1
            AND io.lft '.$operatorLeft.' :lft
            AND io.rgt '.$operatorRight.' :rgt
        ';

        QubitPdo::modify($sql, [
            ':now' => date('Y-m-d H:i:s'),
            ':lft' => $lft,
            ':rgt' => $rgt,
        ]);
    }

    private static function upsertTombstoneFromRow($row, $metadataPrefix, $reason)
    {
        if (empty($row->oai_local_identifier)) {
            return;
        }

        $collectionOaiLocalIdentifier = empty($row->collection_oai_local_identifier)
            ? $row->oai_local_identifier
            : $row->collection_oai_local_identifier;

        $isTopLevel = ((int) $row->parent_id === (int) QubitInformationObject::ROOT_ID);
        $now = date('Y-m-d H:i:s');

        $sql = '
            INSERT INTO oai_deleted_record
                (oai_local_identifier, oai_identifier, metadata_prefix, datestamp, set_spec, is_top_level, reason, active, created_at, updated_at, restored_at)
            VALUES
                (:oaiLocalIdentifier, :oaiIdentifier, :metadataPrefix, :datestamp, :setSpec, :isTopLevel, :reason, 1, :now, :now, NULL)
            ON DUPLICATE KEY UPDATE
                oai_identifier = VALUES(oai_identifier),
                datestamp = VALUES(datestamp),
                set_spec = VALUES(set_spec),
                is_top_level = VALUES(is_top_level),
                reason = VALUES(reason),
                active = 1,
                updated_at = VALUES(updated_at),
                restored_at = NULL
        ';

        QubitPdo::modify($sql, [
            ':oaiLocalIdentifier' => $row->oai_local_identifier,
            ':oaiIdentifier' => self::buildOaiIdentifier($row->oai_local_identifier),
            ':metadataPrefix' => $metadataPrefix,
            ':datestamp' => $now,
            ':setSpec' => self::buildOaiIdentifier($collectionOaiLocalIdentifier),
            ':isTopLevel' => $isTopLevel ? 1 : 0,
            ':reason' => $reason,
            ':now' => $now,
        ]);
    }

    private static function buildUpdatedRecordsUnionSql(array $options, $metadataPrefix, array &$params)
    {
        $activeWhere = [
            'st.status_id = :publishedStatus',
            'st.type_id = :publicationStatusType',
        ];
        $deletedWhere = [
            'odr.active = 1',
            'odr.metadata_prefix = :deletedMetadataPrefix',
        ];

        $params[':publishedStatus'] = QubitTerm::PUBLICATION_STATUS_PUBLISHED_ID;
        $params[':publicationStatusType'] = QubitTerm::STATUS_TYPE_PUBLICATION_ID;
        $params[':deletedMetadataPrefix'] = $metadataPrefix;

        if (!empty($options['from'])) {
            $activeWhere[] = 'obj.updated_at >= :fromActive';
            $deletedWhere[] = 'odr.datestamp >= :fromDeleted';
            $params[':fromActive'] = $options['from'];
            $params[':fromDeleted'] = $options['from'];
        }

        if (!empty($options['until'])) {
            $activeWhere[] = 'obj.updated_at <= :untilActive';
            $deletedWhere[] = 'odr.datestamp <= :untilDeleted';
            $params[':untilActive'] = $options['until'];
            $params[':untilDeleted'] = $options['until'];
        }

        if (!empty($options['topLevel'])) {
            $activeWhere[] = 'io.parent_id = :topLevelRootId';
            $deletedWhere[] = 'odr.is_top_level = 1';
            $params[':topLevelRootId'] = QubitInformationObject::ROOT_ID;
        }

        if (!empty($options['setSpec'])) {
            if (self::TOP_LEVEL_SET_SPEC === $options['setSpec']) {
                $activeWhere[] = 'io.parent_id = :setRootId';
                $deletedWhere[] = 'odr.is_top_level = 1';
                $params[':setRootId'] = QubitInformationObject::ROOT_ID;
            } elseif (null !== $collection = QubitInformationObject::getRecordByOaiID(QubitOai::getOaiIdNumber($options['setSpec']))) {
                $activeWhere[] = 'io.lft >= :setLft';
                $activeWhere[] = 'io.rgt <= :setRgt';
                $deletedWhere[] = 'SUBSTRING_INDEX(odr.set_spec, \'_\', -1) = :deletedSetLocalIdentifier';
                $params[':setLft'] = $collection->lft;
                $params[':setRgt'] = $collection->rgt;
                $params[':deletedSetLocalIdentifier'] = QubitOai::getOaiIdNumber($options['setSpec']);
            }
        }

        return '
            SELECT
                \'active\' AS oai_status,
                io.id AS object_id,
                io.oai_local_identifier AS oai_local_identifier,
                NULL AS oai_identifier,
                NULL AS metadata_prefix,
                obj.updated_at AS datestamp,
                NULL AS set_spec,
                (io.parent_id = '.QubitInformationObject::ROOT_ID.') AS is_top_level,
                NULL AS reason,
                io.id AS sort_id
            FROM information_object io
            JOIN status st ON st.object_id = io.id
            JOIN object obj ON obj.id = io.id
            WHERE '.implode(' AND ', $activeWhere).'

            UNION ALL

            SELECT
                \'deleted\' AS oai_status,
                NULL AS object_id,
                odr.oai_local_identifier AS oai_local_identifier,
                odr.oai_identifier AS oai_identifier,
                odr.metadata_prefix AS metadata_prefix,
                odr.datestamp AS datestamp,
                odr.set_spec AS set_spec,
                odr.is_top_level AS is_top_level,
                odr.reason AS reason,
                odr.oai_local_identifier AS sort_id
            FROM oai_deleted_record odr
            WHERE '.implode(' AND ', $deletedWhere);
    }

    private static function fromRow($row)
    {
        return new self([
            'id' => isset($row->id) ? $row->id : null,
            'objectId' => isset($row->object_id) ? $row->object_id : null,
            'oaiLocalIdentifier' => $row->oai_local_identifier,
            'oaiIdentifier' => $row->oai_identifier,
            'metadataPrefix' => $row->metadata_prefix,
            'datestamp' => $row->datestamp,
            'setSpec' => $row->set_spec,
            'isTopLevel' => $row->is_top_level,
            'reason' => $row->reason,
        ]);
    }

    private static function buildOaiIdentifier($oaiLocalIdentifier)
    {
        return 'oai:'.QubitOai::getRepositoryIdentifier().'_'.$oaiLocalIdentifier;
    }
}
