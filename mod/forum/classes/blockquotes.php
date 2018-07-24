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
 * Library with methods needed for blockquote functionality.
 *
 * This is mainly taken from post_form but changed a few lines to meet the
 * requirement of the inline form. Some form elements are intentionally
 * not removed for later enhancements.
 *
 * @package   mod_forum
 * @copyright Andreas Wagner, SYNERGY LEARNING
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_forum;

defined('MOODLE_INTERNAL') || die();

/**
 * This class contains methods needed for blockquote functionality.
 *
 * This is mainly taken from post_form but changed a few lines to meet the
 * requirement of the inline form. Some form elements are intentionally
 * not removed for later enhancements.
 *
 * @package   mod_forum
 * @copyright Andreas Wagner, SYNERGY LEARNING
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class blockquotes {

    /**
     * Check, whether a quoted reply may be done. Quoted replies are only supported
     * when users uses the atto editor, so we check, whether the first setup
     * editorname starts with "atto" (also supporting attoextended).
     *
     * @return boolean
     */
    public static function can_do_quoted_reply() {
        global $CFG;

        if (!$CFG->forum_enablequotedreplies) {
            return false;
        }

        return (strpos($CFG->texteditors, 'atto') === 0);
    }

    /**
     * Check, whether user can edit inline.
     *
     * @return boolean
     */
    public static function can_edit_inline() {
        global $CFG;

        if (!$CFG->forum_enableinlineediting) {
            return false;
        }

        return (strpos($CFG->texteditors, 'atto') === 0);
    }

    /**
     * Copy the files of the parent post into quoted replying post draft area.
     *
     * @param int $draftitemid draftitemid of the current editor.
     * @param int $contextid of forum module
     * @param string $component
     * @param string $filearea
     * @param int $itemid the id of the parent post!
     * @param array $options
     */
    public static function copy_files_to_draft_area($draftitemid, $contextid,
            $component, $filearea, $itemid, array $options = null) {

        global $USER, $DB;

        $usercontext = \context_user::instance($USER->id);
        $fs = get_file_storage();

        // Cleanup existing draft files.
        // This is needed when are in inline editing mode and the user has choosen to edit
        // another post. Note that we definitely know the right draft itemid.
        if (!empty($options['cleandraftarea'])) {
            $fs->delete_area_files($usercontext->id, 'user', 'draft', $draftitemid);
        }

        // Create a new area and copy existing files into it.
        $filerecord = ['contextid' => $usercontext->id, 'component' => 'user', 'filearea' => 'draft', 'itemid' => $draftitemid];

        if (!is_null($itemid) and $files = $fs->get_area_files($contextid, $component, $filearea, $itemid)) {
            foreach ($files as $file) {
                if ($file->is_directory() and $file->get_filepath() === '/') {
                    // We need a way to mark the age of each draft area,
                    // by not copying the root dir we force it to be created automatically with current timestamp.
                    continue;
                }
                if (!$options['subdirs']
                        and ( $file->is_directory() or $file->get_filepath() !== '/')) {
                    continue;
                }
                $pathnamehash = $fs->get_pathname_hash($usercontext->id, 'user',
                        'draft', $draftitemid, $file->get_filepath(), $file->get_filename());

                if (!$DB->record_exists('files', ['pathnamehash' => $pathnamehash])) {
                    $fs->create_file_from_storedfile($filerecord, $file);
                }
            }
        }
    }

}
