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
 * CLI script to restore a backup file into an existing course.
 *
 * This script restores the content of a Moodle backup file (.mbz) into an existing
 * course by merging/importing the backup content into the target course.
 *
 * @package    core
 * @subpackage cli
 * @copyright  2025 MBS Moodle Development
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', 1);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

// Get CLI options.
list($options, $unrecognized) = cli_get_params(
    [
        'courseid' => false,
        'file' => '',
        'showdebugging' => false,
        'help' => false,
    ],
    [
        'c' => 'courseid',
        'f' => 'file',
        's' => 'showdebugging',
        'h' => 'help',
    ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help'] || !$options['courseid'] || empty($options['file'])) {
    $help = <<<EOL
Restore a Moodle backup file (.mbz) into an existing course.

This script merges/imports the content from a backup file into the specified
target course. The existing course content will be preserved, and the backup
content will be added to it.

Options:
-c, --courseid=INT      ID of the target course to restore into (required).
-f, --file=STRING       Path to the backup file (.mbz) (required).
-s, --showdebugging     Show developer level debugging information.
-h, --help              Print out this help.

Example:
\$sudo -u www-data /usr/bin/php admin/cli/restore_to_course.php --courseid=2 --file=/path/to/backup.mbz

EOL;

    echo $help;
    exit(0);
}

if ($options['showdebugging']) {
    set_debugging(DEBUG_DEVELOPER, true);
}

// Get admin user.
$admin = get_admin();
if (!$admin) {
    cli_error('Error: No admin account was found');
}

// Validate course exists.
$courseid = (int) $options['courseid'];
if ($courseid <= 0) {
    cli_error('Error: Invalid course ID provided');
}

$course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
if (!$course) {
    cli_error("Error: Course with ID {$courseid} does not exist");
}

// Validate backup file exists.
$backupfile = $options['file'];
if (!file_exists($backupfile)) {
    cli_error("Error: Backup file not found: {$backupfile}");
}

if (!is_readable($backupfile)) {
    cli_error("Error: Backup file is not readable: {$backupfile}");
}

// Validate file extension.
$pathinfo = pathinfo($backupfile);
if (!isset($pathinfo['extension']) || strtolower($pathinfo['extension']) !== 'mbz') {
    cli_error("Error: Backup file must have .mbz extension");
}

cli_heading("Restoring backup into course: {$course->fullname} (ID: {$courseid})");
mtrace("Backup file: {$backupfile}");
mtrace('');

// Create temporary directory for extraction.
$backupdir = restore_controller::get_tempdir_name($courseid, $admin->id);
$path = make_backup_temp_directory($backupdir);

try {
    // Extract backup file.
    cli_heading('Extracting backup file...');
    mtrace("Extraction path: {$path}");

    $fp = get_file_packer('application/vnd.moodle.backup');
    $result = $fp->extract_to_pathname($backupfile, $path);

    if (!$result) {
        throw new moodle_exception('errorextractingbackup', 'backup');
    }
    mtrace('Backup file extracted successfully');
    mtrace('');

    // Preprocess backup file.
    cli_heading('Preprocessing backup file...');

    // Create restore controller.
    $rc = new restore_controller(
        $backupdir,
        $courseid,
        backup::INTERACTIVE_NO,
        backup::MODE_GENERAL,
        $admin->id,
        backup::TARGET_EXISTING_ADDING
    );

    // Get backup information.
    $backupinfo = $rc->get_info();
    mtrace("Backup type: " . ($rc->get_type() === backup::TYPE_1COURSE ? 'Course' : 'Activity'));
    mtrace("Original course: " . ($backupinfo->original_course_fullname ?? 'N/A'));
    mtrace("Backup date: " . userdate($backupinfo->backup_date));
    mtrace('');

    // Execute precheck.
    cli_heading('Running precheck...');
    if (!$rc->execute_precheck()) {
        $precheckresults = $rc->get_precheck_results();
        if (!empty($precheckresults['errors'])) {
            throw new moodle_exception('errorduringprecheck', 'backup', '', implode("\n", $precheckresults['errors']));
        }
    }
    mtrace('Precheck passed successfully');
    mtrace('');

    // Execute restore plan.
    cli_heading('Executing restore plan...');
    mtrace('This may take a while depending on the backup size...');
    $rc->execute_plan();
    mtrace('Restore completed successfully');
    mtrace('');

    // Clean up.
    $rc->destroy();

    cli_heading('Cleaning up temporary data...');
    fulldelete($path);
    mtrace('Cleanup completed');
    mtrace('');

    // Display success message.
    cli_heading('Restore successful!');
    mtrace("Course ID: {$courseid}");
    mtrace("Course name: {$course->fullname}");
    mtrace("Course URL: " . (new moodle_url('/course/view.php', ['id' => $courseid]))->out());
    mtrace('');

    exit(0);

} catch (Exception $e) {
    // Clean up on error.
    cli_heading('Error occurred - cleaning up temporary data...');
    if (file_exists($path)) {
        fulldelete($path);
    }

    cli_error("Restore failed: " . $e->getMessage());
}

