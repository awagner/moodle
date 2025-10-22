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
 * Unit tests for mod/subsection/lib.php.
 *
 * @package    mod_subsection
 * @category   test
 * @copyright  2025 Andreas Wagner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_subsection;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/subsection/lib.php');

/**
 * Unit tests for mod/subsection/lib.php.
 *
 * @package    mod_subsection
 * @category   test
 * @copyright  2025 Andreas Wagner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lib_test extends \advanced_testcase {
    /**
     * Test subsection_delete_instance properly rebuilds course cache.
     *
     * @covers ::subsection_delete_instance
     */
    public function test_subsection_delete_instance_rebuilds_cache(): void {
        global $DB;

        $this->resetAfterTest();

        // Create a course with topics format.
        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 3]);

        // Create multiple subsections.
        $subsection1 = $this->getDataGenerator()->create_module('subsection', [
            'course' => $course->id,
            'section' => 1,
        ]);
        $subsection2 = $this->getDataGenerator()->create_module('subsection', [
            'course' => $course->id,
            'section' => 2,
        ]);

        $oldcoursecacherev = $DB->get_field('course', 'cacherev', ['id' => $course->id]);

        // Get initial course module info.
        $modinfo = get_fast_modinfo($course->id);
        $initialsectioncount = count($modinfo->get_section_info_all());

        // Delete the first subsection.
        $result = subsection_delete_instance($subsection1->id);
        $this->assertTrue($result);

        // Get updated course module info.
        $modinfo = get_fast_modinfo($course->id);
        $updatedsectioncount = count($modinfo->get_section_info_all());

        // Verify the cache was rebuilt and section count decreased.
        $this->assertEquals($initialsectioncount - 1, $updatedsectioncount);

        // Verify that course cacherev has been updated.
        $newcacherev = $DB->get_field('course', 'cacherev', ['id' => $course->id]);
        $this->assertGreaterThan($oldcoursecacherev, $newcacherev);

        // Verify the second subsection still exists.
        $this->assertTrue($DB->record_exists('subsection', ['id' => $subsection2->id]));
        $delegatedsection2 = $modinfo->get_section_info_by_component('mod_subsection', $subsection2->id);
        $this->assertNotNull($delegatedsection2);
    }
}
