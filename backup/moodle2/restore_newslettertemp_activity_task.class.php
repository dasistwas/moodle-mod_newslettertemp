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
 * @package moodlecore
 * @subpackage backup-moodle2
 * @copyright 2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/newslettertemp/backup/moodle2/restore_newslettertemp_stepslib.php'); // Because it exists (must)

/**
 * newslettertemp restore task that provides all the settings and steps to perform one
 * complete restore of the activity
 */
class restore_newslettertemp_activity_task extends restore_activity_task {

    /**
     * Define (add) particular settings this activity can have
     */
    protected function define_my_settings() {
        // No particular settings for this activity
    }

    /**
     * Define (add) particular steps this activity can have
     */
    protected function define_my_steps() {
        // Choice only has one structure step
        $this->add_step(new restore_newslettertemp_activity_structure_step('newslettertemp_structure', 'newslettertemp.xml'));
    }

    /**
     * Define the contents in the activity that must be
     * processed by the link decoder
     */
    static public function define_decode_contents() {
        $contents = array();

        $contents[] = new restore_decode_content('newslettertemp', array('intro'), 'newslettertemp');
        $contents[] = new restore_decode_content('newslettertemp_posts', array('message'), 'newslettertemp_post');

        return $contents;
    }

    /**
     * Define the decoding rules for links belonging
     * to the activity to be executed by the link decoder
     */
    static public function define_decode_rules() {
        $rules = array();

        // List of newslettertemps in course
        $rules[] = new restore_decode_rule('NEWSLETTERTEMPINDEX', '/mod/newslettertemp/index.php?id=$1', 'course');
        // Forum by cm->id and newslettertemp->id
        $rules[] = new restore_decode_rule('NEWSLETTERTEMPVIEWBYID', '/mod/newslettertemp/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('NEWSLETTERTEMPVIEWBYF', '/mod/newslettertemp/view.php?f=$1', 'newslettertemp');
        // Link to newslettertemp discussion
        $rules[] = new restore_decode_rule('NEWSLETTERTEMPDISCUSSIONVIEW', '/mod/newslettertemp/discuss.php?d=$1', 'newslettertemp_discussion');
        // Link to discussion with parent and with anchor posts
        $rules[] = new restore_decode_rule('NEWSLETTERTEMPDISCUSSIONVIEWPARENT', '/mod/newslettertemp/discuss.php?d=$1&parent=$2',
                                           array('newslettertemp_discussion', 'newslettertemp_post'));
        $rules[] = new restore_decode_rule('NEWSLETTERTEMPDISCUSSIONVIEWINSIDE', '/mod/newslettertemp/discuss.php?d=$1#$2',
                                           array('newslettertemp_discussion', 'newslettertemp_post'));

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * newslettertemp logs. It must return one array
     * of {@link restore_log_rule} objects
     */
    static public function define_restore_log_rules() {
        $rules = array();

        $rules[] = new restore_log_rule('newslettertemp', 'add', 'view.php?id={course_module}', '{newslettertemp}');
        $rules[] = new restore_log_rule('newslettertemp', 'update', 'view.php?id={course_module}', '{newslettertemp}');
        $rules[] = new restore_log_rule('newslettertemp', 'view', 'view.php?id={course_module}', '{newslettertemp}');
        $rules[] = new restore_log_rule('newslettertemp', 'view newslettertemp', 'view.php?id={course_module}', '{newslettertemp}');
        $rules[] = new restore_log_rule('newslettertemp', 'mark read', 'view.php?f={newslettertemp}', '{newslettertemp}');
        $rules[] = new restore_log_rule('newslettertemp', 'start tracking', 'view.php?f={newslettertemp}', '{newslettertemp}');
        $rules[] = new restore_log_rule('newslettertemp', 'stop tracking', 'view.php?f={newslettertemp}', '{newslettertemp}');
        $rules[] = new restore_log_rule('newslettertemp', 'subscribe', 'view.php?f={newslettertemp}', '{newslettertemp}');
        $rules[] = new restore_log_rule('newslettertemp', 'unsubscribe', 'view.php?f={newslettertemp}', '{newslettertemp}');
        $rules[] = new restore_log_rule('newslettertemp', 'subscriber', 'subscribers.php?id={newslettertemp}', '{newslettertemp}');
        $rules[] = new restore_log_rule('newslettertemp', 'subscribers', 'subscribers.php?id={newslettertemp}', '{newslettertemp}');
        $rules[] = new restore_log_rule('newslettertemp', 'view subscribers', 'subscribers.php?id={newslettertemp}', '{newslettertemp}');
        $rules[] = new restore_log_rule('newslettertemp', 'add discussion', 'discuss.php?d={newslettertemp_discussion}', '{newslettertemp_discussion}');
        $rules[] = new restore_log_rule('newslettertemp', 'view discussion', 'discuss.php?d={newslettertemp_discussion}', '{newslettertemp_discussion}');
        $rules[] = new restore_log_rule('newslettertemp', 'move discussion', 'discuss.php?d={newslettertemp_discussion}', '{newslettertemp_discussion}');
        $rules[] = new restore_log_rule('newslettertemp', 'delete discussi', 'view.php?id={course_module}', '{newslettertemp}',
                                        null, 'delete discussion');
        $rules[] = new restore_log_rule('newslettertemp', 'delete discussion', 'view.php?id={course_module}', '{newslettertemp}');
        $rules[] = new restore_log_rule('newslettertemp', 'add post', 'discuss.php?d={newslettertemp_discussion}&parent={newslettertemp_post}', '{newslettertemp_post}');
        $rules[] = new restore_log_rule('newslettertemp', 'update post', 'discuss.php?d={newslettertemp_discussion}#p{newslettertemp_post}&parent={newslettertemp_post}', '{newslettertemp_post}');
        $rules[] = new restore_log_rule('newslettertemp', 'prune post', 'discuss.php?d={newslettertemp_discussion}', '{newslettertemp_post}');
        $rules[] = new restore_log_rule('newslettertemp', 'delete post', 'discuss.php?d={newslettertemp_discussion}', '[post]');

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * course logs. It must return one array
     * of {@link restore_log_rule} objects
     *
     * Note this rules are applied when restoring course logs
     * by the restore final task, but are defined here at
     * activity level. All them are rules not linked to any module instance (cmid = 0)
     */
    static public function define_restore_log_rules_for_course() {
        $rules = array();

        $rules[] = new restore_log_rule('newslettertemp', 'view newslettertemps', 'index.php?id={course}', null);
        $rules[] = new restore_log_rule('newslettertemp', 'subscribeall', 'index.php?id={course}', '{course}');
        $rules[] = new restore_log_rule('newslettertemp', 'unsubscribeall', 'index.php?id={course}', '{course}');
        $rules[] = new restore_log_rule('newslettertemp', 'user report', 'user.php?course={course}&id={user}&mode=[mode]', '{user}');
        $rules[] = new restore_log_rule('newslettertemp', 'search', 'search.php?id={course}&search=[searchenc]', '[search]');

        return $rules;
    }
}
