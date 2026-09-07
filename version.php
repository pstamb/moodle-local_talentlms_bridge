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
 * Version details.
 *
 * @package    local_talentlms_bridge
 * @copyright  2026 plugindev sandbox
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_talentlms_bridge';
$plugin->version   = 2026090700;
// Floor is Moodle 5.2's branching baseline — the only version this plugin has
// actually been built and tested against so far (see HANDOFF.md). Not a
// claim of compatibility with earlier 5.x or 4.x releases; revisit once a
// real version-matrix is tested.
$plugin->requires  = 2026042000;
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.0.0 (Excel export)';
