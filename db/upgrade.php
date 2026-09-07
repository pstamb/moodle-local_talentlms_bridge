<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Upgrade script for local_talentlms_bridge.
 *
 * @package   local_talentlms_bridge
 * @copyright 2026 plugindev sandbox
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * @param int $oldversion
 * @return bool always true
 */
function xmldb_local_talentlms_bridge_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026072800) {
        // Define field loginoverride to be added to local_talentlms_bridge_user.
        $table = new xmldb_table('local_talentlms_bridge_user');
        $field = new xmldb_field('loginoverride', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'lasterror');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026072800, 'local', 'talentlms_bridge');
    }

    return true;
}
