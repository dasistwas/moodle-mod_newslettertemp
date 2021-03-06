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
 * @package mod-newslettertemp
 * @copyright Jamie Pratt <me@jamiep.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    ///  It must be included from a Moodle page
}

require_once($CFG->libdir.'/formslib.php');

class mod_newslettertemp_post_form extends moodleform {

    /**
     * Returns the options array to use in filemanager for newslettertemp attachments
     *
     * @param stdClass $newslettertemp
     * @return array
     */
    public static function attachment_options($newslettertemp) {
        global $COURSE, $PAGE, $CFG;
        $maxbytes = get_user_max_upload_file_size($PAGE->context, $CFG->maxbytes, $COURSE->maxbytes, $newslettertemp->maxbytes);
        return array(
            'subdirs' => 0,
            'maxbytes' => $maxbytes,
            'maxfiles' => $newslettertemp->maxattachments,
            'accepted_types' => '*',
            'return_types' => FILE_INTERNAL
        );
    }

    /**
     * Returns the options array to use in newslettertemp text editor
     *
     * @return array
     */
    public static function editor_options() {
        global $COURSE, $PAGE, $CFG;
        // TODO: add max files and max size support
        $maxbytes = get_user_max_upload_file_size($PAGE->context, $CFG->maxbytes, $COURSE->maxbytes);
        return array(
            'maxfiles' => EDITOR_UNLIMITED_FILES,
            'maxbytes' => $maxbytes,
            'trusttext'=> true,
            'return_types'=> FILE_INTERNAL | FILE_EXTERNAL
        );
    }

    function definition() {

        global $CFG;
        $mform    =& $this->_form;

        $course        = $this->_customdata['course'];
        $cm            = $this->_customdata['cm'];
        $coursecontext = $this->_customdata['coursecontext'];
        $modcontext    = $this->_customdata['modcontext'];
        $newslettertemp         = $this->_customdata['newslettertemp'];
        $post          = $this->_customdata['post'];

        $mform->addElement('header', 'general', '');//fill in the data depending on page params later using set_data
        $mform->addElement('text', 'subject', get_string('subject', 'newslettertemp'), 'size="48"');
        $mform->setType('subject', PARAM_TEXT);
        $mform->addRule('subject', get_string('required'), 'required', null, 'client');
        $mform->addRule('subject', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('editor', 'message', get_string('message', 'newslettertemp'), null, self::editor_options());
        $mform->setType('message', PARAM_RAW);
        $mform->addRule('message', get_string('required'), 'required', null, 'client');

        if (isset($newslettertemp->id) && newslettertemp_is_forcesubscribed($newslettertemp)) {

            $mform->addElement('static', 'subscribemessage', get_string('subscription', 'newslettertemp'), get_string('everyoneissubscribed', 'newslettertemp'));
            $mform->addElement('hidden', 'subscribe');
            $mform->setType('subscribe', PARAM_INT);
            $mform->addHelpButton('subscribemessage', 'subscription', 'newslettertemp');

        } else if (isset($newslettertemp->forcesubscribe)&& $newslettertemp->forcesubscribe != NEWSLETTERTEMP_DISALLOWSUBSCRIBE ||
                   has_capability('moodle/course:manageactivities', $coursecontext)) {

                $options = array();
                $options[0] = get_string('subscribestop', 'newslettertemp');
                $options[1] = get_string('subscribestart', 'newslettertemp');

                $mform->addElement('select', 'subscribe', get_string('subscription', 'newslettertemp'), $options);
                $mform->addHelpButton('subscribe', 'subscription', 'newslettertemp');
            } else if ($newslettertemp->forcesubscribe == NEWSLETTERTEMP_DISALLOWSUBSCRIBE) {
                $mform->addElement('static', 'subscribemessage', get_string('subscription', 'newslettertemp'), get_string('disallowsubscribe', 'newslettertemp'));
                $mform->addElement('hidden', 'subscribe');
                $mform->setType('subscribe', PARAM_INT);
                $mform->addHelpButton('subscribemessage', 'subscription', 'newslettertemp');
            }

        if (!empty($newslettertemp->maxattachments) && $newslettertemp->maxbytes != 1 && has_capability('mod/newslettertemp:createattachment', $modcontext))  {  //  1 = No attachments at all
            $mform->addElement('filemanager', 'attachments', get_string('attachment', 'newslettertemp'), null, self::attachment_options($newslettertemp));
            $mform->addHelpButton('attachments', 'attachment', 'newslettertemp');
        }

        if (empty($post->id) && has_capability('moodle/course:manageactivities', $coursecontext)) { // hack alert
            $mform->addElement('checkbox', 'mailnow', get_string('mailnow', 'newslettertemp'));
        }

        if (!empty($CFG->newslettertemp_enabletimedposts) && !$post->parent && has_capability('mod/newslettertemp:viewhiddentimedposts', $coursecontext)) { // hack alert
            $mform->addElement('header', '', get_string('displayperiod', 'newslettertemp'));

            $mform->addElement('date_selector', 'timestart', get_string('displaystart', 'newslettertemp'), array('optional'=>true));
            $mform->addHelpButton('timestart', 'displaystart', 'newslettertemp');

            $mform->addElement('date_selector', 'timeend', get_string('displayend', 'newslettertemp'), array('optional'=>true));
            $mform->addHelpButton('timeend', 'displayend', 'newslettertemp');

        } else {
            $mform->addElement('hidden', 'timestart');
            $mform->setType('timestart', PARAM_INT);
            $mform->addElement('hidden', 'timeend');
            $mform->setType('timeend', PARAM_INT);
            $mform->setConstants(array('timestart'=> 0, 'timeend'=>0));
        }

        if (groups_get_activity_groupmode($cm, $course)) { // hack alert
            $groupdata = groups_get_activity_allowed_groups($cm);
            $groupcount = count($groupdata);
            $modulecontext = context_module::instance($cm->id);
            $contextcheck = has_capability('mod/newslettertemp:movediscussions', $modulecontext) && empty($post->parent) && $groupcount > 1;
            if ($contextcheck) {
                $groupinfo = array('0' => get_string('allparticipants'));
                foreach ($groupdata as $grouptemp) {
                    $groupinfo[$grouptemp->id] = $grouptemp->name;
                }
                $mform->addElement('select','groupinfo', get_string('group'), $groupinfo);
                $mform->setDefault('groupinfo', $post->groupid);
            } else {
                if (empty($post->groupid)) {
                    $groupname = get_string('allparticipants');
                } else {
                    $groupname = format_string($groupdata[$post->groupid]->name);
                }
                $mform->addElement('static', 'groupinfo', get_string('group'), $groupname);
            }
        }
        //-------------------------------------------------------------------------------
        // buttons
        if (isset($post->edit)) { // hack alert
            $submit_string = get_string('savechanges');
        } else {
            $submit_string = get_string('posttonewslettertemp', 'newslettertemp');
        }
        $this->add_action_buttons(false, $submit_string);

        $mform->addElement('hidden', 'course');
        $mform->setType('course', PARAM_INT);

        $mform->addElement('hidden', 'newslettertemp');
        $mform->setType('newslettertemp', PARAM_INT);

        $mform->addElement('hidden', 'discussion');
        $mform->setType('discussion', PARAM_INT);

        $mform->addElement('hidden', 'parent');
        $mform->setType('parent', PARAM_INT);

        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);

        $mform->addElement('hidden', 'groupid');
        $mform->setType('groupid', PARAM_INT);

        $mform->addElement('hidden', 'edit');
        $mform->setType('edit', PARAM_INT);

        $mform->addElement('hidden', 'reply');
        $mform->setType('reply', PARAM_INT);
    }

    function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (($data['timeend']!=0) && ($data['timestart']!=0) && $data['timeend'] <= $data['timestart']) {
            $errors['timeend'] = get_string('timestartenderror', 'newslettertemp');
        }
        if (empty($data['message']['text'])) {
            $errors['message'] = get_string('erroremptymessage', 'newslettertemp');
        }
        if (empty($data['subject'])) {
            $errors['subject'] = get_string('erroremptysubject', 'newslettertemp');
        }
        return $errors;
    }
}

