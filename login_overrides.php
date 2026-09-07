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
 * Admin page: let an admin record a Moodle user's real TalentLMS login when
 * it differs from their Moodle username — e.g. someone who already had a
 * TalentLMS account before this plugin existed. Only the Excel/CSV bulk
 * path (users_csv_exporter) needs this: TalentLMS's bulk importer rejects a
 * mismatched-login row as a duplicate email rather than updating it. The
 * REST path doesn't need it — it already resolves the same mismatch itself
 * via create-or-adopt-on-duplicate-email (see user_exporter).
 *
 * @package    local_talentlms_bridge
 * @copyright  2026 plugindev sandbox
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_talentlms_bridge\local\user_mapping;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_talentlms_bridge_login_overrides');

$errors = [];
$saved = 0;

if (optional_param('save', 0, PARAM_BOOL)) {
    require_sesskey();

    $text = optional_param('overrides', '', PARAM_RAW_TRIMMED);
    $parsed = user_mapping::parse_override_lines($text);

    foreach ($parsed['malformed'] as $line) {
        $errors[] = get_string('loginoverrides_error_malformed', 'local_talentlms_bridge', $line);
    }

    if ($parsed['pairs']) {
        $matched = $DB->get_records_list('user', 'username', array_keys($parsed['pairs']), '', 'id, username');
        $byusername = [];
        foreach ($matched as $record) {
            $byusername[$record->username] = $record->id;
        }

        foreach ($parsed['pairs'] as $username => $login) {
            if (!isset($byusername[$username])) {
                $errors[] = get_string('loginoverrides_error_unknownuser', 'local_talentlms_bridge', $username);
                continue;
            }
            user_mapping::set_login_override((int) $byusername[$username], $login);
            $saved++;
        }
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('loginoverrides', 'local_talentlms_bridge'));

echo html_writer::tag('p', get_string('loginoverrides_intro', 'local_talentlms_bridge'));

if ($saved > 0) {
    echo $OUTPUT->notification(get_string('loginoverrides_saved', 'local_talentlms_bridge', $saved), 'notifysuccess');
}
foreach ($errors as $error) {
    echo $OUTPUT->notification($error, 'notifyproblem');
}

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'save', 'value' => 1]);
echo html_writer::tag(
    'textarea',
    s(user_mapping::format_overrides_as_text()),
    [
        'name' => 'overrides', 'rows' => 12, 'cols' => 60, 'class' => 'form-control',
        'style' => 'max-width: 640px; font-family: monospace;',
    ]
);
echo html_writer::div(
    html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary']),
    '',
    ['style' => 'margin-top: 0.75rem;']
);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
