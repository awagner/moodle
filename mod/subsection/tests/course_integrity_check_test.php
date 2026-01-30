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

namespace mod_subsection;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Tests for course_integrity_check() function to prevent nested subsections.
 *
 * This test class verifies that the course_integrity_check() function correctly
 * prevents nested subsections (subsection modules inside subsection sections)
 * and ensures the course has a valid data structure after the check.
 *
 * @package    mod_subsection
 * @copyright  Andreas Wagner (mebis-lp)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::course_integrity_check
 */
final class course_integrity_check_test extends \advanced_testcase {
    /**
     * Set up test environment.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Get nested subsection modules using section reference.
     *
     * This finds subsection modules where cm.section references a subsection-section.
     *
     * @param int $courseid Optional course ID to filter by
     * @return array Array of nested subsection records
     * @throws \dml_exception
     */
    public function get_nested_subsections_by_section(int $courseid = 0): array {
        global $DB;

        $sql = "SELECT cm.id AS cmid, cm.course, cm.instance, cm.section,
                       parentsec.id AS parentsecid
                  FROM {course_modules} cm
                  JOIN {modules} m ON cm.module = m.id AND m.name = 'subsection'
                  JOIN {subsection} sub ON cm.instance = sub.id
                  JOIN {course} c ON c.id = cm.course
                  JOIN {course_sections} parentsec ON parentsec.course = c.id
                       AND parentsec.component = 'mod_subsection'
                 WHERE cm.section = parentsec.id";

        $params = [];
        if ($courseid > 0) {
            $sql .= " AND cm.course = :courseid";
            $params['courseid'] = $courseid;
        }

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Get nested subsection modules using sequence (Fall 2).
     *
     * This finds subsection modules where the cmid is in the sequence of a subsection-section.
     * This is slower but catches cases where cm.section might be inconsistent.
     *
     * @param int $courseid Optional course ID to filter by
     * @return array Array of nested subsection records
     * @throws \dml_exception
     */
    public function get_nested_subsections_by_sequence(int $courseid = 0): array {
        global $DB;

        $sql = "SELECT cm.id AS cmid, cm.course, cm.instance, cm.section,
                       parentsec.id AS parentsecid
                  FROM {course_modules} cm
                  JOIN {modules} m ON cm.module = m.id AND m.name = 'subsection'
                  JOIN {subsection} sub ON cm.instance = sub.id
                  JOIN {course} c ON c.id = cm.course
                  JOIN {course_sections} parentsec ON parentsec.course = c.id
                       AND parentsec.component = 'mod_subsection'
                 WHERE CONCAT(',', parentsec.sequence, ',') LIKE CONCAT('%,',cm.id,',%')";

        $params = [];
        if ($courseid > 0) {
            $sql .= " AND cm.course = :courseid";
            $params['courseid'] = $courseid;
        }
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Helper method to verify that no nested subsections exist in the course.
     *
     * A nested subsection occurs when a subsection module (course_modules entry
     * with module type 'subsection') is contained in a subsection section
     * (course_sections entry with component = 'mod_subsection').
     *
     * @param int $courseid The course ID to check.
     * @return void
     */
    private function assert_no_nested_subsections(int $courseid): void {

        $nestedsubsections = $this->get_nested_subsections_by_section($courseid);
        $this->assertEmpty(
            $nestedsubsections,
            'Found nested subsections: subsection module(s) inside subsection section(s)'
        );

        $nestedsubsections = $this->get_nested_subsections_by_sequence($courseid);
        $this->assertEmpty(
            $nestedsubsections,
            'Found nested subsections: subsection module(s) inside subsection section(s)'
        );
    }

    /**
     * Helper method to verify that the course data structure is consistent.
     *
     * Checks:
     * 1. All modules in sequences exist in course_modules.
     * 2. All course_modules.section values point to existing sections.
     * 3. All modules are in their section's sequence.
     *
     * @param int $courseid The course ID to check.
     * @return void
     */
    private function assert_course_structure_valid(int $courseid): void {
        global $DB;

        $sections = $DB->get_records('course_sections', ['course' => $courseid]);
        $modules = $DB->get_records('course_modules', ['course' => $courseid], '', 'id, section');

        foreach ($sections as $section) {
            if (empty($section->sequence)) {
                continue;
            }

            $cmids = explode(',', $section->sequence);
            foreach ($cmids as $cmid) {
                // Check that each module in sequence exists.
                $this->assertArrayHasKey(
                    (int) $cmid,
                    $modules,
                    "Module {$cmid} in sequence of section {$section->id} does not exist"
                );

                // Check that module points back to this section.
                $this->assertEquals(
                    $section->id,
                    $modules[(int) $cmid]->section,
                    "Module {$cmid} points to section {$modules[(int)$cmid]->section} but is in sequence of section {$section->id}"
                );
            }
        }

        // Check that all modules are in some sequence.
        foreach ($modules as $module) {
            $this->assertArrayHasKey(
                $module->section,
                $sections,
                "Module {$module->id} points to non-existent section {$module->section}"
            );

            $sequence = $sections[$module->section]->sequence ?? '';
            $cmids = !empty($sequence) ? explode(',', $sequence) : [];
            $this->assertContains(
                (string) $module->id,
                $cmids,
                "Module {$module->id} is not in sequence of its section {$module->section}"
            );
        }
    }

    /**
     * Test scenario 1: Subsection module appears in two sequences (normal section + subsection section).
     *
     * This test verifies that when a subsection module is incorrectly placed in both
     * a normal parent section and a subsection section (duplicate in sequences),
     * course_integrity_check() removes it from the subsection section and keeps it
     * in the parent section.
     */
    public function test_prevents_nested_subsection_duplicate_in_sequences(): void {
        global $DB;

        // Create course with sections.
        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 2]);

        // Create a subsection module in section 1.
        $subsection = $this->getDataGenerator()->create_module(
            'subsection',
            (object) ['course' => $course->id, 'section' => 1]
        );

        // Create a subsection module in section 1.
        $subsection2 = $this->getDataGenerator()->create_module(
            'subsection',
            (object) ['course' => $course->id, 'section' => 1]
        );

        // Get the subsection's delegated section (component = 'mod_subsection').
        $subsectionsection2 = $DB->get_record('course_sections', [
            'course' => $course->id,
            'component' => 'mod_subsection',
            'itemid' => $subsection2->id,
        ]);

        // Get the parent section (section 1).
        $parentsection = $DB->get_record('course_sections', [
            'course' => $course->id,
            'section' => 1,
        ]);

        // Verify initial state is correct.
        $this->assertStringContainsString(',' . $subsection->cmid . ',', ',' . $parentsection->sequence . ',');
        $this->assertEmpty($subsectionsection2->sequence);

        // Corrupt the data: Add subsection module to subsection2 section's sequence.
        $subsectionsection2->sequence = (string) $subsection->cmid;
        $DB->update_record('course_sections', $subsectionsection2);

        // Run integrity check.
        $messages = course_integrity_check($course->id);

        // Verify that messages indicate the fix was applied.
        $this->assertNotEmpty($messages);
        $this->assertTrue(
            (bool) preg_grep('/must be removed from sequence of subsection section/', $messages),
            'Expected message about removing subsection module from subsection section. Got: ' . implode(', ', $messages)
        );

        // Reload sections from DB.
        $parentsection = $DB->get_record('course_sections', ['id' => $parentsection->id]);
        $subsectionsection2 = $DB->get_record('course_sections', ['id' => $subsectionsection2->id]);
        $cm = $DB->get_record('course_modules', ['id' => $subsection->cmid]);

        // Verify: Subsection module should remain in parent section, not in subsection section.
        $this->assertStringContainsString(',' . $subsection->cmid . ',', ',' . $parentsection->sequence . ',');
        $this->assertStringNotContainsString(',' . $subsection->cmid, ',' . $subsectionsection2->sequence . ',');
        $this->assertEquals($parentsection->id, $cm->section);

        // Verify no nested subsections and valid structure.
        $this->assert_no_nested_subsections($course->id);
        $this->assert_course_structure_valid($course->id);
    }

    /**
     * Test scenario 2: Orphaned subsection module where $mod->section points to existing subsection section.
     *
     * This test verifies that when an orphaned subsection module (not in any sequence)
     * has its course_modules.section pointing to a subsection section,
     * course_integrity_check() places it in a non-delegated section instead.
     */
    public function test_prevents_nested_subsection_orphan_pointing_to_subsection(): void {
        global $DB;

        // Create course with sections.
        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 2]);

        // Create two subsection modules in section 1.
        $subsectiona = $this->getDataGenerator()->create_module(
            'subsection',
            (object) ['course' => $course->id, 'section' => 1]
        );
        $subsectionb = $this->getDataGenerator()->create_module(
            'subsection',
            (object) ['course' => $course->id, 'section' => 1]
        );

        // Get subsection A's delegated section.
        $subsectionsectiona = $DB->get_record('course_sections', [
            'course' => $course->id,
            'component' => 'mod_subsection',
            'itemid' => $subsectiona->id,
        ]);

        // Get the parent section (section 1).
        $parentsection = $DB->get_record('course_sections', [
            'course' => $course->id,
            'section' => 1,
        ]);

        // Corrupt the data:
        // 1. Remove subsection B from parent section's sequence (make it orphaned).
        $parentsection->sequence = (string) $subsectiona->cmid;
        $DB->update_record('course_sections', $parentsection);

        // 2. Point subsection B's course_modules.section to subsection A's section (would create nesting).
        $DB->update_record('course_modules', (object) [
            'id' => $subsectionb->cmid,
            'section' => $subsectionsectiona->id,
        ]);

        // Run integrity check with fullcheck=true (required for orphan detection).
        $messages = course_integrity_check($course->id, null, null, true);

        // Verify that messages were generated.
        $this->assertNotEmpty($messages);

        // Reload data from DB.
        $cm = $DB->get_record('course_modules', ['id' => $subsectionb->cmid]);
        $subsectionsectiona = $DB->get_record('course_sections', ['id' => $subsectionsectiona->id]);

        // Verify: Orphaned subsection module should NOT be in subsection section.
        $this->assertStringNotContainsString(',' . $subsectionb->cmid . ',', ',' . $subsectionsectiona->sequence . ',');

        // Verify: It should be placed in a non-delegated section.
        $nondelegatedsection = $DB->get_record('course_sections', ['id' => $cm->section]);
        $this->assertNull($nondelegatedsection->component);

        // Verify no nested subsections and valid structure.
        $this->assert_no_nested_subsections($course->id);
        $this->assert_course_structure_valid($course->id);
    }

    /**
     * Test scenario 3: Orphaned subsection module where $mod->section points to non-existent section.
     *
     * This test verifies that when an orphaned subsection module has its
     * course_modules.section pointing to a non-existent section,
     * course_integrity_check() places it in a non-delegated section.
     */
    public function test_prevents_nested_subsection_orphan_invalid_section(): void {
        global $DB;

        // Create course with sections.
        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 2]);

        // Create a subsection module in section 1.
        $subsection = $this->getDataGenerator()->create_module(
            'subsection',
            (object) ['course' => $course->id, 'section' => 1]
        );

        // Get the parent section (section 1).
        $parentsection = $DB->get_record('course_sections', [
            'course' => $course->id,
            'section' => 1,
        ]);

        // Corrupt the data:
        // 1. Remove subsection from parent section's sequence (make it orphaned).
        $parentsection->sequence = '';
        $DB->update_record('course_sections', $parentsection);

        // 2. Point course_modules.section to a non-existent section ID.
        $DB->update_record('course_modules', (object) [
            'id' => $subsection->cmid,
            'section' => 99999,
        ]);

        // Run integrity check with fullcheck=true.
        $messages = course_integrity_check($course->id, null, null, true);

        // Verify that messages were generated.
        $this->assertNotEmpty($messages);
        $this->assertTrue(
            (bool) preg_grep('/is missing from sequence of section/', $messages),
            'Expected message about missing module from sequence. Got: ' . implode(', ', $messages)
        );

        // Reload data from DB.
        $cm = $DB->get_record('course_modules', ['id' => $subsection->cmid]);

        // Verify: Module should be placed in a non-delegated section.
        $targetsection = $DB->get_record('course_sections', ['id' => $cm->section]);
        $this->assertNull($targetsection->component);

        // Verify: Module should be in the target section's sequence.
        $this->assertStringContainsString(',' . $subsection->cmid . ',', ',' . $targetsection->sequence . ',');

        // Verify no nested subsections and valid structure.
        $this->assert_no_nested_subsections($course->id);
        $this->assert_course_structure_valid($course->id);
    }

    /**
     * Test scenario 4: Subsection section has lowest section number and orphaned subsection module exists.
     *
     * This test verifies that when a subsection section has the lowest section number
     * (e.g., section=-100 or section=0), orphaned subsection modules are NOT placed
     * into this subsection section, but into a non-delegated section instead.
     *
     * This is critical because course_integrity_check() uses reset($nondelegatedsections)
     * to find a fallback section, and if the sections are sorted incorrectly or a
     * subsection section has a very low section number, it could be selected erroneously.
     */
    public function test_prevents_nested_subsection_when_subsection_has_lowest_section_number(): void {
        global $DB;

        // Create course with sections.
        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 2]);

        // Create two subsection modules in section 1.
        $subsectiona = $this->getDataGenerator()->create_module(
            'subsection',
            (object) ['course' => $course->id, 'section' => 1]
        );
        $subsectionb = $this->getDataGenerator()->create_module(
            'subsection',
            (object) ['course' => $course->id, 'section' => 1]
        );

        // Get subsection A's delegated section.
        $subsectionsectiona = $DB->get_record('course_sections', [
            'course' => $course->id,
            'component' => 'mod_subsection',
            'itemid' => $subsectiona->id,
        ]);

        // Get the parent section (section 1).
        $parentsection = $DB->get_record('course_sections', [
            'course' => $course->id,
            'section' => 1,
        ]);

        // Get section 0.
        $section0 = $DB->get_record('course_sections', [
            'course' => $course->id,
            'section' => 0,
        ]);

        // Corrupt the data:
        // 1. Set subsection A's section number to -100 (lowest possible, will be first in sorted order).
        $subsectionsectiona->section = -100;
        $DB->update_record('course_sections', $subsectionsectiona);

        // 2. Remove subsection B from parent section's sequence (make it orphaned).
        $parentsection->sequence = (string) $subsectiona->cmid;
        $DB->update_record('course_sections', $parentsection);

        // 3. Point subsection B's course_modules.section to a non-existent section (orphaned).
        $DB->update_record('course_modules', (object) [
            'id' => $subsectionb->cmid,
            'section' => 99999,
        ]);

        // Run integrity check with fullcheck=true (required for orphan detection).
        $messages = course_integrity_check($course->id, null, null, true);

        // Verify that messages were generated.
        $this->assertNotEmpty($messages);

        // Reload data from DB.
        $cm = $DB->get_record('course_modules', ['id' => $subsectionb->cmid]);
        $subsectionsectiona = $DB->get_record('course_sections', ['id' => $subsectionsectiona->id]);

        // Verify: Orphaned subsection module should NOT be in subsection section (even with section=-100).
        $this->assertStringNotContainsString(
            ',' . $subsectionb->cmid . ',',
            ',' . $subsectionsectiona->sequence . ',',
            'Subsection module was incorrectly placed in subsection section with lowest section number'
        );

        // Verify: It should NOT point to the subsection section.
        $this->assertNotEquals(
            $subsectionsectiona->id,
            $cm->section,
            'Subsection module section reference points to subsection section'
        );

        // Verify: It should be placed in a non-delegated section.
        $nondelegatedsection = $DB->get_record('course_sections', ['id' => $cm->section]);
        $this->assertNull(
            $nondelegatedsection->component,
            'Orphaned subsection module was not placed in a non-delegated section'
        );

        // Verify no nested subsections and valid structure.
        $this->assert_no_nested_subsections($course->id);
        $this->assert_course_structure_valid($course->id);
    }

    /**
     * Test scenario 5: Confirms that nested subsections WOULD NOT occur with new implementation.
     *
     * This test demonstrates the problem that exists when:
     * - A subsection section has the lowest section number (e.g., section=-100)
     * - A subsection module is orphaned (not in any sequence, points to non-existent section)
     * - The original course_integrity_check() uses reset($sections) which returns
     *   the first section in the sorted array (the one with lowest section number)
     *
     * Without the fix, the orphaned subsection module would be placed into the
     * subsection section, creating an invalid nested subsection structure.
     */
    public function test_confirms_nested_subsection_would_not_occur_with_new_implementation(): void {
        global $DB;

        // Create course with sections.
        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 2]);

        // Create two subsection modules in section 1.
        $subsectiona = $this->getDataGenerator()->create_module(
            'subsection',
            (object) ['course' => $course->id, 'section' => 1]
        );
        $subsectionb = $this->getDataGenerator()->create_module(
            'subsection',
            (object) ['course' => $course->id, 'section' => 1]
        );

        // Get subsection A's delegated section.
        $subsectionsectiona = $DB->get_record('course_sections', [
            'course' => $course->id,
            'component' => 'mod_subsection',
            'itemid' => $subsectiona->id,
        ]);

        // Get the parent section (section 1).
        $parentsection = $DB->get_record('course_sections', [
            'course' => $course->id,
            'section' => 1,
        ]);

        // Corrupt the data:
        // 1. Set subsection A's section number to -100 (lowest possible).
        $subsectionsectiona->section = -100;
        $DB->update_record('course_sections', $subsectionsectiona);

        // 2. Remove subsection B from parent section's sequence (make it orphaned).
        $parentsection->sequence = (string) $subsectiona->cmid;
        $DB->update_record('course_sections', $parentsection);

        // 3. Point subsection B's course_modules.section to a non-existent section.
        $DB->update_record('course_modules', (object) [
            'id' => $subsectionb->cmid,
            'section' => 99999,
        ]);

        // Simulate the ORIGINAL algorithm behavior.
        course_integrity_check($course->id, null, null, true);

        $nested = $this->get_nested_subsections_by_section($course->id);
        $this->assertCount(0, $nested);

        $nested = $this->get_nested_subsections_by_sequence($course->id);
        $this->assertCount(0, $nested);
    }

    /**
     * Test scenario 6: Confirms that nested subsections WOULD NOT occur with new implementation
     * when subsection module appears in both normal section and subsection section sequences
     * (Last Section Wins behavior is overridden for subsection modules).
     *
     * This test demonstrates that the fix prevents nested subsections when:
     * - A subsection module is in the sequence of a normal parent section
     * - The same subsection module is also in the sequence of a subsection section
     * - The subsection section has a higher section number (would be processed later)
     * - The new course_integrity_check() detects this and removes it from subsection section
     */
    public function test_confirms_nested_subsection_would_not_occur_with_new_last_section_wins(): void {
        global $DB;

        // Create course with sections.
        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 2]);

        // Create a subsection module in section 1.
        $subsection = $this->getDataGenerator()->create_module(
            'subsection',
            (object) ['course' => $course->id, 'section' => 1]
        );

        // Get the subsection's delegated section (component = 'mod_subsection').
        $subsectionsection = $DB->get_record('course_sections', [
            'course' => $course->id,
            'component' => 'mod_subsection',
            'itemid' => $subsection->id,
        ]);

        // Get the parent section (section 1).
        $parentsection = $DB->get_record('course_sections', [
            'course' => $course->id,
            'section' => 1,
        ]);

        // Verify initial state is correct.
        $this->assertStringContainsString(',' . $subsection->cmid . ',', ',' . $parentsection->sequence . ',');
        $this->assertEmpty($subsectionsection->sequence);

        // Corrupt the data: Add subsection module to its own subsection section's sequence (duplicate).
        // The subsection section has a higher section number, so it would be processed LATER.
        $this->assertGreaterThan(
            $parentsection->section,
            $subsectionsection->section,
            'Subsection section should have higher section number than parent section'
        );

        $subsectionsection->sequence = (string) $subsection->cmid;
        $DB->update_record('course_sections', $subsectionsection);

        // Run the NEW integrity check (with the fix).
        $messages = course_integrity_check($course->id);

        // Verify that messages indicate the fix was applied.
        $this->assertNotEmpty($messages);
        $this->assertTrue(
            (bool) preg_grep('/must be removed from sequence of subsection section/', $messages),
            'Expected message about removing subsection module from subsection section. Got: ' . implode(', ', $messages)
        );

        // Verify that NO nested subsection was created.
        $nested = $this->get_nested_subsections_by_section($course->id);
        $this->assertCount(
            0,
            $nested,
            'New implementation should NOT create nested subsection'
        );

        $nested = $this->get_nested_subsections_by_sequence($course->id);
        $this->assertCount(
            0,
            $nested,
            'New implementation should NOT create nested subsection in sequence'
        );

        // Verify the subsection module is in the parent section.
        $parentsection = $DB->get_record('course_sections', ['id' => $parentsection->id]);
        $subsectionsection = $DB->get_record('course_sections', ['id' => $subsectionsection->id]);
        $cm = $DB->get_record('course_modules', ['id' => $subsection->cmid]);

        $this->assertStringContainsString(',' . $subsection->cmid . ',', ',' . $parentsection->sequence . ',');
        $this->assertStringNotContainsString(',' . $subsection->cmid . ',', ',' . $subsectionsection->sequence . ',');
        $this->assertEquals($parentsection->id, $cm->section);

        // Verify course structure is valid.
        $this->assert_no_nested_subsections($course->id);
        $this->assert_course_structure_valid($course->id);
    }

    /**
     * Test scenario 7: Confirms that a nested subsection is corrected.
     *
     * This test verifies the complete correction of a nested subsection where:
     * - A subsection module exists ONLY in a subsection section's sequence (not in any parent section)
     * - The course_modules.section also points to the subsection section
     * - After course_integrity_check(), the nested subsection is removed from the subsection section
     * - The subsection module is moved to a non-delegated section (section 0)
     *
     * This is the most severe case of nested subsection corruption where the module
     * has no valid parent section reference at all.
     */
    public function test_nested_subsection_is_corrected(): void {
        global $DB;

        // Create course with sections.
        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 2]);

        // Create two subsection modules in section 1.
        $subsectiona = $this->getDataGenerator()->create_module(
            'subsection',
            (object) ['course' => $course->id, 'section' => 1]
        );
        $subsectionb = $this->getDataGenerator()->create_module(
            'subsection',
            (object) ['course' => $course->id, 'section' => 1]
        );

        // Get subsection A's delegated section.
        $subsectionsectiona = $DB->get_record('course_sections', [
            'course' => $course->id,
            'component' => 'mod_subsection',
            'itemid' => $subsectiona->id,
        ]);

        // Get the parent section (section 1).
        $parentsection = $DB->get_record('course_sections', [
            'course' => $course->id,
            'section' => 1,
        ]);

        // Get section 0 (will be the fallback for orphaned modules).
        $section0 = $DB->get_record('course_sections', [
            'course' => $course->id,
            'section' => 0,
        ]);

        // Verify initial state is correct: both subsections are in parent section's sequence.
        $this->assertStringContainsString(',' . $subsectiona->cmid . ',', ',' . $parentsection->sequence . ',');
        $this->assertStringContainsString(',' . $subsectionb->cmid . ',', ',' . $parentsection->sequence . ',');
        $this->assertEmpty($subsectionsectiona->sequence);

        // Corrupt the data to create a nested subsection:
        // 1. Remove subsection B from parent section's sequence.
        $parentsection->sequence = (string) $subsectiona->cmid;
        $DB->update_record('course_sections', $parentsection);

        // 2. Add subsection B to subsection A's delegated section sequence.
        $subsectionsectiona->sequence = (string) $subsectionb->cmid;
        $DB->update_record('course_sections', $subsectionsectiona);

        // 3. Point subsection B's course_modules.section to subsection A's section.
        $DB->update_record('course_modules', (object) [
            'id' => $subsectionb->cmid,
            'section' => $subsectionsectiona->id,
        ]);

        // Verify the corruption is in place (nested subsection exists).
        $nestedbefore = $this->get_nested_subsections_by_section($course->id);
        $this->assertCount(
            1,
            $nestedbefore,
            'Expected exactly one nested subsection before correction'
        );
        $this->assertEquals($subsectionb->cmid, reset($nestedbefore)->cmid);

        $nestedbysequencebefore = $this->get_nested_subsections_by_sequence($course->id);
        $this->assertCount(
            1,
            $nestedbysequencebefore,
            'Expected exactly one nested subsection in sequence before correction'
        );

        // Run integrity check with fullcheck=true.
        $messages = course_integrity_check($course->id, null, null, true);

        // Check for the specific message about removing from subsection section.
        $this->assertTrue(
            (bool) preg_grep('/must be removed from sequence of subsection section/', $messages),
            'Expected message about removing subsection module from subsection section. Got: ' . implode(', ', $messages)
        );

        // Reload all data from DB after correction.
        $cmb = $DB->get_record('course_modules', ['id' => $subsectionb->cmid]);
        $subsectionsectiona = $DB->get_record('course_sections', ['id' => $subsectionsectiona->id]);

        // Verify: Subsection B is no longer in subsection A's section sequence.
        $this->assertStringNotContainsString(
            ',' . $subsectionb->cmid . ',',
            ',' . $subsectionsectiona->sequence . ',',
            'Subsection B should be removed from subsection section A\'s sequence'
        );

        // Verify: Subsection B's course_modules.section now points to a non-delegated section.
        $targetsection = $DB->get_record('course_sections', ['id' => $cmb->section]);
        $this->assertNull(
            $targetsection->component,
            'Subsection B should now point to a non-delegated section'
        );

        // Verify: Subsection B is now in the target section's sequence.
        $this->assertStringContainsString(
            ',' . $subsectionb->cmid . ',',
            ',' . $targetsection->sequence . ',',
            'Subsection B should be in the target section\'s sequence'
        );

        // Verify: No nested subsections exist after correction.
        $nestedafter = $this->get_nested_subsections_by_section($course->id);
        $this->assertCount(
            0,
            $nestedafter,
            'No nested subsections should exist after correction'
        );

        $nestedbysequenceafter = $this->get_nested_subsections_by_sequence($course->id);
        $this->assertCount(
            0,
            $nestedbysequenceafter,
            'No nested subsections in sequence should exist after correction'
        );

        // Verify complete course structure is valid.
        $this->assert_no_nested_subsections($course->id);
        $this->assert_course_structure_valid($course->id);
    }
}
