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

$id         = required_param('id',PARAM_INT);                           // The newslettertemp to subscribe or unsubscribe to
$returnpage = optional_param('returnpage', 'index.php', PARAM_FILE);    // Page to return to.

$url = new moodle_url('/mod/newslettertemp/settracking.php', array('id'=>$id));
if ($returnpage !== 'index.php') {
    $url->param('returnpage', $returnpage);
}
$PAGE->set_url($url);

if (! $newslettertemp = $DB->get_record("newslettertemp", array("id" => $id))) {
    print_error('invalidnewslettertempid', 'newslettertemp');
}

if (! $course = $DB->get_record("course", array("id" => $newslettertemp->course))) {
    print_error('invalidcoursemodule');
}

if (! $cm = get_coursemodule_from_instance("newslettertemp", $newslettertemp->id, $course->id)) {
    print_error('invalidcoursemodule');
}

require_course_login($course, false, $cm);

$returnto = newslettertemp_go_back_to($returnpage.'?id='.$course->id.'&f='.$newslettertemp->id);

if (!newslettertemp_tp_can_track_newslettertemps($newslettertemp)) {
    redirect($returnto);
}

$info = new stdClass();
$info->name  = fullname($USER);
$info->newslettertemp = format_string($newslettertemp->name);
if (newslettertemp_tp_is_tracked($newslettertemp) ) {
    if (newslettertemp_tp_stop_tracking($newslettertemp->id)) {
        add_to_log($course->id, "newslettertemp", "stop tracking", "view.php?f=$newslettertemp->id", $newslettertemp->id, $cm->id);
        redirect($returnto, get_string("nownottracking", "newslettertemp", $info), 1);
    } else {
        print_error('cannottrack', '', $_SERVER["HTTP_REFERER"]);
    }

} else { // subscribe
    if (newslettertemp_tp_start_tracking($newslettertemp->id)) {
        add_to_log($course->id, "newslettertemp", "start tracking", "view.php?f=$newslettertemp->id", $newslettertemp->id, $cm->id);
        redirect($returnto, get_string("nowtracking", "newslettertemp", $info), 1);
    } else {
        print_error('cannottrack', '', $_SERVER["HTTP_REFERER"]);
    }
}


