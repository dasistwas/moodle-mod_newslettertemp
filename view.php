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
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

    require_once('../../config.php');
    require_once('lib.php');
    require_once($CFG->libdir.'/completionlib.php');

    $id          = optional_param('id', 0, PARAM_INT);       // Course Module ID
    $f           = optional_param('f', 0, PARAM_INT);        // Forum ID
    $mode        = optional_param('mode', 0, PARAM_INT);     // Display mode (for single newslettertemp)
    $showall     = optional_param('showall', '', PARAM_INT); // show all discussions on one page
    $changegroup = optional_param('group', -1, PARAM_INT);   // choose the current group
    $page        = optional_param('page', 0, PARAM_INT);     // which page to show
    $search      = optional_param('search', '', PARAM_CLEAN);// search string

    $params = array();
    if ($id) {
        $params['id'] = $id;
    } else {
        $params['f'] = $f;
    }
    if ($page) {
        $params['page'] = $page;
    }
    if ($search) {
        $params['search'] = $search;
    }
    $PAGE->set_url('/mod/newslettertemp/view.php', $params);

    if ($id) {
        if (! $cm = get_coursemodule_from_id('newslettertemp', $id)) {
            print_error('invalidcoursemodule');
        }
        if (! $course = $DB->get_record("course", array("id" => $cm->course))) {
            print_error('coursemisconf');
        }
        if (! $newslettertemp = $DB->get_record("newslettertemp", array("id" => $cm->instance))) {
            print_error('invalidnewslettertempid', 'newslettertemp');
        }
        if ($newslettertemp->type == 'single') {
            $PAGE->set_pagetype('mod-newslettertemp-discuss');
        }
        // move require_course_login here to use forced language for course
        // fix for MDL-6926
        require_course_login($course, true, $cm);
        $strnewslettertemps = get_string("modulenameplural", "newslettertemp");
        $strnewslettertemp = get_string("modulename", "newslettertemp");
    } else if ($f) {

        if (! $newslettertemp = $DB->get_record("newslettertemp", array("id" => $f))) {
            print_error('invalidnewslettertempid', 'newslettertemp');
        }
        if (! $course = $DB->get_record("course", array("id" => $newslettertemp->course))) {
            print_error('coursemisconf');
        }

        if (!$cm = get_coursemodule_from_instance("newslettertemp", $newslettertemp->id, $course->id)) {
            print_error('missingparameter');
        }
        // move require_course_login here to use forced language for course
        // fix for MDL-6926
        require_course_login($course, true, $cm);
        $strnewslettertemps = get_string("modulenameplural", "newslettertemp");
        $strnewslettertemp = get_string("modulename", "newslettertemp");
    } else {
        print_error('missingparameter');
    }

    if (!$PAGE->button) {
        $PAGE->set_button(newslettertemp_search_form($course, $search));
    }

    $context = context_module::instance($cm->id);
    $PAGE->set_context($context);

    if (!empty($CFG->enablerssfeeds) && !empty($CFG->newslettertemp_enablerssfeeds) && $newslettertemp->rsstype && $newslettertemp->rssarticles) {
        require_once("$CFG->libdir/rsslib.php");

        $rsstitle = format_string($course->shortname, true, array('context' => context_course::instance($course->id))) . ': %fullname%';
        rss_add_http_header($context, 'mod_newslettertemp', $newslettertemp, $rsstitle);
    }

    // Mark viewed if required
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);

/// Print header.

    $PAGE->set_title(format_string($newslettertemp->name));
    $PAGE->add_body_class('newslettertemptype-'.$newslettertemp->type);
    $PAGE->set_heading(format_string($course->fullname));

    echo $OUTPUT->header();

/// Some capability checks.
    if (empty($cm->visible) and !has_capability('moodle/course:viewhiddenactivities', $context)) {
        notice(get_string("activityiscurrentlyhidden"));
    }

    if (!has_capability('mod/newslettertemp:viewdiscussion', $context)) {
        notice(get_string('noviewdiscussionspermission', 'newslettertemp'));
    }

/// find out current groups mode
    groups_print_activity_menu($cm, $CFG->wwwroot . '/mod/newslettertemp/view.php?id=' . $cm->id);
    $currentgroup = groups_get_activity_group($cm);
    $groupmode = groups_get_activity_groupmode($cm);

/// Okay, we can show the discussions. Log the newslettertemp view.
    if ($cm->id) {
        add_to_log($course->id, "newslettertemp", "view newslettertemp", "view.php?id=$cm->id", "$newslettertemp->id", $cm->id);
    } else {
        add_to_log($course->id, "newslettertemp", "view newslettertemp", "view.php?f=$newslettertemp->id", "$newslettertemp->id");
    }

    $SESSION->fromdiscussion = qualified_me();   // Return here if we post or set subscription etc


/// Print settings and things across the top

    // If it's a simple single discussion newslettertemp, we need to print the display
    // mode control.
    if ($newslettertemp->type == 'single') {
        $discussion = NULL;
        $discussions = $DB->get_records('newslettertemp_discussions', array('newslettertemp'=>$newslettertemp->id), 'timemodified ASC');
        if (!empty($discussions)) {
            $discussion = array_pop($discussions);
        }
        if ($discussion) {
            if ($mode) {
                set_user_preference("newslettertemp_displaymode", $mode);
            }
            $displaymode = get_user_preferences("newslettertemp_displaymode", $CFG->newslettertemp_displaymode);
            newslettertemp_print_mode_form($newslettertemp->id, $displaymode, $newslettertemp->type);
        }
    }

    if (!empty($newslettertemp->blockafter) && !empty($newslettertemp->blockperiod)) {
        $a->blockafter = $newslettertemp->blockafter;
        $a->blockperiod = get_string('secondstotime'.$newslettertemp->blockperiod);
        echo $OUTPUT->notification(get_string('thisnewslettertempisthrottled','newslettertemp',$a));
    }

    if ($newslettertemp->type == 'qanda' && !has_capability('moodle/course:manageactivities', $context)) {
        echo $OUTPUT->notification(get_string('qandanotify','newslettertemp'));
    }

    switch ($newslettertemp->type) {
        case 'single':
            if (!empty($discussions) && count($discussions) > 1) {
                echo $OUTPUT->notification(get_string('warnformorepost', 'newslettertemp'));
            }
            if (! $post = newslettertemp_get_post_full($discussion->firstpost)) {
                print_error('cannotfindfirstpost', 'newslettertemp');
            }
            if ($mode) {
                set_user_preference("newslettertemp_displaymode", $mode);
            }

            $canreply    = newslettertemp_user_can_post($newslettertemp, $discussion, $USER, $cm, $course, $context);
            $canrate     = has_capability('mod/newslettertemp:rate', $context);
            $displaymode = get_user_preferences("newslettertemp_displaymode", $CFG->newslettertemp_displaymode);

            echo '&nbsp;'; // this should fix the floating in FF
            newslettertemp_print_discussion($course, $cm, $newslettertemp, $discussion, $post, $displaymode, $canreply, $canrate);
            break;

        case 'eachuser':
            if (!empty($newslettertemp->intro)) {
                echo $OUTPUT->box(format_module_intro('newslettertemp', $newslettertemp, $cm->id), 'generalbox', 'intro');
            }
            echo '<p class="mdl-align">';
            if (newslettertemp_user_can_post_discussion($newslettertemp, null, -1, $cm)) {
                print_string("allowsdiscussions", "newslettertemp");
            } else {
                echo '&nbsp;';
            }
            echo '</p>';
            if (!empty($showall)) {
                newslettertemp_print_latest_discussions($course, $newslettertemp, 0, 'header', '', -1, -1, -1, 0, $cm);
            } else {
                newslettertemp_print_latest_discussions($course, $newslettertemp, -1, 'header', '', -1, -1, $page, $CFG->newslettertemp_manydiscussions, $cm);
            }
            break;

        case 'teacher':
            if (!empty($showall)) {
                newslettertemp_print_latest_discussions($course, $newslettertemp, 0, 'header', '', -1, -1, -1, 0, $cm);
            } else {
                newslettertemp_print_latest_discussions($course, $newslettertemp, -1, 'header', '', -1, -1, $page, $CFG->newslettertemp_manydiscussions, $cm);
            }
            break;

        case 'blog':
            if (!empty($newslettertemp->intro)) {
                echo $OUTPUT->box(format_module_intro('newslettertemp', $newslettertemp, $cm->id), 'generalbox', 'intro');
            }
            echo '<br />';
            if (!empty($showall)) {
                newslettertemp_print_latest_discussions($course, $newslettertemp, 0, 'plain', '', -1, -1, -1, 0, $cm);
            } else {
                newslettertemp_print_latest_discussions($course, $newslettertemp, -1, 'plain', '', -1, -1, $page, $CFG->newslettertemp_manydiscussions, $cm);
            }
            break;

        default:
            if (!empty($newslettertemp->intro)) {
                echo $OUTPUT->box(format_module_intro('newslettertemp', $newslettertemp, $cm->id), 'generalbox', 'intro');
            }
            echo '<br />';
            if (!empty($showall)) {
                newslettertemp_print_latest_discussions($course, $newslettertemp, 0, 'header', '', -1, -1, -1, 0, $cm);
            } else {
                newslettertemp_print_latest_discussions($course, $newslettertemp, -1, 'header', '', -1, -1, $page, $CFG->newslettertemp_manydiscussions, $cm);
            }


            break;
    }

    echo $OUTPUT->footer($course);


