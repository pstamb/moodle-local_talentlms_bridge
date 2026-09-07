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
 * Language strings.
 *
 * @package    local_talentlms_bridge
 * @copyright  2026 plugindev sandbox
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['error:exportpathnotwritable'] = 'CSV export directory "{$a}" does not exist or is not writable.';
$string['error:zipexportfailed'] = 'Could not build the split export .zip file.';
$string['exportusers'] = 'Export users to TalentLMS (Excel)';
$string['exportusers_customfields_heading'] = 'Custom fields';
$string['exportusers_download'] = 'Download Excel export';
$string['exportusers_intro'] = 'Download your {$a} Moodle user(s) as an Excel file, ready for TalentLMS\'s bulk import. Review it, then upload it in TalentLMS to create or update these accounts.';
$string['exportusers_note_customfields'] = 'Note 2: Custom fields must already exist in TalentLMS (Account & Settings > Users > Custom user fields) with matching names before you import — otherwise that column is silently ignored, not created.';
$string['exportusers_note_login'] = 'Note 1: TalentLMS matches existing accounts by username (Login). One exception: if someone already has a TalentLMS account under a different username than their Moodle one, TalentLMS treats them as a new user and rejects the row (duplicate email) instead of updating them.';
$string['exportusers_note_usertype'] = 'Each user\'s TalentLMS type (shown in the table above) is set automatically from their Moodle role:';
$string['exportusers_notes_summary'] = 'Details';
$string['exportusers_roletable_everyoneelse'] = 'Everyone else';
$string['exportusers_roletable_manager'] = 'Manager';
$string['exportusers_roletable_moodle'] = 'Moodle role';
$string['exportusers_roletable_siteadmin'] = 'Site admin';
$string['exportusers_roletable_talentlms'] = 'TalentLMS type';
$string['exportusers_roletable_teacher'] = 'Teacher';
$string['exportusers_split_hint'] = 'TalentLMS recommends keeping bulk-import files to {$a} rows or fewer. When checked, the download is a .zip of multiple part files instead of a single .xlsx.';
$string['exportusers_split_label'] = 'Split into files of {$a} rows or fewer';
$string['exportusers_typetable_count'] = 'Users';
$string['exportusers_typetable_totalrow'] = 'Total';
$string['exportusers_typetable_type'] = 'TalentLMS type';
$string['loginoverrides'] = 'TalentLMS login overrides';
$string['loginoverrides_error_malformed'] = 'Ignored malformed line (expected "username = login"): {$a}';
$string['loginoverrides_error_unknownuser'] = 'Ignored override for unknown Moodle username: {$a}';
$string['loginoverrides_intro'] = 'If someone already has a TalentLMS account under a different login than their Moodle username, list them here as "username = login", one pair per line, so the Excel/CSV export uses their real TalentLMS login instead of their Moodle username.';
$string['loginoverrides_saved'] = 'Saved {$a} login override(s).';
$string['pluginname'] = 'TalentLMS bridge';
$string['privacy:metadata:local_talentlms_bridge_user'] = 'One row per Moodle user this plugin has touched, either via a TalentLMS sync or a login override.';
$string['privacy:metadata:local_talentlms_bridge_user:lasterror'] = 'The last REST sync error message for this user, if any.';
$string['privacy:metadata:local_talentlms_bridge_user:loginoverride'] = 'The real TalentLMS login for this user, when it differs from their Moodle username.';
$string['privacy:metadata:local_talentlms_bridge_user:status'] = 'The REST sync status of this user (pending, synced or error).';
$string['privacy:metadata:local_talentlms_bridge_user:talentlmsuserid'] = 'The matching TalentLMS user ID, once a REST sync has created or matched the account.';
$string['privacy:metadata:local_talentlms_bridge_user:timecreated'] = 'The time this row was created.';
$string['privacy:metadata:local_talentlms_bridge_user:timemodified'] = 'The time this row was last modified.';
$string['privacy:metadata:local_talentlms_bridge_user:userid'] = 'The ID of the Moodle user this row is about.';
$string['talentlms_bridge:managesync'] = 'Trigger and manage TalentLMS synchronisation';
$string['walkthrough:heading'] = 'How to finish the move in TalentLMS';
$string['walkthrough:step1body'] = 'Click "Download Excel export" above and open the file. Each row is one user — check it looks right before uploading.';
$string['walkthrough:step1title'] = 'Download and review';
$string['walkthrough:step2body'] = 'In TalentLMS, go to Account & Settings, then Import-Export.';
$string['walkthrough:step2title'] = 'Go to Import-Export in TalentLMS';
$string['walkthrough:step3body'] = 'Drag the downloaded file into the Import box, or click it to browse for the file.';
$string['walkthrough:step3title'] = 'Upload the file';
$string['walkthrough:step4body'] = 'TalentLMS imports it immediately and shows a result for every user in a panel on the right — matching logins are updated, new ones are created.';
$string['walkthrough:step4title'] = 'Check the results';
