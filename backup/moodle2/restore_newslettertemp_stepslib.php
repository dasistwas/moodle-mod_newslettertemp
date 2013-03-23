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

/**
 * Define all the restore steps that will be used by the restore_newslettertemp_activity_task
 */

/**
 * Structure step to restore one newslettertemp activity
 */
class restore_newslettertemp_activity_structure_step extends restore_activity_structure_step {

    protected function define_structure() {

        $paths = array();
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('newslettertemp', '/activity/newslettertemp');
        if ($userinfo) {
            $paths[] = new restore_path_element('newslettertemp_discussion', '/activity/newslettertemp/discussions/discussion');
            $paths[] = new restore_path_element('newslettertemp_post', '/activity/newslettertemp/discussions/discussion/posts/post');
            $paths[] = new restore_path_element('newslettertemp_rating', '/activity/newslettertemp/discussions/discussion/posts/post/ratings/rating');
            $paths[] = new restore_path_element('newslettertemp_subscription', '/activity/newslettertemp/subscriptions/subscription');
            $paths[] = new restore_path_element('newslettertemp_read', '/activity/newslettertemp/readposts/read');
            $paths[] = new restore_path_element('newslettertemp_track', '/activity/newslettertemp/trackedprefs/track');
        }

        // Return the paths wrapped into standard activity structure
        return $this->prepare_activity_structure($paths);
    }

    protected function process_newslettertemp($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        $data->assesstimestart = $this->apply_date_offset($data->assesstimestart);
        $data->assesstimefinish = $this->apply_date_offset($data->assesstimefinish);
        if ($data->scale < 0) { // scale found, get mapping
            $data->scale = -($this->get_mappingid('scale', abs($data->scale)));
        }

        $newitemid = $DB->insert_record('newslettertemp', $data);
        $this->apply_activity_instance($newitemid);
    }

    protected function process_newslettertemp_discussion($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        $data->newslettertemp = $this->get_new_parentid('newslettertemp');
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $data->timestart = $this->apply_date_offset($data->timestart);
        $data->timeend = $this->apply_date_offset($data->timeend);
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->groupid = $this->get_mappingid('group', $data->groupid);
        $data->usermodified = $this->get_mappingid('user', $data->usermodified);

        $newitemid = $DB->insert_record('newslettertemp_discussions', $data);
        $this->set_mapping('newslettertemp_discussion', $oldid, $newitemid);
    }

    protected function process_newslettertemp_post($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->discussion = $this->get_new_parentid('newslettertemp_discussion');
        $data->created = $this->apply_date_offset($data->created);
        $data->modified = $this->apply_date_offset($data->modified);
        $data->userid = $this->get_mappingid('user', $data->userid);
        // If post has parent, map it (it has been already restored)
        if (!empty($data->parent)) {
            $data->parent = $this->get_mappingid('newslettertemp_post', $data->parent);
        }

        $newitemid = $DB->insert_record('newslettertemp_posts', $data);
        $this->set_mapping('newslettertemp_post', $oldid, $newitemid, true);

        // If !post->parent, it's the 1st post. Set it in discussion
        if (empty($data->parent)) {
            $DB->set_field('newslettertemp_discussions', 'firstpost', $newitemid, array('id' => $data->discussion));
        }
    }

    protected function process_newslettertemp_rating($data) {
        global $DB;

        $data = (object)$data;

        // Cannot use ratings API, cause, it's missing the ability to specify times (modified/created)
        $data->contextid = $this->task->get_contextid();
        $data->itemid    = $this->get_new_parentid('newslettertemp_post');
        if ($data->scaleid < 0) { // scale found, get mapping
            $data->scaleid = -($this->get_mappingid('scale', abs($data->scaleid)));
        }
        $data->rating = $data->value;
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // We need to check that component and ratingarea are both set here.
        if (empty($data->component)) {
            $data->component = 'mod_newslettertemp';
        }
        if (empty($data->ratingarea)) {
            $data->ratingarea = 'post';
        }

        $newitemid = $DB->insert_record('rating', $data);
    }

    protected function process_newslettertemp_subscription($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->newslettertemp = $this->get_new_parentid('newslettertemp');
        $data->userid = $this->get_mappingid('user', $data->userid);

        $newitemid = $DB->insert_record('newslettertemp_subscriptions', $data);
    }

    protected function process_newslettertemp_read($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->newslettertempid = $this->get_new_parentid('newslettertemp');
        $data->discussionid = $this->get_mappingid('newslettertemp_discussion', $data->discussionid);
        $data->postid = $this->get_mappingid('newslettertemp_post', $data->postid);
        $data->userid = $this->get_mappingid('user', $data->userid);

        $newitemid = $DB->insert_record('newslettertemp_read', $data);
    }

    protected function process_newslettertemp_track($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->newslettertempid = $this->get_new_parentid('newslettertemp');
        $data->userid = $this->get_mappingid('user', $data->userid);

        $newitemid = $DB->insert_record('newslettertemp_track_prefs', $data);
    }

    protected function after_execute() {
        global $DB;

        // Add newslettertemp related files, no need to match by itemname (just internally handled context)
        $this->add_related_files('mod_newslettertemp', 'intro', null);

        // If the newslettertemp is of type 'single' and no discussion has been ignited
        // (non-userinfo backup/restore) create the discussion here, using newslettertemp
        // information as base for the initial post.
        $newslettertempid = $this->task->get_activityid();
        $newslettertemprec = $DB->get_record('newslettertemp', array('id' => $newslettertempid));
        if ($newslettertemprec->type == 'single' && !$DB->record_exists('newslettertemp_discussions', array('newslettertemp' => $newslettertempid))) {
            // Create single discussion/lead post from newslettertemp data
            $sd = new stdclass();
            $sd->course   = $newslettertemprec->course;
            $sd->newslettertemp    = $newslettertemprec->id;
            $sd->name     = $newslettertemprec->name;
            $sd->assessed = $newslettertemprec->assessed;
            $sd->message  = $newslettertemprec->intro;
            $sd->messageformat = $newslettertemprec->introformat;
            $sd->messagetrust  = true;
            $sd->mailnow  = false;
            $sdid = newslettertemp_add_discussion($sd, null, $sillybyrefvar, $this->task->get_userid());
            // Mark the post as mailed
            $DB->set_field ('newslettertemp_posts','mailed', '1', array('discussion' => $sdid));
            // Copy all the files from mod_foum/intro to mod_newslettertemp/post
            $fs = get_file_storage();
            $files = $fs->get_area_files($this->task->get_contextid(), 'mod_newslettertemp', 'intro');
            foreach ($files as $file) {
                $newfilerecord = new stdclass();
                $newfilerecord->filearea = 'post';
                $newfilerecord->itemid   = $DB->get_field('newslettertemp_discussions', 'firstpost', array('id' => $sdid));
                $fs->create_file_from_storedfile($newfilerecord, $file);
            }
        }

        // Add post related files, matching by itemname = 'newslettertemp_post'
        $this->add_related_files('mod_newslettertemp', 'post', 'newslettertemp_post');
        $this->add_related_files('mod_newslettertemp', 'attachment', 'newslettertemp_post');
    }
}
