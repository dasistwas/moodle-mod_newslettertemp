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
 * Subscribe to or unsubscribe from a newslettertemp or manage newslettertemp subscription mode
 *
 * This script can be used by either individual users to subscribe to or
 * unsubscribe from a newslettertemp (no 'mode' param provided), or by newslettertemp managers
 * to control the subscription mode (by 'mode' param).
 * This script can be called from a link in email so the sesskey is not
 * required parameter. However, if sesskey is missing, the user has to go
 * through a confirmation page that redirects the user back with the
 * sesskey.
 *
 * @package    mod
 * @subpackage newslettertemp
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once($CFG->dirroot.'/mod/newslettertemp/lib.php');

$id      = required_param('id', PARAM_INT);             // the newslettertemp to subscribe or unsubscribe to
$mode    = optional_param('mode', null, PARAM_INT);     // the newslettertemp's subscription mode
$user    = optional_param('user', 0, PARAM_INT);        // userid of the user to subscribe, defaults to $USER
$sesskey = optional_param('sesskey', null, PARAM_RAW);  // sesskey

$url = new moodle_url('/mod/newslettertemp/subscribe.php', array('id'=>$id));
if (!is_null($mode)) {
    $url->param('mode', $mode);
}
if ($user !== 0) {
    $url->param('user', $user);
}
if (!is_null($sesskey)) {
    $url->param('sesskey', $sesskey);
}
$PAGE->set_url($url);

$newslettertemp   = $DB->get_record('newslettertemp', array('id' => $id), '*', MUST_EXIST);
$course  = $DB->get_record('course', array('id' => $newslettertemp->course), '*', MUST_EXIST);
$cm      = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id, $course->id, false, MUST_EXIST);
$context = context_module::instance($cm->id);

if ($user) {
    require_sesskey();
    if (!has_capability('mod/newslettertemp:managesubscriptions', $context)) {
        print_error('nopermissiontosubscribe', 'newslettertemp');
    }
    $user = $DB->get_record('user', array('id' => $user), '*', MUST_EXIST);
} else {
    $user = $USER;
}

if (isset($cm->groupmode) && empty($course->groupmodeforce)) {
    $groupmode = $cm->groupmode;
} else {
    $groupmode = $course->groupmode;
}
if ($groupmode && !newslettertemp_is_subscribed($user->id, $newslettertemp) && !has_capability('moodle/site:accessallgroups', $context)) {
    if (!groups_get_all_groups($course->id, $USER->id)) {
        print_error('cannotsubscribe', 'newslettertemp');
    }
}

require_login($course, false, $cm);

if (is_null($mode) and !is_enrolled($context, $USER, '', true)) {   // Guests and visitors can't subscribe - only enrolled
    $PAGE->set_title($course->shortname);
    $PAGE->set_heading($course->fullname);
    if (isguestuser()) {
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(get_string('subscribeenrolledonly', 'newslettertemp').'<br /><br />'.get_string('liketologin'),
                     get_login_url(), new moodle_url('/mod/newslettertemp/view.php', array('f'=>$id)));
        echo $OUTPUT->footer();
        exit;
    } else {
        // there should not be any links leading to this place, just redirect
        redirect(new moodle_url('/mod/newslettertemp/view.php', array('f'=>$id)), get_string('subscribeenrolledonly', 'newslettertemp'));
    }
}

$returnto = optional_param('backtoindex',0,PARAM_INT)
    ? "index.php?id=".$course->id
    : "view.php?f=$id";

if (!is_null($mode) and has_capability('mod/newslettertemp:managesubscriptions', $context)) {
    require_sesskey();
    switch ($mode) {
        case NEWSLETTERTEMP_CHOOSESUBSCRIBE : // 0
            newslettertemp_forcesubscribe($newslettertemp->id, 0);
            redirect($returnto, get_string("everyonecannowchoose", "newslettertemp"), 1);
            break;
        case NEWSLETTERTEMP_FORCESUBSCRIBE : // 1
            newslettertemp_forcesubscribe($newslettertemp->id, 1);
            redirect($returnto, get_string("everyoneisnowsubscribed", "newslettertemp"), 1);
            break;
        case NEWSLETTERTEMP_INITIALSUBSCRIBE : // 2
            newslettertemp_forcesubscribe($newslettertemp->id, 2);
            redirect($returnto, get_string("everyoneisnowsubscribed", "newslettertemp"), 1);
            break;
        case NEWSLETTERTEMP_DISALLOWSUBSCRIBE : // 3
            newslettertemp_forcesubscribe($newslettertemp->id, 3);
            redirect($returnto, get_string("noonecansubscribenow", "newslettertemp"), 1);
            break;
        default:
            print_error(get_string('invalidforcesubscribe', 'newslettertemp'));
    }
}

if (newslettertemp_is_forcesubscribed($newslettertemp)) {
    redirect($returnto, get_string("everyoneisnowsubscribed", "newslettertemp"), 1);
}

$info = new stdClass();
$info->name  = fullname($user);
$info->newslettertemp = format_string($newslettertemp->name);

if (newslettertemp_is_subscribed($user->id, $newslettertemp->id)) {
    if (is_null($sesskey)) {    // we came here via link in email
        $PAGE->set_title($course->shortname);
        $PAGE->set_heading($course->fullname);
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(get_string('confirmunsubscribe', 'newslettertemp', format_string($newslettertemp->name)),
                new moodle_url($PAGE->url, array('sesskey' => sesskey())), new moodle_url('/mod/newslettertemp/view.php', array('f' => $id)));
        echo $OUTPUT->footer();
        exit;
    }
    require_sesskey();
    if (newslettertemp_unsubscribe($user->id, $newslettertemp->id)) {
        add_to_log($course->id, "newslettertemp", "unsubscribe", "view.php?f=$newslettertemp->id", $newslettertemp->id, $cm->id);
        redirect($returnto, get_string("nownotsubscribed", "newslettertemp", $info), 1);
    } else {
        print_error('cannotunsubscribe', 'newslettertemp', $_SERVER["HTTP_REFERER"]);
    }

} else {  // subscribe
    if ($newslettertemp->forcesubscribe == NEWSLETTERTEMP_DISALLOWSUBSCRIBE &&
                !has_capability('mod/newslettertemp:managesubscriptions', $context)) {
        print_error('disallowsubscribe', 'newslettertemp', $_SERVER["HTTP_REFERER"]);
    }
    if (!has_capability('mod/newslettertemp:viewdiscussion', $context)) {
        print_error('noviewdiscussionspermission', 'newslettertemp', $_SERVER["HTTP_REFERER"]);
    }
    if (is_null($sesskey)) {    // we came here via link in email
        $PAGE->set_title($course->shortname);
        $PAGE->set_heading($course->fullname);
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(get_string('confirmsubscribe', 'newslettertemp', format_string($newslettertemp->name)),
                new moodle_url($PAGE->url, array('sesskey' => sesskey())), new moodle_url('/mod/newslettertemp/view.php', array('f' => $id)));
        echo $OUTPUT->footer();
        exit;
    }
    require_sesskey();
    newslettertemp_subscribe($user->id, $newslettertemp->id);
    add_to_log($course->id, "newslettertemp", "subscribe", "view.php?f=$newslettertemp->id", $newslettertemp->id, $cm->id);
    redirect($returnto, get_string("nowsubscribed", "newslettertemp", $info), 1);
}
