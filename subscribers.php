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
 * This file is used to display and organise newslettertemp subscribers
 *
 * @package mod-newslettertemp
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("../../config.php");
require_once("lib.php");

$id    = required_param('id',PARAM_INT);           // newslettertemp
$group = optional_param('group',0,PARAM_INT);      // change of group
$edit  = optional_param('edit',-1,PARAM_BOOL);     // Turn editing on and off

$url = new moodle_url('/mod/newslettertemp/subscribers.php', array('id'=>$id));
if ($group !== 0) {
    $url->param('group', $group);
}
if ($edit !== 0) {
    $url->param('edit', $edit);
}
$PAGE->set_url($url);

$newslettertemp = $DB->get_record('newslettertemp', array('id'=>$id), '*', MUST_EXIST);
$course = $DB->get_record('course', array('id'=>$newslettertemp->course), '*', MUST_EXIST);
if (! $cm = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id, $course->id)) {
    $cm->id = 0;
}

require_login($course, false, $cm);

$context = context_module::instance($cm->id);
if (!has_capability('mod/newslettertemp:viewsubscribers', $context)) {
    print_error('nopermissiontosubscribe', 'newslettertemp');
}

unset($SESSION->fromdiscussion);

add_to_log($course->id, "newslettertemp", "view subscribers", "subscribers.php?id=$newslettertemp->id", $newslettertemp->id, $cm->id);

$newslettertempoutput = $PAGE->get_renderer('mod_newslettertemp');
$currentgroup = groups_get_activity_group($cm);
$options = array('newslettertempid'=>$newslettertemp->id, 'currentgroup'=>$currentgroup, 'context'=>$context);
$existingselector = new newslettertemp_existing_subscriber_selector('existingsubscribers', $options);
$subscriberselector = new newslettertemp_potential_subscriber_selector('potentialsubscribers', $options);
$subscriberselector->set_existing_subscribers($existingselector->find_users(''));

if (data_submitted()) {
    require_sesskey();
    $subscribe = (bool)optional_param('subscribe', false, PARAM_RAW);
    $unsubscribe = (bool)optional_param('unsubscribe', false, PARAM_RAW);
    /** It has to be one or the other, not both or neither */
    if (!($subscribe xor $unsubscribe)) {
        print_error('invalidaction');
    }
    if ($subscribe) {
        $users = $subscriberselector->get_selected_users();
        foreach ($users as $user) {
            if (!newslettertemp_subscribe($user->id, $id)) {
                print_error('cannotaddsubscriber', 'newslettertemp', '', $user->id);
            }
        }
    } else if ($unsubscribe) {
        $users = $existingselector->get_selected_users();
        foreach ($users as $user) {
            if (!newslettertemp_unsubscribe($user->id, $id)) {
                print_error('cannotremovesubscriber', 'newslettertemp', '', $user->id);
            }
        }
    }
    $subscriberselector->invalidate_selected_users();
    $existingselector->invalidate_selected_users();
    $subscriberselector->set_existing_subscribers($existingselector->find_users(''));
}

$strsubscribers = get_string("subscribers", "newslettertemp");
$PAGE->navbar->add($strsubscribers);
$PAGE->set_title($strsubscribers);
$PAGE->set_heading($COURSE->fullname);
if (has_capability('mod/newslettertemp:managesubscriptions', $context)) {
    $PAGE->set_button(newslettertemp_update_subscriptions_button($course->id, $id));
    if ($edit != -1) {
        $USER->subscriptionsediting = $edit;
    }
} else {
    unset($USER->subscriptionsediting);
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('newslettertemp', 'newslettertemp').' '.$strsubscribers);
if (empty($USER->subscriptionsediting)) {
    echo $newslettertempoutput->subscriber_overview(newslettertemp_subscribed_users($course, $newslettertemp, $currentgroup, $context), $newslettertemp, $course);
} else if (newslettertemp_is_forcesubscribed($newslettertemp)) {
    $subscriberselector->set_force_subscribed(true);
    echo $newslettertempoutput->subscribed_users($subscriberselector);
} else {
    echo $newslettertempoutput->subscriber_selection_form($existingselector, $subscriberselector);
}
echo $OUTPUT->footer();