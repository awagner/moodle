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
 * Tests for copied course_integrity_check() function to prevent nested subsections.
 *
 * This test class verifies that the unmmodified course_integrity_check() creates
 * nested subsections (subsection modules inside subsection sections) in some scenarios.
 *
 * @package    mod_subsection
 * @copyright  Andreas Wagner (mebis-lp)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class confirm_problem_course_integrity_check_test extends \advanced_testcase {
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
    protected function get_nested_subsections_by_section(int $courseid = 0): array {
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
     * Get nested subsection modules using sequence.
     *
     * This finds subsection modules where the cmid is in the sequence of a subsection-section.
     * This is slower but catches cases where cm.section might be inconsistent.
     *
     * @param int $courseid Optional course ID to filter by
     * @return array Array of nested subsection records
     * @throws \dml_exception
     */
    protected function get_nested_subsections_by_sequence(int $courseid = 0): array {
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
     * This is an unmodified copy of course_integrity_check() to test that it will create
     * nested subsections in certain scenarios.
     *
     * @param int $courseid
     * @param array $rawmods
     * @param array $sections
     * @param bool $fullcheck
     * @param bool $checkonly
     * @return array|trues
     * @throws \dml_exception
     */
    protected function course_integrity_check(
        $courseid,
        $rawmods = null,
        $sections = null,
        $fullcheck = false,
        $checkonly = false
    ) {
        global $DB;
        $messages = [];
        if ($sections === null) {
            $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section', 'id,section,sequence');
        }
        if ($fullcheck) {
            // Retrieve all records from course_modules regardless of module type visibility.
            $rawmods = $DB->get_records('course_modules', ['course' => $courseid], 'id', 'id,section');
        }
        if ($rawmods === null) {
            $rawmods = get_course_mods($courseid);
        }
        if (!$fullcheck && (empty($sections) || empty($rawmods))) {
            // If either of the arrays is empty, no modules are displayed anyway.
            return true;
        }
        $debuggingprefix = 'Failed integrity check for course [' . $courseid . ']. ';

        // First make sure that each module id appears in section sequences only once.
        // If it appears in several section sequences the last section wins.
        // If it appears twice in one section sequence, the first occurence wins.
        $modsection = [];
        foreach ($sections as $sectionid => $section) {
            $sections[$sectionid]->newsequence = $section->sequence;
            if (!empty($section->sequence)) {
                $sequence = explode(",", $section->sequence);
                $sequenceunique = array_unique($sequence);
                if (count($sequenceunique) != count($sequence)) {
                    // Some course module id appears in this section sequence more than once.
                    ksort($sequenceunique); // Preserve initial order of modules.
                    $sequence = array_values($sequenceunique);
                    $sections[$sectionid]->newsequence = join(',', $sequence);
                    $messages[] = $debuggingprefix . 'Sequence for course section [' .
                        $sectionid . '] is "' . $sections[$sectionid]->sequence . '", must be "' .
                        $sections[$sectionid]->newsequence . '"';
                }
                foreach ($sequence as $cmid) {
                    if (array_key_exists($cmid, $modsection) && isset($rawmods[$cmid])) {
                        // Some course module id appears to be in more than one section's sequences.
                        $wrongsectionid = $modsection[$cmid];
                        $sections[$wrongsectionid]->newsequence =
                            trim(preg_replace("/,$cmid,/", ',', ',' . $sections[$wrongsectionid]->newsequence . ','), ',');
                        $messages[] =
                            $debuggingprefix . 'Course module [' . $cmid . '] must be removed from sequence of section [' .
                            $wrongsectionid . '] because it is also present in sequence of section [' . $sectionid . ']';
                    }
                    $modsection[$cmid] = $sectionid;
                }
            }
        }

        // Add orphaned modules to their sections if they exist or to section 0 otherwise.
        if ($fullcheck) {
            foreach ($rawmods as $cmid => $mod) {
                if (!isset($modsection[$cmid])) {
                    // This is a module that is not mentioned in course_section.sequence at all.
                    // Add it to the section $mod->section or to the last available section.
                    if ($mod->section && isset($sections[$mod->section])) {
                        $modsection[$cmid] = $mod->section;
                    } else {
                        $firstsection = reset($sections);
                        $modsection[$cmid] = $firstsection->id;
                    }
                    $sections[$modsection[$cmid]]->newsequence =
                        trim($sections[$modsection[$cmid]]->newsequence . ',' . $cmid, ',');
                    $messages[] = $debuggingprefix . 'Course module [' . $cmid . '] is missing from sequence of section [' .
                        $modsection[$cmid] . ']';
                }
            }
            foreach ($modsection as $cmid => $sectionid) {
                if (!isset($rawmods[$cmid])) {
                    // Section $sectionid refers to module id that does not exist.
                    $sections[$sectionid]->newsequence =
                        trim(preg_replace("/,$cmid,/", ',', ',' . $sections[$sectionid]->newsequence . ','), ',');
                    $messages[] = $debuggingprefix . 'Course module [' . $cmid .
                        '] does not exist but is present in the sequence of section [' . $sectionid . ']';
                }
            }
        }

        // Update changed sections.
        if (!$checkonly && !empty($messages)) {
            foreach ($sections as $sectionid => $section) {
                if ($section->newsequence !== $section->sequence) {
                    $DB->update_record('course_sections', ['id' => $sectionid, 'sequence' => $section->newsequence]);
                }
            }
        }

        // Now make sure that all modules point to the correct sections.
        foreach ($rawmods as $cmid => $mod) {
            if (isset($modsection[$cmid]) && $modsection[$cmid] != $mod->section) {
                if (!$checkonly) {
                    $DB->update_record('course_modules', ['id' => $cmid, 'section' => $modsection[$cmid]]);
                }
                $messages[] = $debuggingprefix . 'Course module [' . $cmid .
                    '] points to section [' . $mod->section . '] instead of [' . $modsection[$cmid] . ']';
            }
        }

        return $messages;
    }

    /**
     * Test scenario 1: Confirms that nested subsections WOULD occur with original implementation.
     *
     * This test demonstrates the problem that exists when:
     * - A subsection section has the lowest section number (e.g., section=-100)
     * - A subsection module is orphaned (not in any sequence, points to non-existent section)
     * - The original course_integrity_check() uses reset($sections) which returns
     *   the first section in the sorted array (the one with lowest section number)
     *
     * Without the fix, the orphaned subsection module would be placed into the
     * subsection section, creating an invalid nested subsection structure.
     *
     * This test uses the ORIGINAL algorithm behavior to confirm the issue.
     *
     * @covers \mod_subsection\confirm_problem_course_integrity_check::course_integrity_check
     */
    public function test_confirms_nested_subsection_would_occur_with_original_implementation(): void {
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
        $this->course_integrity_check($course->id, null, null, true);

        $nested = $this->get_nested_subsections_by_section($course->id);
        $this->assertCount(1, $nested);
        $this->assertEquals($subsectionb->cmid, array_shift($nested)->cmid);

        $nested = $this->get_nested_subsections_by_sequence($course->id);
        $this->assertCount(1, $nested);
        $this->assertEquals($subsectionb->cmid, array_shift($nested)->cmid);
    }

    /**
     * Test scenario 2: Confirms that nested subsections WOULD occur with original implementation
     * when subsection module appears in both normal section and subsection section sequences
     * (Last Section Wins behavior).
     *
     * This test demonstrates the problem that exists when:
     * - A subsection module is in the sequence of a normal parent section
     * - The same subsection module is also in the sequence of a subsection section
     * - The subsection section has a higher section number (processed later)
     * - The original course_integrity_check() uses "last section wins" logic
     *
     * Without the fix, the subsection module would be moved to the subsection section,
     * creating an invalid nested subsection structure.
     *
     * @covers \mod_subsection\confirm_problem_course_integrity_check::course_integrity_check
     */
    public function test_confirms_nested_subsection_would_occur_with_original_last_section_wins(): void {
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
        // The subsection section has a higher section number, so it will be processed LATER
        // and "win" in the "last section wins" logic.
        $this->assertGreaterThan(
            $parentsection->section,
            $subsectionsection->section,
            'Subsection section should have higher section number than parent section'
        );

        $subsectionsection->sequence = (string) $subsection->cmid;
        $DB->update_record('course_sections', $subsectionsection);

        // Run the ORIGINAL integrity check (without the fix).
        $this->course_integrity_check($course->id);

        // Verify that a nested subsection was created (the bug).
        $nested = $this->get_nested_subsections_by_section($course->id);
        $this->assertCount(
            1,
            $nested,
            'Original implementation should create nested subsection due to "last section wins" behavior'
        );
        $this->assertEquals($subsection->cmid, array_shift($nested)->cmid);

        $nested = $this->get_nested_subsections_by_sequence($course->id);
        $this->assertCount(
            1,
            $nested,
            'Original implementation should create nested subsection in sequence'
        );
        $this->assertEquals($subsection->cmid, array_shift($nested)->cmid);
    }
}
