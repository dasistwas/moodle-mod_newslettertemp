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
 * Edit and save a new post to a discussion
 *
 * @package mod-newslettertemp
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once('lib.php');
require_once($CFG->libdir.'/completionlib.php');

$reply   = optional_param('reply', 0, PARAM_INT);
$newslettertemp   = optional_param('newslettertemp', 0, PARAM_INT);
$edit    = optional_param('edit', 0, PARAM_INT);
$delete  = optional_param('delete', 0, PARAM_INT);
$prune   = optional_param('prune', 0, PARAM_INT);
$name    = optional_param('name', '', PARAM_CLEAN);
$confirm = optional_param('confirm', 0, PARAM_INT);
$groupid = optional_param('groupid', null, PARAM_INT);

$PAGE->set_url('/mod/newslettertemp/post.php', array(
        'reply' => $reply,
        'newslettertemp' => $newslettertemp,
        'edit'  => $edit,
        'delete'=> $delete,
        'prune' => $prune,
        'name'  => $name,
        'confirm'=>$confirm,
        'groupid'=>$groupid,
        ));
//these page_params will be passed as hidden variables later in the form.
$page_params = array('reply'=>$reply, 'newslettertemp'=>$newslettertemp, 'edit'=>$edit);

$sitecontext = context_system::instance();

if (!isloggedin() or isguestuser()) {

    if (!isloggedin() and !get_referer()) {
        // No referer+not logged in - probably coming in via email  See MDL-9052
        require_login();
    }

    if (!empty($newslettertemp)) {      // User is starting a new discussion in a newslettertemp
        if (! $newslettertemp = $DB->get_record('newslettertemp', array('id' => $newslettertemp))) {
            print_error('invalidnewslettertempid', 'newslettertemp');
        }
    } else if (!empty($reply)) {      // User is writing a new reply
        if (! $parent = newslettertemp_get_post_full($reply)) {
            print_error('invalidparentpostid', 'newslettertemp');
        }
        if (! $discussion = $DB->get_record('newslettertemp_discussions', array('id' => $parent->discussion))) {
            print_error('notpartofdiscussion', 'newslettertemp');
        }
        if (! $newslettertemp = $DB->get_record('newslettertemp', array('id' => $discussion->newslettertemp))) {
            print_error('invalidnewslettertempid');
        }
    }
    if (! $course = $DB->get_record('course', array('id' => $newslettertemp->course))) {
        print_error('invalidcourseid');
    }

    if (!$cm = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id, $course->id)) { // For the logs
        print_error('invalidcoursemodule');
    } else {
        $modcontext = context_module::instance($cm->id);
    }

    $PAGE->set_cm($cm, $course, $newslettertemp);
    $PAGE->set_context($modcontext);
    $PAGE->set_title($course->shortname);
    $PAGE->set_heading($course->fullname);

    echo $OUTPUT->header();
    echo $OUTPUT->confirm(get_string('noguestpost', 'newslettertemp').'<br /><br />'.get_string('liketologin'), get_login_url(), get_referer(false));
    echo $OUTPUT->footer();
    exit;
}

require_login(0, false);   // Script is useless unless they're logged in

if (!empty($newslettertemp)) {      // User is starting a new discussion in a newslettertemp
    if (! $newslettertemp = $DB->get_record("newslettertemp", array("id" => $newslettertemp))) {
        print_error('invalidnewslettertempid', 'newslettertemp');
    }
    if (! $course = $DB->get_record("course", array("id" => $newslettertemp->course))) {
        print_error('invalidcourseid');
    }
    if (! $cm = get_coursemodule_from_instance("newslettertemp", $newslettertemp->id, $course->id)) {
        print_error("invalidcoursemodule");
    }

    $coursecontext = context_course::instance($course->id);

    if (! newslettertemp_user_can_post_discussion($newslettertemp, $groupid, -1, $cm)) {
        if (!isguestuser()) {
            if (!is_enrolled($coursecontext)) {
                if (enrol_selfenrol_available($course->id)) {
                    $SESSION->wantsurl = qualified_me();
                    $SESSION->enrolcancel = $_SERVER['HTTP_REFERER'];
                    redirect($CFG->wwwroot.'/enrol/index.php?id='.$course->id, get_string('youneedtoenrol'));
                }
            }
        }
        print_error('nopostnewslettertemp', 'newslettertemp');
    }

    if (!$cm->visible and !has_capability('moodle/course:viewhiddenactivities', $coursecontext)) {
        print_error("activityiscurrentlyhidden");
    }

    if (isset($_SERVER["HTTP_REFERER"])) {
        $SESSION->fromurl = $_SERVER["HTTP_REFERER"];
    } else {
        $SESSION->fromurl = '';
    }


    // Load up the $post variable.

    $post = new stdClass();
    $post->course        = $course->id;
    $post->newslettertemp         = $newslettertemp->id;
    $post->discussion    = 0;           // ie discussion # not defined yet
    $post->parent        = 0;
    $post->subject       = '';
    $post->userid        = $USER->id;
    $post->message       = '';
    $post->messageformat = editors_get_preferred_format();
    $post->messagetrust  = 0;

    if (isset($groupid)) {
        $post->groupid = $groupid;
    } else {
        $post->groupid = groups_get_activity_group($cm);
    }

    newslettertemp_set_return();

} else if (!empty($reply)) {      // User is writing a new reply

    if (! $parent = newslettertemp_get_post_full($reply)) {
        print_error('invalidparentpostid', 'newslettertemp');
    }
    if (! $discussion = $DB->get_record("newslettertemp_discussions", array("id" => $parent->discussion))) {
        print_error('notpartofdiscussion', 'newslettertemp');
    }
    if (! $newslettertemp = $DB->get_record("newslettertemp", array("id" => $discussion->newslettertemp))) {
        print_error('invalidnewslettertempid', 'newslettertemp');
    }
    if (! $course = $DB->get_record("course", array("id" => $discussion->course))) {
        print_error('invalidcourseid');
    }
    if (! $cm = get_coursemodule_from_instance("newslettertemp", $newslettertemp->id, $course->id)) {
        print_error('invalidcoursemodule');
    }

    // Ensure lang, theme, etc. is set up properly. MDL-6926
    $PAGE->set_cm($cm, $course, $newslettertemp);

    $coursecontext = context_course::instance($course->id);
    $modcontext    = context_module::instance($cm->id);

    if (! newslettertemp_user_can_post($newslettertemp, $discussion, $USER, $cm, $course, $modcontext)) {
        if (!isguestuser()) {
            if (!is_enrolled($coursecontext)) {  // User is a guest here!
                $SESSION->wantsurl = qualified_me();
                $SESSION->enrolcancel = $_SERVER['HTTP_REFERER'];
                redirect($CFG->wwwroot.'/enrol/index.php?id='.$course->id, get_string('youneedtoenrol'));
            }
        }
        print_error('nopostnewslettertemp', 'newslettertemp');
    }

    // Make sure user can post here
    if (isset($cm->groupmode) && empty($course->groupmodeforce)) {
        $groupmode =  $cm->groupmode;
    } else {
        $groupmode = $course->groupmode;
    }
    if ($groupmode == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', $modcontext)) {
        if ($discussion->groupid == -1) {
            print_error('nopostnewslettertemp', 'newslettertemp');
        } else {
            if (!groups_is_member($discussion->groupid)) {
                print_error('nopostnewslettertemp', 'newslettertemp');
            }
        }
    }

    if (!$cm->visible and !has_capability('moodle/course:viewhiddenactivities', $coursecontext)) {
        print_error("activityiscurrentlyhidden");
    }

    // Load up the $post variable.

    $post = new stdClass();
    $post->course      = $course->id;
    $post->newslettertemp       = $newslettertemp->id;
    $post->discussion  = $parent->discussion;
    $post->parent      = $parent->id;
    $post->subject     = $parent->subject;
    $post->userid      = $USER->id;
    $post->message     = '';

    $post->groupid = ($discussion->groupid == -1) ? 0 : $discussion->groupid;

    $strre = get_string('re', 'newslettertemp');
    if (!(substr($post->subject, 0, strlen($strre)) == $strre)) {
        $post->subject = $strre.' '.$post->subject;
    }

    unset($SESSION->fromdiscussion);

} else if (!empty($edit)) {  // User is editing their own post

    if (! $post = newslettertemp_get_post_full($edit)) {
        print_error('invalidpostid', 'newslettertemp');
    }
    if ($post->parent) {
        if (! $parent = newslettertemp_get_post_full($post->parent)) {
            print_error('invalidparentpostid', 'newslettertemp');
        }
    }

    if (! $discussion = $DB->get_record("newslettertemp_discussions", array("id" => $post->discussion))) {
        print_error('notpartofdiscussion', 'newslettertemp');
    }
    if (! $newslettertemp = $DB->get_record("newslettertemp", array("id" => $discussion->newslettertemp))) {
        print_error('invalidnewslettertempid', 'newslettertemp');
    }
    if (! $course = $DB->get_record("course", array("id" => $discussion->course))) {
        print_error('invalidcourseid');
    }
    if (!$cm = get_coursemodule_from_instance("newslettertemp", $newslettertemp->id, $course->id)) {
        print_error('invalidcoursemodule');
    } else {
        $modcontext = context_module::instance($cm->id);
    }

    $PAGE->set_cm($cm, $course, $newslettertemp);

    if (!($newslettertemp->type == 'news' && !$post->parent && $discussion->timestart > time())) {
        if (((time() - $post->created) > $CFG->maxeditingtime) and
                    !has_capability('mod/newslettertemp:editanypost', $modcontext)) {
            print_error('maxtimehaspassed', 'newslettertemp', '', format_time($CFG->maxeditingtime));
        }
    }
    if (($post->userid <> $USER->id) and
                !has_capability('mod/newslettertemp:editanypost', $modcontext)) {
        print_error('cannoteditposts', 'newslettertemp');
    }


    // Load up the $post variable.
    $post->edit   = $edit;
    $post->course = $course->id;
    $post->newslettertemp  = $newslettertemp->id;
    $post->groupid = ($discussion->groupid == -1) ? 0 : $discussion->groupid;

    $post = trusttext_pre_edit($post, 'message', $modcontext);

    unset($SESSION->fromdiscussion);


}else if (!empty($delete)) {  // User is deleting a post

    if (! $post = newslettertemp_get_post_full($delete)) {
        print_error('invalidpostid', 'newslettertemp');
    }
    if (! $discussion = $DB->get_record("newslettertemp_discussions", array("id" => $post->discussion))) {
        print_error('notpartofdiscussion', 'newslettertemp');
    }
    if (! $newslettertemp = $DB->get_record("newslettertemp", array("id" => $discussion->newslettertemp))) {
        print_error('invalidnewslettertempid', 'newslettertemp');
    }
    if (!$cm = get_coursemodule_from_instance("newslettertemp", $newslettertemp->id, $newslettertemp->course)) {
        print_error('invalidcoursemodule');
    }
    if (!$course = $DB->get_record('course', array('id' => $newslettertemp->course))) {
        print_error('invalidcourseid');
    }

    require_login($course, false, $cm);
    $modcontext = context_module::instance($cm->id);

    if ( !(($post->userid == $USER->id && has_capability('mod/newslettertemp:deleteownpost', $modcontext))
                || has_capability('mod/newslettertemp:deleteanypost', $modcontext)) ) {
        print_error('cannotdeletepost', 'newslettertemp');
    }


    $replycount = newslettertemp_count_replies($post);

    if (!empty($confirm) && confirm_sesskey()) {    // User has confirmed the delete
        //check user capability to delete post.
        $timepassed = time() - $post->created;
        if (($timepassed > $CFG->maxeditingtime) && !has_capability('mod/newslettertemp:deleteanypost', $modcontext)) {
            print_error("cannotdeletepost", "newslettertemp",
                      newslettertemp_go_back_to("discuss.php?d=$post->discussion"));
        }

        if ($post->totalscore) {
            notice(get_string('couldnotdeleteratings', 'rating'),
                    newslettertemp_go_back_to("discuss.php?d=$post->discussion"));

        } else if ($replycount && !has_capability('mod/newslettertemp:deleteanypost', $modcontext)) {
            print_error("couldnotdeletereplies", "newslettertemp",
                    newslettertemp_go_back_to("discuss.php?d=$post->discussion"));

        } else {
            if (! $post->parent) {  // post is a discussion topic as well, so delete discussion
                if ($newslettertemp->type == 'single') {
                    notice("Sorry, but you are not allowed to delete that discussion!",
                            newslettertemp_go_back_to("discuss.php?d=$post->discussion"));
                }
                newslettertemp_delete_discussion($discussion, false, $course, $cm, $newslettertemp);

                add_to_log($discussion->course, "newslettertemp", "delete discussion",
                           "view.php?id=$cm->id", "$newslettertemp->id", $cm->id);

                redirect("view.php?f=$discussion->newslettertemp");

            } else if (newslettertemp_delete_post($post, has_capability('mod/newslettertemp:deleteanypost', $modcontext),
                $course, $cm, $newslettertemp)) {

                if ($newslettertemp->type == 'single') {
                    // Single discussion newslettertemps are an exception. We show
                    // the newslettertemp itself since it only has one discussion
                    // thread.
                    $discussionurl = "view.php?f=$newslettertemp->id";
                } else {
                    $discussionurl = "discuss.php?d=$post->discussion";
                }

                add_to_log($discussion->course, "newslettertemp", "delete post", $discussionurl, "$post->id", $cm->id);

                redirect(newslettertemp_go_back_to($discussionurl));
            } else {
                print_error('errorwhiledelete', 'newslettertemp');
            }
        }


    } else { // User just asked to delete something

        newslettertemp_set_return();
        $PAGE->navbar->add(get_string('delete', 'newslettertemp'));
        $PAGE->set_title($course->shortname);
        $PAGE->set_heading($course->fullname);

        if ($replycount) {
            if (!has_capability('mod/newslettertemp:deleteanypost', $modcontext)) {
                print_error("couldnotdeletereplies", "newslettertemp",
                      newslettertemp_go_back_to("discuss.php?d=$post->discussion"));
            }
            echo $OUTPUT->header();
            echo $OUTPUT->confirm(get_string("deletesureplural", "newslettertemp", $replycount+1),
                         "post.php?delete=$delete&confirm=$delete",
                         $CFG->wwwroot.'/mod/newslettertemp/discuss.php?d='.$post->discussion.'#p'.$post->id);

            newslettertemp_print_post($post, $discussion, $newslettertemp, $cm, $course, false, false, false);

            if (empty($post->edit)) {
                $newslettertemptracked = newslettertemp_tp_is_tracked($newslettertemp);
                $posts = newslettertemp_get_all_discussion_posts($discussion->id, "created ASC", $newslettertemptracked);
                newslettertemp_print_posts_nested($course, $cm, $newslettertemp, $discussion, $post, false, false, $newslettertemptracked, $posts);
            }
        } else {
            echo $OUTPUT->header();
            echo $OUTPUT->confirm(get_string("deletesure", "newslettertemp", $replycount),
                         "post.php?delete=$delete&confirm=$delete",
                         $CFG->wwwroot.'/mod/newslettertemp/discuss.php?d='.$post->discussion.'#p'.$post->id);
            newslettertemp_print_post($post, $discussion, $newslettertemp, $cm, $course, false, false, false);
        }

    }
    echo $OUTPUT->footer();
    die;


} else if (!empty($prune)) {  // Pruning

    if (!$post = newslettertemp_get_post_full($prune)) {
        print_error('invalidpostid', 'newslettertemp');
    }
    if (!$discussion = $DB->get_record("newslettertemp_discussions", array("id" => $post->discussion))) {
        print_error('notpartofdiscussion', 'newslettertemp');
    }
    if (!$newslettertemp = $DB->get_record("newslettertemp", array("id" => $discussion->newslettertemp))) {
        print_error('invalidnewslettertempid', 'newslettertemp');
    }
    if ($newslettertemp->type == 'single') {
        print_error('cannotsplit', 'newslettertemp');
    }
    if (!$post->parent) {
        print_error('alreadyfirstpost', 'newslettertemp');
    }
    if (!$cm = get_coursemodule_from_instance("newslettertemp", $newslettertemp->id, $newslettertemp->course)) { // For the logs
        print_error('invalidcoursemodule');
    } else {
        $modcontext = context_module::instance($cm->id);
    }
    if (!has_capability('mod/newslettertemp:splitdiscussions', $modcontext)) {
        print_error('cannotsplit', 'newslettertemp');
    }

    if (!empty($name) && confirm_sesskey()) {    // User has confirmed the prune

        $newdiscussion = new stdClass();
        $newdiscussion->course       = $discussion->course;
        $newdiscussion->newslettertemp        = $discussion->newslettertemp;
        $newdiscussion->name         = $name;
        $newdiscussion->firstpost    = $post->id;
        $newdiscussion->userid       = $discussion->userid;
        $newdiscussion->groupid      = $discussion->groupid;
        $newdiscussion->assessed     = $discussion->assessed;
        $newdiscussion->usermodified = $post->userid;
        $newdiscussion->timestart    = $discussion->timestart;
        $newdiscussion->timeend      = $discussion->timeend;

        $newid = $DB->insert_record('newslettertemp_discussions', $newdiscussion);

        $newpost = new stdClass();
        $newpost->id      = $post->id;
        $newpost->parent  = 0;
        $newpost->subject = $name;

        $DB->update_record("newslettertemp_posts", $newpost);

        newslettertemp_change_discussionid($post->id, $newid);

        // update last post in each discussion
        newslettertemp_discussion_update_last_post($discussion->id);
        newslettertemp_discussion_update_last_post($newid);

        add_to_log($discussion->course, "newslettertemp", "prune post",
                       "discuss.php?d=$newid", "$post->id", $cm->id);

        redirect(newslettertemp_go_back_to("discuss.php?d=$newid"));

    } else { // User just asked to prune something

        $course = $DB->get_record('course', array('id' => $newslettertemp->course));

        $PAGE->set_cm($cm);
        $PAGE->set_context($modcontext);
        $PAGE->navbar->add(format_string($post->subject, true), new moodle_url('/mod/newslettertemp/discuss.php', array('d'=>$discussion->id)));
        $PAGE->navbar->add(get_string("prune", "newslettertemp"));
        $PAGE->set_title(format_string($discussion->name).": ".format_string($post->subject));
        $PAGE->set_heading($course->fullname);
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('pruneheading', 'newslettertemp'));
        echo '<center>';

        include('prune.html');

        newslettertemp_print_post($post, $discussion, $newslettertemp, $cm, $course, false, false, false);
        echo '</center>';
    }
    echo $OUTPUT->footer();
    die;
} else {
    print_error('unknowaction');

}

if (!isset($coursecontext)) {
    // Has not yet been set by post.php.
    $coursecontext = context_course::instance($newslettertemp->course);
}


// from now on user must be logged on properly

if (!$cm = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id, $course->id)) { // For the logs
    print_error('invalidcoursemodule');
}
$modcontext = context_module::instance($cm->id);
require_login($course, false, $cm);

if (isguestuser()) {
    // just in case
    print_error('noguest');
}

if (!isset($newslettertemp->maxattachments)) {  // TODO - delete this once we add a field to the newslettertemp table
    $newslettertemp->maxattachments = 3;
}

require_once('post_form.php');

$mform_post = new mod_newslettertemp_post_form('post.php', array('course'=>$course, 'cm'=>$cm, 'coursecontext'=>$coursecontext, 'modcontext'=>$modcontext, 'newslettertemp'=>$newslettertemp, 'post'=>$post), 'post', '', array('id' => 'mformnewslettertemp'));

$draftitemid = file_get_submitted_draft_itemid('attachments');
file_prepare_draft_area($draftitemid, $modcontext->id, 'mod_newslettertemp', 'attachment', empty($post->id)?null:$post->id, mod_newslettertemp_post_form::attachment_options($newslettertemp));

//load data into form NOW!

if ($USER->id != $post->userid) {   // Not the original author, so add a message to the end
    $data = new stdClass();
    $data->date = userdate($post->modified);
    if ($post->messageformat == FORMAT_HTML) {
        $data->name = '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$USER->id.'&course='.$post->course.'">'.
                       fullname($USER).'</a>';
        $post->message .= '<p><span class="edited">('.get_string('editedby', 'newslettertemp', $data).')</span></p>';
    } else {
        $data->name = fullname($USER);
        $post->message .= "\n\n(".get_string('editedby', 'newslettertemp', $data).')';
    }
    unset($data);
}

$formheading = '';
if (!empty($parent)) {
    $heading = get_string("yourreply", "newslettertemp");
    $formheading = get_string('reply', 'newslettertemp');
} else {
    if ($newslettertemp->type == 'qanda') {
        $heading = get_string('yournewquestion', 'newslettertemp');
    } else {
        $heading = get_string('yournewtopic', 'newslettertemp');
    }
}

if (newslettertemp_is_subscribed($USER->id, $newslettertemp->id)) {
    $subscribe = true;

} else if (newslettertemp_user_has_posted($newslettertemp->id, 0, $USER->id)) {
    $subscribe = false;

} else {
    // user not posted yet - use subscription default specified in profile
    $subscribe = !empty($USER->autosubscribe);
}

$draftid_editor = file_get_submitted_draft_itemid('message');
$currenttext = file_prepare_draft_area($draftid_editor, $modcontext->id, 'mod_newslettertemp', 'post', empty($post->id) ? null : $post->id, mod_newslettertemp_post_form::editor_options(), $post->message);
$mform_post->set_data(array(        'attachments'=>$draftitemid,
                                    'general'=>$heading,
                                    'subject'=>$post->subject,
                                    'message'=>array(
                                        'text'=>$currenttext,
                                        'format'=>empty($post->messageformat) ? editors_get_preferred_format() : $post->messageformat,
                                        'itemid'=>$draftid_editor
                                    ),
                                    'subscribe'=>$subscribe?1:0,
                                    'mailnow'=>!empty($post->mailnow),
                                    'userid'=>$post->userid,
                                    'parent'=>$post->parent,
                                    'discussion'=>$post->discussion,
                                    'course'=>$course->id) +
                                    $page_params +

                            (isset($post->format)?array(
                                    'format'=>$post->format):
                                array())+

                            (isset($discussion->timestart)?array(
                                    'timestart'=>$discussion->timestart):
                                array())+

                            (isset($discussion->timeend)?array(
                                    'timeend'=>$discussion->timeend):
                                array())+

                            (isset($post->groupid)?array(
                                    'groupid'=>$post->groupid):
                                array())+

                            (isset($discussion->id)?
                                    array('discussion'=>$discussion->id):
                                    array()));

if ($fromform = $mform_post->get_data()) {

    if (empty($SESSION->fromurl)) {
        $errordestination = "$CFG->wwwroot/mod/newslettertemp/view.php?f=$newslettertemp->id";
    } else {
        $errordestination = $SESSION->fromurl;
    }

    $fromform->itemid        = $fromform->message['itemid'];
    $fromform->messageformat = $fromform->message['format'];
    $fromform->message       = $fromform->message['text'];
    // WARNING: the $fromform->message array has been overwritten, do not use it anymore!
    $fromform->messagetrust  = trusttext_trusted($modcontext);

    $contextcheck = isset($fromform->groupinfo) && has_capability('mod/newslettertemp:movediscussions', $modcontext);

    if ($fromform->edit) {           // Updating a post
        unset($fromform->groupid);
        $fromform->id = $fromform->edit;
        $message = '';

        //fix for bug #4314
        if (!$realpost = $DB->get_record('newslettertemp_posts', array('id' => $fromform->id))) {
            $realpost = new stdClass();
            $realpost->userid = -1;
        }


        // if user has edit any post capability
        // or has either startnewdiscussion or reply capability and is editting own post
        // then he can proceed
        // MDL-7066
        if ( !(($realpost->userid == $USER->id && (has_capability('mod/newslettertemp:replypost', $modcontext)
                            || has_capability('mod/newslettertemp:startdiscussion', $modcontext))) ||
                            has_capability('mod/newslettertemp:editanypost', $modcontext)) ) {
            print_error('cannotupdatepost', 'newslettertemp');
        }

        // If the user has access to all groups and they are changing the group, then update the post.
        if ($contextcheck) {
            if (empty($fromform->groupinfo)) {
                $fromform->groupinfo = -1;
            }
            $DB->set_field('newslettertemp_discussions' ,'groupid' , $fromform->groupinfo, array('firstpost' => $fromform->id));
        }

        $updatepost = $fromform; //realpost
        $updatepost->newslettertemp = $newslettertemp->id;
        if (!newslettertemp_update_post($updatepost, $mform_post, $message)) {
            print_error("couldnotupdate", "newslettertemp", $errordestination);
        }

        // MDL-11818
        if (($newslettertemp->type == 'single') && ($updatepost->parent == '0')){ // updating first post of single discussion type -> updating newslettertemp intro
            $newslettertemp->intro = $updatepost->message;
            $newslettertemp->timemodified = time();
            $DB->update_record("newslettertemp", $newslettertemp);
        }

        $timemessage = 2;
        if (!empty($message)) { // if we're printing stuff about the file upload
            $timemessage = 4;
        }

        if ($realpost->userid == $USER->id) {
            $message .= '<br />'.get_string("postupdated", "newslettertemp");
        } else {
            $realuser = $DB->get_record('user', array('id' => $realpost->userid));
            $message .= '<br />'.get_string("editedpostupdated", "newslettertemp", fullname($realuser));
        }

        if ($subscribemessage = newslettertemp_post_subscription($fromform, $newslettertemp)) {
            $timemessage = 4;
        }
        if ($newslettertemp->type == 'single') {
            // Single discussion newslettertemps are an exception. We show
            // the newslettertemp itself since it only has one discussion
            // thread.
            $discussionurl = "view.php?f=$newslettertemp->id";
        } else {
            $discussionurl = "discuss.php?d=$discussion->id#p$fromform->id";
        }
        add_to_log($course->id, "newslettertemp", "update post",
                "$discussionurl&amp;parent=$fromform->id", "$fromform->id", $cm->id);

        redirect(newslettertemp_go_back_to("$discussionurl"), $message.$subscribemessage, $timemessage);

        exit;


    } else if ($fromform->discussion) { // Adding a new post to an existing discussion
        unset($fromform->groupid);
        $message = '';
        $addpost = $fromform;
        $addpost->newslettertemp=$newslettertemp->id;
        if ($fromform->id = newslettertemp_add_new_post($addpost, $mform_post, $message)) {

            $timemessage = 2;
            if (!empty($message)) { // if we're printing stuff about the file upload
                $timemessage = 4;
            }

            if ($subscribemessage = newslettertemp_post_subscription($fromform, $newslettertemp)) {
                $timemessage = 4;
            }

            if (!empty($fromform->mailnow)) {
                $message .= get_string("postmailnow", "newslettertemp");
                $timemessage = 4;
            } else {
                $message .= '<p>'.get_string("postaddedsuccess", "newslettertemp") . '</p>';
                $message .= '<p>'.get_string("postaddedtimeleft", "newslettertemp", format_time($CFG->maxeditingtime)) . '</p>';
            }

            if ($newslettertemp->type == 'single') {
                // Single discussion newslettertemps are an exception. We show
                // the newslettertemp itself since it only has one discussion
                // thread.
                $discussionurl = "view.php?f=$newslettertemp->id";
            } else {
                $discussionurl = "discuss.php?d=$discussion->id";
            }
            add_to_log($course->id, "newslettertemp", "add post",
                      "$discussionurl&amp;parent=$fromform->id", "$fromform->id", $cm->id);

            // Update completion state
            $completion=new completion_info($course);
            if($completion->is_enabled($cm) &&
                ($newslettertemp->completionreplies || $newslettertemp->completionposts)) {
                $completion->update_state($cm,COMPLETION_COMPLETE);
            }

            redirect(newslettertemp_go_back_to("$discussionurl#p$fromform->id"), $message.$subscribemessage, $timemessage);

        } else {
            print_error("couldnotadd", "newslettertemp", $errordestination);
        }
        exit;

    } else {                     // Adding a new discussion
        if (!newslettertemp_user_can_post_discussion($newslettertemp, $fromform->groupid, -1, $cm, $modcontext)) {
            print_error('cannotcreatediscussion', 'newslettertemp');
        }
        // If the user has access all groups capability let them choose the group.
        if ($contextcheck) {
            $fromform->groupid = $fromform->groupinfo;
        }
        if (empty($fromform->groupid)) {
            $fromform->groupid = -1;
        }

        $fromform->mailnow = empty($fromform->mailnow) ? 0 : 1;

        $discussion = $fromform;
        $discussion->name    = $fromform->subject;

        $newstopic = false;
        if ($newslettertemp->type == 'news' && !$fromform->parent) {
            $newstopic = true;
        }
        $discussion->timestart = $fromform->timestart;
        $discussion->timeend = $fromform->timeend;

        $message = '';
        if ($discussion->id = newslettertemp_add_discussion($discussion, $mform_post, $message)) {

            add_to_log($course->id, "newslettertemp", "add discussion",
                    "discuss.php?d=$discussion->id", "$discussion->id", $cm->id);

            $timemessage = 2;
            if (!empty($message)) { // if we're printing stuff about the file upload
                $timemessage = 4;
            }

            if ($fromform->mailnow) {
                $message .= get_string("postmailnow", "newslettertemp");
                $timemessage = 4;
            } else {
                $message .= '<p>'.get_string("postaddedsuccess", "newslettertemp") . '</p>';
                $message .= '<p>'.get_string("postaddedtimeleft", "newslettertemp", format_time($CFG->maxeditingtime)) . '</p>';
            }

            if ($subscribemessage = newslettertemp_post_subscription($discussion, $newslettertemp)) {
                $timemessage = 4;
            }

            // Update completion status
            $completion=new completion_info($course);
            if($completion->is_enabled($cm) &&
                ($newslettertemp->completiondiscussions || $newslettertemp->completionposts)) {
                $completion->update_state($cm,COMPLETION_COMPLETE);
            }

            redirect(newslettertemp_go_back_to("view.php?f=$fromform->newslettertemp"), $message.$subscribemessage, $timemessage);

        } else {
            print_error("couldnotadd", "newslettertemp", $errordestination);
        }

        exit;
    }
}



// To get here they need to edit a post, and the $post
// variable will be loaded with all the particulars,
// so bring up the form.

// $course, $newslettertemp are defined.  $discussion is for edit and reply only.

if ($post->discussion) {
    if (! $toppost = $DB->get_record("newslettertemp_posts", array("discussion" => $post->discussion, "parent" => 0))) {
        print_error('cannotfindparentpost', 'newslettertemp', '', $post->id);
    }
} else {
    $toppost = new stdClass();
    $toppost->subject = ($newslettertemp->type == "news") ? get_string("addanewtopic", "newslettertemp") :
                                                   get_string("addanewdiscussion", "newslettertemp");
}

if (empty($post->edit)) {
    $post->edit = '';
}

if (empty($discussion->name)) {
    if (empty($discussion)) {
        $discussion = new stdClass();
    }
    $discussion->name = $newslettertemp->name;
}
if ($newslettertemp->type == 'single') {
    // There is only one discussion thread for this newslettertemp type. We should
    // not show the discussion name (same as newslettertemp name in this case) in
    // the breadcrumbs.
    $strdiscussionname = '';
} else {
    // Show the discussion name in the breadcrumbs.
    $strdiscussionname = format_string($discussion->name).':';
}

$forcefocus = empty($reply) ? NULL : 'message';

if (!empty($discussion->id)) {
    $PAGE->navbar->add(format_string($toppost->subject, true), "discuss.php?d=$discussion->id");
}

if ($post->parent) {
    $PAGE->navbar->add(get_string('reply', 'newslettertemp'));
}

if ($edit) {
    $PAGE->navbar->add(get_string('edit', 'newslettertemp'));
}

$PAGE->set_title("$course->shortname: $strdiscussionname ".format_string($toppost->subject));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();

// checkup
if (!empty($parent) && !newslettertemp_user_can_see_post($newslettertemp, $discussion, $post, null, $cm)) {
    print_error('cannotreply', 'newslettertemp');
}
if (empty($parent) && empty($edit) && !newslettertemp_user_can_post_discussion($newslettertemp, $groupid, -1, $cm, $modcontext)) {
    print_error('cannotcreatediscussion', 'newslettertemp');
}

if ($newslettertemp->type == 'qanda'
            && !has_capability('mod/newslettertemp:viewqandawithoutposting', $modcontext)
            && !empty($discussion->id)
            && !newslettertemp_user_has_posted($newslettertemp->id, $discussion->id, $USER->id)) {
    echo $OUTPUT->notification(get_string('qandanotify','newslettertemp'));
}

newslettertemp_check_throttling($newslettertemp, $cm);

if (!empty($parent)) {
    if (! $discussion = $DB->get_record('newslettertemp_discussions', array('id' => $parent->discussion))) {
        print_error('notpartofdiscussion', 'newslettertemp');
    }

    newslettertemp_print_post($parent, $discussion, $newslettertemp, $cm, $course, false, false, false);
    if (empty($post->edit)) {
        if ($newslettertemp->type != 'qanda' || newslettertemp_user_can_see_discussion($newslettertemp, $discussion, $modcontext)) {
            $newslettertemptracked = newslettertemp_tp_is_tracked($newslettertemp);
            $posts = newslettertemp_get_all_discussion_posts($discussion->id, "created ASC", $newslettertemptracked);
            newslettertemp_print_posts_threaded($course, $cm, $newslettertemp, $discussion, $parent, 0, false, $newslettertemptracked, $posts);
        }
    }
} else {
    if (!empty($newslettertemp->intro)) {
        echo $OUTPUT->box(format_module_intro('newslettertemp', $newslettertemp, $cm->id), 'generalbox', 'intro');

        if (!empty($CFG->enableplagiarism)) {
            require_once($CFG->libdir.'/plagiarismlib.php');
            echo plagiarism_print_disclosure($cm->id);
        }
    }
}

if (!empty($formheading)) {
    echo $OUTPUT->heading($formheading, 2, array('class' => 'accesshide'));
}
$mform_post->display();

echo $OUTPUT->footer();

