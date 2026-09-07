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
 * Admin settings.
 *
 * @package    local_talentlms_bridge
 * @copyright  2026 plugindev sandbox
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// local/talentlms_bridge:managesync is granted to the manager archetype, not
// just full site:config admins, and admin_externalpage checks its own
// capability — so these stay visible to managers who lack site:config.
$ADMIN->add('localplugins', new admin_externalpage(
    'local_talentlms_bridge_export',
    get_string('exportusers', 'local_talentlms_bridge'),
    new moodle_url('/local/talentlms_bridge/export.php'),
    'local/talentlms_bridge:managesync'
));

$ADMIN->add('localplugins', new admin_externalpage(
    'local_talentlms_bridge_login_overrides',
    get_string('loginoverrides', 'local_talentlms_bridge'),
    new moodle_url('/local/talentlms_bridge/login_overrides.php'),
    'local/talentlms_bridge:managesync'
));
