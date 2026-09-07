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
 * Admin page: download a reviewable Excel export of Moodle users in
 * TalentLMS's own bulk-import template shape.
 *
 * For admins who just want a one-time move and don't want SFTP or API key
 * setup — they review the file themselves, then upload it by hand via
 * TalentLMS's own Account & Settings > Import-Export screen, which imports
 * synchronously and reports per-row success/errors there. This page's job
 * stops at producing a correct file; it does not attempt to replicate that
 * feedback, and does not preview the spreadsheet's contents in-browser —
 * reviewing it is what the downloaded file itself is for.
 *
 * @package    local_talentlms_bridge
 * @copyright  2026 plugindev sandbox
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_talentlms_bridge\local\role_mapper;
use local_talentlms_bridge\local\user_source;
use local_talentlms_bridge\local\users_csv_exporter;

define('NO_OUTPUT_BUFFERING', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$download = optional_param('download', 0, PARAM_BOOL);

// Capability is already enforced by admin_externalpage_setup() via
// $extpage->check_access(), against what this page was registered with
// (settings.php) — no separate require_capability() needed here.
admin_externalpage_setup('local_talentlms_bridge_export');

$availablecustomfields = users_csv_exporter::available_custom_fields();

if ($download) {
    $filename = clean_filename('talentlms_users_export');
    // Every Moodle user is included, with a User-type column set per
    // role_mapper::map_bulk_user_type() — see users_csv_exporter's docblock.
    $users = user_source::iterate_all();
    $exporter = new users_csv_exporter();
    if ($availablecustomfields) {
        // Present (even if the admin left every box checked) only when
        // there was something to choose from — matches the form below,
        // which is only rendered in that same case.
        $exporter->set_included_custom_field_ids(optional_param_array('customfields', [], PARAM_INT));
    }

    if (optional_param('split', 0, PARAM_BOOL)) {
        // The control for this is only shown on the page once the site has
        // more than users_csv_exporter::MAX_ROWS_PER_FILE users (see
        // local_talentlms_bridge_download_control_html()), but nothing here
        // depends on that — requesting it for a smaller site just yields a
        // one-part zip.
        local_talentlms_bridge_download_split_excel($filename, $exporter, $users);
    } else {
        \core\dataformat::download_data(
            $filename,
            'excel',
            $exporter->header(),
            $users,
            static fn($user, $supportshtml) => $exporter->to_row($user)
        );
    }
    exit;
}

$typecounts = role_mapper::tally_bulk_user_types(user_source::iterate_all());
$usercount = array_sum($typecounts);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('exportusers', 'local_talentlms_bridge'));
echo local_talentlms_bridge_styles();

echo html_writer::tag('p', get_string('exportusers_intro', 'local_talentlms_bridge', $usercount), ['class' => 'tlb-intro']);

echo local_talentlms_bridge_typetable_html($typecounts);

echo local_talentlms_bridge_download_control_html($availablecustomfields, $usercount);

echo local_talentlms_bridge_notes_html((bool) $availablecustomfields);
echo local_talentlms_bridge_walkthrough_html();

echo $OUTPUT->footer();

/**
 * One shared <style> block for everything this page renders below the
 * standard Moodle heading — layout only; table markup/classes come from
 * html_writer::table() (see local_talentlms_bridge_simple_table()).
 *
 * @return string
 */
function local_talentlms_bridge_styles(): string {
    return '<style>
    .tlb-intro { max-width: 640px; }
    .tlb-download-control { margin: 1.5rem 0; }
    .tlb-customfields-heading { font-weight: 600; font-size: 1.05rem; margin: 0 0 0.6rem; }
    .tlb-customfields { max-width: 640px; margin: 0 0 1rem; padding-left: 0; list-style: none; }
    .tlb-customfields li { margin-bottom: 0.4rem; }
    .tlb-customfields input[type="checkbox"] { margin-right: 0.5rem; }
    .tlb-split { margin: 1rem 0 0.25rem; }
    .tlb-split input[type="checkbox"] { margin-right: 0.5rem; }
    .tlb-split-hint { max-width: 640px; margin: 0 0 1rem; font-size: 0.9rem; }
    .tlb-notes-details { max-width: 640px; margin: 1rem 0 1.5rem; }
    .tlb-notes-details summary { cursor: pointer; font-weight: 600; }
    .tlb-notes-details p { margin: 0.75rem 0; }
    .tlb-walkthrough { margin-top: 2rem; max-width: 640px; }
    .tlb-walkthrough h3 { margin-bottom: 1rem; }
    .tlb-step { display: flex; gap: 1rem; margin-bottom: 1.75rem; }
    .tlb-step-number {
        flex: 0 0 auto; width: 2rem; height: 2rem; border-radius: 50%;
        background: #f0f0f0; color: #333; font-weight: bold;
        display: flex; align-items: center; justify-content: center;
    }
    .tlb-step-body p { margin: 0 0 0.5rem; }
    .tlb-step-body img {
        max-width: 100%; border: 1px solid #ddd; border-radius: 4px; margin-top: 0.25rem;
    }
    </style>';
}

/**
 * Build a small two-column html_table — Moodle's own table renderer, rather
 * than hand-written <table> markup — shared by the type-count and
 * role-mapping tables below.
 *
 * @param string $col1 First column header.
 * @param string $col2 Second column header.
 * @param array $rows Rows of [value1, value2], one per html_table_row (or a plain array for a simple row).
 * @param string $cssclass Extra class appended to Moodle's default table classes.
 * @return \html_table
 */
function local_talentlms_bridge_simple_table(string $col1, string $col2, array $rows, string $cssclass): \html_table {
    $table = new \html_table();
    $table->head = [$col1, $col2];
    $table->align = [null, 'right'];
    // Set explicitly, not appended: html_writer::table() only applies its
    // own default classes when this is empty, and we still want those.
    $table->attributes['class'] = 'generaltable ' . $cssclass;
    $table->data = $rows;
    return $table;
}

/**
 * Render the "what's in this export" User-type breakdown table.
 *
 * @param array $typecounts As returned by role_mapper::tally_bulk_user_types() (string type => int count).
 * @return string
 */
function local_talentlms_bridge_typetable_html(array $typecounts): string {
    $rows = [];
    foreach ($typecounts as $type => $count) {
        $rows[] = [$type, $count];
    }

    $totalrow = new \html_table_row([
        get_string('exportusers_typetable_totalrow', 'local_talentlms_bridge'),
        array_sum($typecounts),
    ]);
    $totalrow->attributes['class'] = 'font-weight-bold';
    $rows[] = $totalrow;

    $table = local_talentlms_bridge_simple_table(
        get_string('exportusers_typetable_type', 'local_talentlms_bridge'),
        get_string('exportusers_typetable_count', 'local_talentlms_bridge'),
        $rows,
        'tlb-typetable'
    );

    return html_writer::table($table);
}

/**
 * Render the download control: a plain button if this site has no custom
 * user profile fields and isn't large enough to suggest splitting, or —
 * otherwise — a form with those two optional controls (each only rendered
 * when applicable) and the download button as its submit.
 *
 * The "split into files" checkbox (custom fields aside) is only shown once
 * $usercount exceeds users_csv_exporter::MAX_ROWS_PER_FILE — TalentLMS's
 * recommendation is purely a suggestion for large imports, so there's
 * nothing to offer below that size.
 *
 * @param \stdClass[] $customfields As returned by users_csv_exporter::available_custom_fields().
 * @param int $usercount As returned by role_mapper::tally_bulk_user_types() summed.
 * @return string
 */
function local_talentlms_bridge_download_control_html(array $customfields, int $usercount): string {
    global $OUTPUT;

    $downloadurl = new moodle_url('/local/talentlms_bridge/export.php', ['download' => 1]);
    $showsplit = $usercount > users_csv_exporter::MAX_ROWS_PER_FILE;

    if (!$customfields && !$showsplit) {
        return html_writer::div(
            $OUTPUT->single_button($downloadurl, get_string('exportusers_download', 'local_talentlms_bridge'), 'get'),
            'tlb-download-control'
        );
    }

    $html = html_writer::start_tag('form', ['method' => 'get', 'action' => $downloadurl->out_omit_querystring()]);
    foreach ($downloadurl->params() as $name => $value) {
        $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }

    if ($customfields) {
        $html .= html_writer::tag(
            'p',
            get_string('exportusers_customfields_heading', 'local_talentlms_bridge'),
            ['class' => 'tlb-customfields-heading']
        );
        $html .= html_writer::start_tag('ul', ['class' => 'tlb-customfields']);
        foreach ($customfields as $field) {
            $checkbox = html_writer::checkbox('customfields[]', $field->id, true, $field->name);
            $html .= html_writer::tag('li', $checkbox);
        }
        $html .= html_writer::end_tag('ul');
    }

    if ($showsplit) {
        $maxrows = users_csv_exporter::MAX_ROWS_PER_FILE;
        $html .= html_writer::div(
            html_writer::checkbox('split', 1, true, get_string('exportusers_split_label', 'local_talentlms_bridge', $maxrows)),
            'tlb-split'
        );
        $html .= html_writer::tag(
            'p',
            get_string('exportusers_split_hint', 'local_talentlms_bridge', $maxrows),
            ['class' => 'tlb-split-hint text-muted']
        );
    }

    $html .= html_writer::tag(
        'button',
        get_string('exportusers_download', 'local_talentlms_bridge'),
        ['type' => 'submit', 'class' => 'btn btn-secondary']
    );
    $html .= html_writer::end_tag('form');

    return html_writer::div($html, 'tlb-download-control');
}

/**
 * Build and stream a .zip of multiple users_csv_exporter::MAX_ROWS_PER_FILE
 * -row Excel files for the "split into files" download option — the Excel
 * counterpart of users_csv_exporter::write_batches(), which does the same
 * thing for the CLI/SFTP CSV path. Exits the request via send_temp_file(),
 * same as the single-file \core\dataformat::download_data() path.
 *
 * @param string $basefilename Without extension.
 * @param users_csv_exporter $exporter
 * @param iterable $users
 */
function local_talentlms_bridge_download_split_excel(string $basefilename, users_csv_exporter $exporter, iterable $users): void {
    global $CFG;

    $header = $exporter->header();
    $callback = static fn($user, $supportshtml) => $exporter->to_row($user);

    $archivefiles = [];
    $chunk = [];
    $writechunk = function () use (&$chunk, &$archivefiles, $header, $callback, $basefilename) {
        $part = count($archivefiles) + 1;
        $path = \core\dataformat::write_data($basefilename . '_part' . $part, 'excel', $header, $chunk, $callback);
        $archivefiles[basename($path)] = $path;
        $chunk = [];
    };

    foreach ($users as $user) {
        $chunk[] = $user;
        if (count($chunk) >= users_csv_exporter::MAX_ROWS_PER_FILE) {
            $writechunk();
        }
    }
    if ($chunk || !$archivefiles) {
        $writechunk();
    }

    check_dir_exists($CFG->tempdir . '/zip');
    $ziptarget = tempnam($CFG->tempdir . '/zip', 'tlbexport');
    if (!get_file_packer()->archive_to_pathname($archivefiles, $ziptarget)) {
        @unlink($ziptarget);
        throw new \moodle_exception('error:zipexportfailed', 'local_talentlms_bridge');
    }

    send_temp_file($ziptarget, $basefilename . '.zip');
}

/**
 * Render the "good to know" details below the download button: a
 * Moodle-role -> TalentLMS-type mapping table (same pairing
 * role_mapper::map_bulk_user_type() applies, spelled out for a human reader
 * rather than as inline arrow-separated text), the Login-matching caveat
 * (Note 1), and — only when this site actually has custom fields to export
 * — the must-already-exist-in-TalentLMS caveat (Note 2).
 *
 * @param bool $hascustomfields Whether to include Note 2.
 * @return string
 */
function local_talentlms_bridge_notes_html(bool $hascustomfields): string {
    // Order matches role_mapper::BULK_USER_TYPES (most to least privileged).
    $roletypepairs = [
        [get_string('exportusers_roletable_siteadmin', 'local_talentlms_bridge'), 'SuperAdmin'],
        [get_string('exportusers_roletable_manager', 'local_talentlms_bridge'), 'Admin-Type'],
        [get_string('exportusers_roletable_teacher', 'local_talentlms_bridge'), 'Trainer-Type'],
        [get_string('exportusers_roletable_everyoneelse', 'local_talentlms_bridge'), 'Learner-Type'],
    ];

    $roletable = local_talentlms_bridge_simple_table(
        get_string('exportusers_roletable_moodle', 'local_talentlms_bridge'),
        get_string('exportusers_roletable_talentlms', 'local_talentlms_bridge'),
        $roletypepairs,
        'tlb-roletable'
    );

    $html = '<details class="tlb-notes-details"><summary>'
        . get_string('exportusers_notes_summary', 'local_talentlms_bridge') . '</summary>';
    $html .= '<p>' . get_string('exportusers_note_usertype', 'local_talentlms_bridge') . '</p>';
    $html .= html_writer::table($roletable);
    $html .= '<p>' . get_string('exportusers_note_login', 'local_talentlms_bridge') . '</p>';
    if ($hascustomfields) {
        $html .= '<p>' . get_string('exportusers_note_customfields', 'local_talentlms_bridge') . '</p>';
    }
    $html .= '</details>';

    return $html;
}

/**
 * Render the "how to finish the move in TalentLMS" numbered walkthrough,
 * with real screenshots from a live TalentLMS account. Deliberately plain
 * HTML rather than a template — this is a single static block, not
 * reusable output.
 *
 * @return string
 */
function local_talentlms_bridge_walkthrough_html(): string {
    $pixurl = fn(string $file) => new moodle_url('/local/talentlms_bridge/pix/' . $file);

    $steps = [
        [
            'title' => get_string('walkthrough:step1title', 'local_talentlms_bridge'),
            'body' => get_string('walkthrough:step1body', 'local_talentlms_bridge'),
            'image' => null,
        ],
        [
            'title' => get_string('walkthrough:step2title', 'local_talentlms_bridge'),
            'body' => get_string('walkthrough:step2body', 'local_talentlms_bridge'),
            'image' => $pixurl('step1-navigate.png'),
        ],
        [
            'title' => get_string('walkthrough:step3title', 'local_talentlms_bridge'),
            'body' => get_string('walkthrough:step3body', 'local_talentlms_bridge'),
            'image' => $pixurl('step2-upload.png'),
        ],
        [
            'title' => get_string('walkthrough:step4title', 'local_talentlms_bridge'),
            'body' => get_string('walkthrough:step4body', 'local_talentlms_bridge'),
            'image' => $pixurl('step3-results.png'),
        ],
    ];

    $html = '<div class="tlb-walkthrough"><h3>'
        . get_string('walkthrough:heading', 'local_talentlms_bridge') . '</h3>';

    foreach ($steps as $i => $step) {
        $html .= '<div class="tlb-step">'
            . '<div class="tlb-step-number">' . ($i + 1) . '</div>'
            . '<div class="tlb-step-body">'
            . '<p><strong>' . $step['title'] . '</strong><br>' . $step['body'] . '</p>';
        if ($step['image']) {
            $html .= '<img src="' . $step['image'] . '" alt="' . $step['title'] . '">';
        }
        $html .= '</div></div>';
    }

    $html .= '</div>';

    return $html;
}
