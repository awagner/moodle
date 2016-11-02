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
 * Test for renames roles restore.
 *
 * @package core_backup
 * @copyright 2016 Andreas Wagner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Test for renames roles restore.
 *
 * @package core_backup
 * @copyright 2016 Andreas Wagner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_backup_moodle2_rename_roles_testcase extends advanced_testcase {

    /**
     * Backs a course up and restores it.
     *
     * @param stdClass $srccourse Course object to backup
     * @param stdClass $dstcourse Course object to restore into
     * @param int $target Target course mode (backup::TARGET_xx)
     * @return int ID of newly restored course
     */
    protected function backup_and_restore($srccourse, $dstcourse = null, $target = backup::TARGET_NEW_COURSE) {
        global $USER, $CFG;

        // Turn off file logging, otherwise it can't delete the file (Windows).
        $CFG->backup_file_logger_level = backup::LOG_NONE;

        // Do backup with default settings. MODE_IMPORT means it will just
        // create the directory and not zip it.
        $bc = new backup_controller(backup::TYPE_1COURSE, $srccourse->id, backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO, backup::MODE_IMPORT, $USER->id);

        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        // Do restore to new course with default settings.
        if ($dstcourse !== null) {
            $newcourseid = $dstcourse->id;
        } else {
            $newcourseid = restore_dbops::create_new_course(
                    $srccourse->fullname, $srccourse->shortname . '_2',
                    $srccourse->category);
        }
        $rc = new restore_controller($backupid, $newcourseid,
            backup::INTERACTIVE_NO, backup::MODE_GENERAL, $USER->id, $target);

        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return $newcourseid;
    }

    public function test_rename_roles_restore() {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Create a course with some availability data set.
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();

        $enrol = enrol_get_plugin('manual');
        $enrolinstances = $DB->get_records('enrol',
            array('enrol' => 'manual', 'courseid' => $course->id,
            'status' => ENROL_INSTANCE_ENABLED), 'sortorder,id ASC');
        $enrolinstance = reset($enrolinstances);

        $student1 = $this->getDataGenerator()->create_user();
        $teacher1 = $this->getDataGenerator()->create_user();

        // Check with 3 students enrolled and 1 teacher.
        $enrol->enrol_user($enrolinstance, $student1->id, 3);
        $enrol->enrol_user($enrolinstance, $teacher1->id, 5);

        $coursecontext = context_course::instance($course->id);
        // Get Roles and rename it.
        $roles = $DB->get_records('role');
        foreach ($roles as $role) {

            $rolename = new \stdClass();
            $rolename->roleid = $role->id;
            $rolename->contextid = $coursecontext->id;
            $rolename->name = $role->shortname . '_new';
            $DB->insert_record('role_names', $rolename);
        }

        // Backup and restore it.
        $newcourseid = $this->backup_and_restore($course);
        $newcoursecontext = context_course::instance($newcourseid);

        $newrolenames = $DB->get_records('role_names',
            array('contextid' => $newcoursecontext->id));
        $this->assertEquals(count($roles), count($newrolenames));
    }

}
