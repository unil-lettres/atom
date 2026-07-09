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

/*
 * Add persistent OAI-PMH tombstones for deleted records.
 *
 * @package    AccesstoMemory
 * @subpackage migration
 */
class arMigration0198
{
    public const VERSION = 198;
    public const MIN_MILESTONE = 2;

    public function up($configuration)
    {
        $sql = '
            CREATE TABLE IF NOT EXISTS `oai_deleted_record` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `oai_local_identifier` int(11) NOT NULL,
                `oai_identifier` varchar(1024) NOT NULL,
                `metadata_prefix` varchar(32) NOT NULL,
                `datestamp` datetime NOT NULL,
                `set_spec` varchar(1024) DEFAULT NULL,
                `is_top_level` tinyint(1) NOT NULL DEFAULT 0,
                `reason` varchar(32) NOT NULL,
                `active` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` datetime NOT NULL,
                `updated_at` datetime NOT NULL,
                `restored_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `oai_deleted_record_identifier_prefix` (`oai_local_identifier`, `metadata_prefix`),
                KEY `oai_deleted_record_active_datestamp` (`active`, `datestamp`),
                KEY `oai_deleted_record_set_prefix_active` (`set_spec`(255), `metadata_prefix`, `active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
        ';

        QubitPdo::modify($sql);

        return true;
    }
}
