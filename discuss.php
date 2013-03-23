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
 * Displays a post, and all the posts below it.
 * If no post is given, displays all posts in a discussion
 *
 * @package mod-newslettertemp
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

    require_once('../../config.php');

    $d      = required_param('d', PARAM_INT);                // Discussion ID
    $parent = optional_param('parent', 0, PARAM_INT);        // If set, then display this post and all children.
    $mode   = optional_param('mode', 0, PARAM_INT);          // If set, changes the layout of the thread
    $move   = optional_param('move', 0, PARAM_INT);          // If set, moves this discussion to another newslettertemp
    $mark   = optional_param('mark', '', PARAM_ALPHA);       // Used for tracking read posts if user initiated.
    $postid = optional_param('postid', 0, PARAM_INT);        // Used for tracking read posts if user initiated.

    $url = new moodle_url('/mod/newslettertemp/discuss.php', array('d'=>$d));
    if ($parent !== 0) {
        $url->param('parent', $parent);
    }
    $PAGE->set_url($url);

    $discussion = $DB->get_record('newslettertemp_discussions', array('id' => $d), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $discussion->course), '*', MUST_EXIST);
    $newslettertemp = $DB->get_record('newslettertemp', array('id' => $discussion->newslettertemp), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id, $course->id, false, MUST_EXIST);

    require_course_login($course, true, $cm);

    // move this down fix for MDL-6926
    require_once($CFG->dirroot.'/mod/newslettertemp/lib.php');

    $modcontext = context_module::instance($cm->id);
    require_capability('mod/newslettertemp:viewdiscussion', $modcontext, NULL, true, 'noviewdiscussionspermission', 'newslettertemp');

    if (!empty($CFG->enablerssfeeds) && !empty($CFG->newslettertemp_enablerssfeeds) && $newslettertemp->rsstype && $newslettertemp->rssarticles) {
        require_once("$CFG->libdir/rsslib.php");

        $rsstitle = format_string($course->shortname, true, array('context' => context_course::instance($course->id))) . ': %fullname%';
        rss_add_http_header($modcontext, 'mod_newslettertemp', $newslettertemp, $rsstitle);
    }

/// move discussion if requested
    if ($move > 0 and confirm_sesskey()) {
        $return = $CFG->wwwroot.'/mod/newslettertemp/discuss.php?d='.$discussion->id;

        require_capability('mod/newslettertemp:movediscussions', $modcontext);

        if ($newslettertemp->type == 'single') {
            print_error('cannotmovefromsinglenewslettertemp', 'newslettertemp', $return);
        }

        if (!$newslettertempto = $DB->get_record('newslettertemp', array('id' => $move))) {
            print_error('cannotmovetonotexist', 'newslettertemp', $return);
        }

        if ($newslettertempto->type == 'single') {
            print_error('cannotmovetosinglenewslettertemp', 'newslettertemp', $return);
        }

        if (!$cmto = get_coursemodule_from_instance('newslettertemp', $newslettertempto->id, $course->id)) {
            print_error('cannotmovetonotfound', 'newslettertemp', $return);
        }

        if (!coursemodule_visible_for_user($cmto)) {
            print_error('cannotmovenotvisible', 'newslettertemp', $return);
        }

        require_capability('mod/newslettertemp:startdiscussion', context_module::instance($cmto->id));

        if (!newslettertemp_move_attachments($discussion, $newslettertemp->id, $newslettertempto->id)) {
            echo $OUTPUT->notification("Errors occurred while moving attachment directories - check your file permissions");
        }
        $DB->set_field('newslettertemp_discussions', 'newslettertemp', $newslettertempto->id, array('id' => $discussion->id));
        $DB->set_field('newslettertemp_read', 'newslettertempid', $newslettertempto->id, array('discussionid' => $discussion->id));
        add_to_log($course->id, 'newslettertemp', 'move discussion', "discuss.php?d=$discussion->id", $discussion->id, $cmto->id);

        require_once($CFG->libdir.'/rsslib.php');
        require_once($CFG->dirroot.'/mod/newslettertemp/rsslib.php');

        // Delete the RSS files for the 2 newslettertemps to force regeneration of the feeds
        newslettertemp_rss_delete_file($newslettertemp);
        newslettertemp_rss_delete_file($newslettertempto);

        redirect($return.'&moved=-1&sesskey='.sesskey());
    }

    add_to_log($course->id, 'newslettertemp', 'view discussion', "discuss.php?d=$discussion->id", $discussion->id, $cm->id);

    unset($SESSION->fromdiscussion);

    if ($mode) {
        set_user_preference('newslettertemp_displaymode', $mode);
    }

    $displaymode = get_user_preferences('newslettertemp_displaymode', $CFG->newslettertemp_displaymode);

    if ($parent) {
        // If flat AND parent, then force nested display this time
        if ($displaymode == NEWSLETTERTEMP_MODE_FLATOLDEST or $displaymode == NEWSLETTERTEMP_MODE_FLATNEWEST) {
            $displaymode = NEWSLETTERTEMP_MODE_NESTED;
        }
    } else {
        $parent = $discussion->firstpost;
    }

    if (! $post = newslettertemp_get_post_full($parent)) {
        print_error("notexists", 'newslettertemp', "$CFG->wwwroot/mod/newslettertemp/view.php?f=$newslettertemp->id");
    }

    if (!newslettertemp_user_can_see_post($newslettertemp, $discussion, $post, null, $cm)) {
        print_error('noviewdiscussionspermission', 'newslettertemp', "$CFG->wwwroot/mod/newslettertemp/view.php?id=$newslettertemp->id");
    }

    if ($mark == 'read' or $mark == 'unread') {
        if ($CFG->newslettertemp_usermarksread && newslettertemp_tp_can_track_newslettertemps($newslettertemp) && newslettertemp_tp_is_tracked($newslettertemp)) {
            if ($mark == 'read') {
                newslettertemp_tp_add_read_record($USER->id, $postid);
            } else {
                // unread
                newslettertemp_tp_delete_read_records($USER->id, $postid);
            }
        }
    }

    $searchform = newslettertemp_search_form($course);

    $newslettertempnode = $PAGE->navigation->find($cm->id, navigation_node::TYPE_ACTIVITY);
    if (empty($newslettertempnode)) {
        $newslettertempnode = $PAGE->navbar;
    } else {
        $newslettertempnode->make_active();
    }
    $node = $newslettertempnode->add(format_string($discussion->name), new moodle_url('/mod/newslettertemp/discuss.php', array('d'=>$discussion->id)));
    $node->display = false;
    if ($node && $post->id != $discussion->firstpost) {
        $node->add(format_string($post->subject), $PAGE->url);
    }

    $PAGE->set_title("$course->shortname: ".format_string($discussion->name));
    $PAGE->set_heading($course->fullname);
    $PAGE->set_button($searchform);
    echo $OUTPUT->header();

/// Check to see if groups are being used in this newslettertemp
/// If so, make sure the current person is allowed to see this discussion
/// Also, if we know they should be able to reply, then explicitly set $canreply for performance reasons

    $canreply = newslettertemp_user_can_post($newslettertemp, $discussion, $USER, $cm, $course, $modcontext);
    if (!$canreply and $newslettertemp->type !== 'news') {
        if (isguestuser() or !isloggedin()) {
            $canreply = true;
        }
        if (!is_enrolled($modcontext) and !is_viewing($modcontext)) {
            // allow guests and not-logged-in to see the link - they are prompted to log in after clicking the link
            // normal users with temporary guest access see this link too, they are asked to enrol instead
            $canreply = enrol_selfenrol_available($course->id);
        }
    }

/// Print the controls across the top
    echo '<div class="discussioncontrols clearfix">';

    if (!empty($CFG->enableportfolios) && has_capability('mod/newslettertemp:exportdiscussion', $modcontext)) {
        require_once($CFG->libdir.'/portfoliolib.php');
        $button = new portfolio_add_button();
        $button->set_callback_options('newslettertemp_portfolio_caller', array('discussionid' => $discussion->id), 'mod_newslettertemp');
        $button = $button->to_html(PORTFOLIO_ADD_FULL_FORM, get_string('exportdiscussion', 'mod_newslettertemp'));
        $buttonextraclass = '';
        if (empty($button)) {
            // no portfolio plugin available.
            $button = '&nbsp;';
            $buttonextraclass = ' noavailable';
        }
        echo html_writer::tag('div', $button, array('class' => 'discussioncontrol exporttoportfolio'.$buttonextraclass));
    } else {
        echo html_writer::tag('div', '&nbsp;', array('class'=>'discussioncontrol nullcontrol'));
    }

    // groups selector not needed here
    echo '<div class="discussioncontrol displaymode">';
    newslettertemp_print_mode_form($discussion->id, $displaymode);
    echo "</div>";

    if ($newslettertemp->type != 'single'
                && has_capability('mod/newslettertemp:movediscussions', $modcontext)) {

        echo '<div class="discussioncontrol movediscussion">';
        // Popup menu to move discussions to other newslettertemps. The discussion in a
        // single discussion newslettertemp can't be moved.
        $modinfo = get_fast_modinfo($course);
        if (isset($modinfo->instances['newslettertemp'])) {
            $newslettertempmenu = array();
            // Check newslettertemp types and eliminate simple discussions.
            $newslettertempcheck = $DB->get_records('newslettertemp', array('course' => $course->id),'', 'id, type');
            foreach ($modinfo->instances['newslettertemp'] as $newslettertempcm) {
                if (!$newslettertempcm->uservisible || !has_capability('mod/newslettertemp:startdiscussion',
                    context_module::instance($newslettertempcm->id))) {
                    continue;
                }
                $section = $newslettertempcm->sectionnum;
                $sectionname = get_section_name($course, $section);
                if (empty($newslettertempmenu[$section])) {
                    $newslettertempmenu[$section] = array($sectionname => array());
                }
                $newslettertempidcompare = $newslettertempcm->instance != $newslettertemp->id;
                $newslettertemptypecheck = $newslettertempcheck[$newslettertempcm->instance]->type !== 'single';
                if ($newslettertempidcompare and $newslettertemptypecheck) {
                    $url = "/mod/newslettertemp/discuss.php?d=$discussion->id&move=$newslettertempcm->instance&sesskey=".sesskey();
                    $newslettertempmenu[$section][$sectionname][$url] = format_string($newslettertempcm->name);
                }
            }
            if (!empty($newslettertempmenu)) {
                echo '<div class="movediscussionoption">';
                $select = new url_select($newslettertempmenu, '',
                        array(''=>get_string("movethisdiscussionto", "newslettertemp")),
                        'newslettertempmenu', get_string('move'));
                echo $OUTPUT->render($select);
                echo "</div>";
            }
        }
        echo "</div>";
    }
    echo '<div class="clearfloat">&nbsp;</div>';
    echo "</div>";

    if (!empty($newslettertemp->blockafter) && !empty($newslettertemp->blockperiod)) {
        $a = new stdClass();
        $a->blockafter  = $newslettertemp->blockafter;
        $a->blockperiod = get_string('secondstotime'.$newslettertemp->blockperiod);
        echo $OUTPUT->notification(get_string('thisnewslettertempisthrottled','newslettertemp',$a));
    }

    if ($newslettertemp->type == 'qanda' && !has_capability('mod/newslettertemp:viewqandawithoutposting', $modcontext) &&
                !newslettertemp_user_has_posted($newslettertemp->id,$discussion->id,$USER->id)) {
        echo $OUTPUT->notification(get_string('qandanotify','newslettertemp'));
    }

    if ($move == -1 and confirm_sesskey()) {
        echo $OUTPUT->notification(get_string('discussionmoved', 'newslettertemp', format_string($newslettertemp->name,true)));
    }

    $canrate = has_capability('mod/newslettertemp:rate', $modcontext);
    newslettertemp_print_discussion($course, $cm, $newslettertemp, $discussion, $post, $displaymode, $canreply, $canrate);

    echo $OUTPUT->footer();



