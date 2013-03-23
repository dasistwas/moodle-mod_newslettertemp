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
 * Set tracking option for the newslettertemp.
 *
 * @package mod-newslettertemp
 * @copyright 2005 mchurch
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("../../config.php");
require_once("lib.php");

$f          = required_param('f',PARAM_INT); // The newslettertemp to mark
$mark       = required_param('mark',PARAM_ALPHA); // Read or unread?
$d          = optional_param('d',0,PARAM_INT); // Discussion to mark.
$returnpage = optional_param('returnpage', 'index.php', PARAM_FILE);    // Page to return to.

$url = new moodle_url('/mod/newslettertemp/markposts.php', array('f'=>$f, 'mark'=>$mark));
if ($d !== 0) {
    $url->param('d', $d);
}
if ($returnpage !== 'index.php') {
    $url->param('returnpage', $returnpage);
}
$PAGE->set_url($url);

if (! $newslettertemp = $DB->get_record("newslettertemp", array("id" => $f))) {
    print_error('invalidnewslettertempid', 'newslettertemp');
}

if (! $course = $DB->get_record("course", array("id" => $newslettertemp->course))) {
    print_error('invalidcourseid');
}

if (!$cm = get_coursemodule_from_instance("newslettertemp", $newslettertemp->id, $course->id)) {
    print_error('invalidcoursemodule');
}

$user = $USER;

require_login($course, false, $cm);

if ($returnpage == 'index.php') {
    $returnto = newslettertemp_go_back_to($returnpage.'?id='.$course->id);
} else {
    $returnto = newslettertemp_go_back_to($returnpage.'?f='.$newslettertemp->id);
}

if (isguestuser()) {   // Guests can't change newslettertemp
    $PAGE->set_title($course->shortname);
    $PAGE->set_heading($course->fullname);
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(get_string('noguesttracking', 'newslettertemp').'<br /><br />'.get_string('liketologin'), get_login_url(), $returnto);
    echo $OUTPUT->footer();
    exit;
}

$info = new stdClass();
$info->name  = fullname($user);
$info->newslettertemp = format_string($newslettertemp->name);

if ($mark == 'read') {
    if (!empty($d)) {
        if (! $discussion = $DB->get_record('newslettertemp_discussions', array('id'=> $d, 'newslettertemp'=> $newslettertemp->id))) {
            print_error('invaliddiscussionid', 'newslettertemp');
        }

        if (newslettertemp_tp_mark_discussion_read($user, $d)) {
            add_to_log($course->id, "discussion", "mark read", "view.php?f=$newslettertemp->id", $d, $cm->id);
        }
    } else {
        // Mark all messages read in current group
        $currentgroup = groups_get_activity_group($cm);
        if(!$currentgroup) {
            // mark_newslettertemp_read requires ===false, while get_activity_group
            // may return 0
            $currentgroup=false;
        }
        if (newslettertemp_tp_mark_newslettertemp_read($user, $newslettertemp->id,$currentgroup)) {
            add_to_log($course->id, "newslettertemp", "mark read", "view.php?f=$newslettertemp->id", $newslettertemp->id, $cm->id);
        }
    }

/// FUTURE - Add ability to mark them as unread.
//    } else { // subscribe
//        if (newslettertemp_tp_start_tracking($newslettertemp->id, $user->id)) {
//            add_to_log($course->id, "newslettertemp", "mark unread", "view.php?f=$newslettertemp->id", $newslettertemp->id, $cm->id);
//            redirect($returnto, get_string("nowtracking", "newslettertemp", $info), 1);
//        } else {
//            print_error("Could not start tracking that newslettertemp", $_SERVER["HTTP_REFERER"]);
//        }
}

redirect($returnto);

