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
 * @package    mod
 * @subpackage newslettertemp
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/** Include required files */
require_once($CFG->libdir.'/filelib.php');
require_once($CFG->libdir.'/eventslib.php');
require_once($CFG->dirroot.'/user/selector/lib.php');
require_once($CFG->dirroot.'/mod/newslettertemp/post_form.php');

/// CONSTANTS ///////////////////////////////////////////////////////////

define('NEWSLETTERTEMP_MODE_FLATOLDEST', 1);
define('NEWSLETTERTEMP_MODE_FLATNEWEST', -1);
define('NEWSLETTERTEMP_MODE_THREADED', 2);
define('NEWSLETTERTEMP_MODE_NESTED', 3);

define('NEWSLETTERTEMP_CHOOSESUBSCRIBE', 0);
define('NEWSLETTERTEMP_FORCESUBSCRIBE', 1);
define('NEWSLETTERTEMP_INITIALSUBSCRIBE', 2);
define('NEWSLETTERTEMP_DISALLOWSUBSCRIBE',3);

define('NEWSLETTERTEMP_TRACKING_OFF', 0);
define('NEWSLETTERTEMP_TRACKING_OPTIONAL', 1);
define('NEWSLETTERTEMP_TRACKING_ON', 2);

if (!defined('NEWSLETTERTEMP_CRON_USER_CACHE')) {
    /** Defines how many full user records are cached in newslettertemp cron. */
    define('NEWSLETTERTEMP_CRON_USER_CACHE', 5000);
}

/// STANDARD FUNCTIONS ///////////////////////////////////////////////////////////

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @param stdClass $newslettertemp add newslettertemp instance
 * @param mod_newslettertemp_mod_form $mform
 * @return int intance id
 */
function newslettertemp_add_instance($newslettertemp, $mform = null) {
    global $CFG, $DB;

    $newslettertemp->timemodified = time();

    if (empty($newslettertemp->assessed)) {
        $newslettertemp->assessed = 0;
    }

    if (empty($newslettertemp->ratingtime) or empty($newslettertemp->assessed)) {
        $newslettertemp->assesstimestart  = 0;
        $newslettertemp->assesstimefinish = 0;
    }

    $newslettertemp->id = $DB->insert_record('newslettertemp', $newslettertemp);
    $modcontext = context_module::instance($newslettertemp->coursemodule);

    if ($newslettertemp->type == 'single') {  // Create related discussion.
        $discussion = new stdClass();
        $discussion->course        = $newslettertemp->course;
        $discussion->newslettertemp         = $newslettertemp->id;
        $discussion->name          = $newslettertemp->name;
        $discussion->assessed      = $newslettertemp->assessed;
        $discussion->message       = $newslettertemp->intro;
        $discussion->messageformat = $newslettertemp->introformat;
        $discussion->messagetrust  = trusttext_trusted(context_course::instance($newslettertemp->course));
        $discussion->mailnow       = false;
        $discussion->groupid       = -1;

        $message = '';

        $discussion->id = newslettertemp_add_discussion($discussion, null, $message);

        if ($mform and $draftid = file_get_submitted_draft_itemid('introeditor')) {
            // ugly hack - we need to copy the files somehow
            $discussion = $DB->get_record('newslettertemp_discussions', array('id'=>$discussion->id), '*', MUST_EXIST);
            $post = $DB->get_record('newslettertemp_posts', array('id'=>$discussion->firstpost), '*', MUST_EXIST);

            $post->message = file_save_draft_area_files($draftid, $modcontext->id, 'mod_newslettertemp', 'post', $post->id,
                    mod_newslettertemp_post_form::attachment_options($newslettertemp), $post->message);
            $DB->set_field('newslettertemp_posts', 'message', $post->message, array('id'=>$post->id));
        }
    }

    if ($newslettertemp->forcesubscribe == NEWSLETTERTEMP_INITIALSUBSCRIBE) {
        $users = newslettertemp_get_potential_subscribers($modcontext, 0, 'u.id, u.email');
        foreach ($users as $user) {
            newslettertemp_subscribe($user->id, $newslettertemp->id);
        }
    }

    newslettertemp_grade_item_update($newslettertemp);

    return $newslettertemp->id;
}


/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @global object
 * @param object $newslettertemp newslettertemp instance (with magic quotes)
 * @return bool success
 */
function newslettertemp_update_instance($newslettertemp, $mform) {
    global $DB, $OUTPUT, $USER;

    $newslettertemp->timemodified = time();
    $newslettertemp->id           = $newslettertemp->instance;

    if (empty($newslettertemp->assessed)) {
        $newslettertemp->assessed = 0;
    }

    if (empty($newslettertemp->ratingtime) or empty($newslettertemp->assessed)) {
        $newslettertemp->assesstimestart  = 0;
        $newslettertemp->assesstimefinish = 0;
    }

    $oldnewslettertemp = $DB->get_record('newslettertemp', array('id'=>$newslettertemp->id));

    // MDL-3942 - if the aggregation type or scale (i.e. max grade) changes then recalculate the grades for the entire newslettertemp
    // if  scale changes - do we need to recheck the ratings, if ratings higher than scale how do we want to respond?
    // for count and sum aggregation types the grade we check to make sure they do not exceed the scale (i.e. max score) when calculating the grade
    if (($oldnewslettertemp->assessed<>$newslettertemp->assessed) or ($oldnewslettertemp->scale<>$newslettertemp->scale)) {
        newslettertemp_update_grades($newslettertemp); // recalculate grades for the newslettertemp
    }

    if ($newslettertemp->type == 'single') {  // Update related discussion and post.
        $discussions = $DB->get_records('newslettertemp_discussions', array('newslettertemp'=>$newslettertemp->id), 'timemodified ASC');
        if (!empty($discussions)) {
            if (count($discussions) > 1) {
                echo $OUTPUT->notification(get_string('warnformorepost', 'newslettertemp'));
            }
            $discussion = array_pop($discussions);
        } else {
            // try to recover by creating initial discussion - MDL-16262
            $discussion = new stdClass();
            $discussion->course          = $newslettertemp->course;
            $discussion->newslettertemp  = $newslettertemp->id;
            $discussion->name            = $newslettertemp->name;
            $discussion->assessed        = $newslettertemp->assessed;
            $discussion->message         = $newslettertemp->intro;
            $discussion->messageformat   = $newslettertemp->introformat;
            $discussion->messagetrust    = true;
            $discussion->mailnow         = false;
            $discussion->groupid         = -1;

            $message = '';

            newslettertemp_add_discussion($discussion, null, $message);

            if (! $discussion = $DB->get_record('newslettertemp_discussions', array('newslettertemp'=>$newslettertemp->id))) {
                print_error('cannotadd', 'newslettertemp');
            }
        }
        if (! $post = $DB->get_record('newslettertemp_posts', array('id'=>$discussion->firstpost))) {
            print_error('cannotfindfirstpost', 'newslettertemp');
        }

        $cm         = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id);
        $modcontext = context_module::instance($cm->id, MUST_EXIST);

        if ($mform and $draftid = file_get_submitted_draft_itemid('introeditor')) {
            // ugly hack - we need to copy the files somehow
            $discussion = $DB->get_record('newslettertemp_discussions', array('id'=>$discussion->id), '*', MUST_EXIST);
            $post = $DB->get_record('newslettertemp_posts', array('id'=>$discussion->firstpost), '*', MUST_EXIST);

            $post->message = file_save_draft_area_files($draftid, $modcontext->id, 'mod_newslettertemp', 'post', $post->id,
                    mod_newslettertemp_post_form::editor_options(), $post->message);
        }

        $post->subject       = $newslettertemp->name;
        $post->message       = $newslettertemp->intro;
        $post->messageformat = $newslettertemp->introformat;
        $post->messagetrust  = trusttext_trusted($modcontext);
        $post->modified      = $newslettertemp->timemodified;
        $post->userid        = $USER->id;    // MDL-18599, so that current teacher can take ownership of activities

        $DB->update_record('newslettertemp_posts', $post);
        $discussion->name = $newslettertemp->name;
        $DB->update_record('newslettertemp_discussions', $discussion);
    }

    $DB->update_record('newslettertemp', $newslettertemp);

    newslettertemp_grade_item_update($newslettertemp);

    return true;
}


/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @global object
 * @param int $id newslettertemp instance id
 * @return bool success
 */
function newslettertemp_delete_instance($id) {
    global $DB;

    if (!$newslettertemp = $DB->get_record('newslettertemp', array('id'=>$id))) {
        return false;
    }
    if (!$cm = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id)) {
        return false;
    }
    if (!$course = $DB->get_record('course', array('id'=>$cm->course))) {
        return false;
    }

    $context = context_module::instance($cm->id);

    // now get rid of all files
    $fs = get_file_storage();
    $fs->delete_area_files($context->id);

    $result = true;

    if ($discussions = $DB->get_records('newslettertemp_discussions', array('newslettertemp'=>$newslettertemp->id))) {
        foreach ($discussions as $discussion) {
            if (!newslettertemp_delete_discussion($discussion, true, $course, $cm, $newslettertemp)) {
                $result = false;
            }
        }
    }

    if (!$DB->delete_records('newslettertemp_subscriptions', array('newslettertemp'=>$newslettertemp->id))) {
        $result = false;
    }

    newslettertemp_tp_delete_read_records(-1, -1, -1, $newslettertemp->id);

    if (!$DB->delete_records('newslettertemp', array('id'=>$newslettertemp->id))) {
        $result = false;
    }

    newslettertemp_grade_item_delete($newslettertemp);

    return $result;
}


/**
 * Indicates API features that the newslettertemp supports.
 *
 * @uses FEATURE_GROUPS
 * @uses FEATURE_GROUPINGS
 * @uses FEATURE_GROUPMEMBERSONLY
 * @uses FEATURE_MOD_INTRO
 * @uses FEATURE_COMPLETION_TRACKS_VIEWS
 * @uses FEATURE_COMPLETION_HAS_RULES
 * @uses FEATURE_GRADE_HAS_GRADE
 * @uses FEATURE_GRADE_OUTCOMES
 * @param string $feature
 * @return mixed True if yes (some features may use other values)
 */
function newslettertemp_supports($feature) {
    switch($feature) {
        case FEATURE_GROUPS:                  return true;
        case FEATURE_GROUPINGS:               return true;
        case FEATURE_GROUPMEMBERSONLY:        return true;
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_COMPLETION_HAS_RULES:    return true;
        case FEATURE_GRADE_HAS_GRADE:         return true;
        case FEATURE_GRADE_OUTCOMES:          return true;
        case FEATURE_RATE:                    return true;
        case FEATURE_BACKUP_MOODLE2:          return true;
        case FEATURE_SHOW_DESCRIPTION:        return true;
        case FEATURE_PLAGIARISM:              return true;

        default: return null;
    }
}


/**
 * Obtains the automatic completion state for this newslettertemp based on any conditions
 * in newslettertemp settings.
 *
 * @global object
 * @global object
 * @param object $course Course
 * @param object $cm Course-module
 * @param int $userid User ID
 * @param bool $type Type of comparison (or/and; can be used as return value if no conditions)
 * @return bool True if completed, false if not. (If no conditions, then return
 *   value depends on comparison type)
 */
function newslettertemp_get_completion_state($course,$cm,$userid,$type) {
    global $CFG,$DB;

    // Get newslettertemp details
    if (!($newslettertemp=$DB->get_record('newslettertemp',array('id'=>$cm->instance)))) {
        throw new Exception("Can't find newslettertemp {$cm->instance}");
    }

    $result=$type; // Default return value

    $postcountparams=array('userid'=>$userid,'newslettertempid'=>$newslettertemp->id);
    $postcountsql="
SELECT
    COUNT(1)
FROM
    {newslettertemp_posts} fp
    INNER JOIN {newslettertemp_discussions} fd ON fp.discussion=fd.id
WHERE
    fp.userid=:userid AND fd.newslettertemp=:newslettertempid";

    if ($newslettertemp->completiondiscussions) {
        $value = $newslettertemp->completiondiscussions <=
                 $DB->count_records('newslettertemp_discussions',array('newslettertemp'=>$newslettertemp->id,'userid'=>$userid));
        if ($type == COMPLETION_AND) {
            $result = $result && $value;
        } else {
            $result = $result || $value;
        }
    }
    if ($newslettertemp->completionreplies) {
        $value = $newslettertemp->completionreplies <=
                 $DB->get_field_sql( $postcountsql.' AND fp.parent<>0',$postcountparams);
        if ($type==COMPLETION_AND) {
            $result = $result && $value;
        } else {
            $result = $result || $value;
        }
    }
    if ($newslettertemp->completionposts) {
        $value = $newslettertemp->completionposts <= $DB->get_field_sql($postcountsql,$postcountparams);
        if ($type == COMPLETION_AND) {
            $result = $result && $value;
        } else {
            $result = $result || $value;
        }
    }

    return $result;
}

/**
 * Create a message-id string to use in the custom headers of newslettertemp notification emails
 *
 * message-id is used by email clients to identify emails and to nest conversations
 *
 * @param int $postid The ID of the newslettertemp post we are notifying the user about
 * @param int $usertoid The ID of the user being notified
 * @param string $hostname The server's hostname
 * @return string A unique message-id
 */
function newslettertemp_get_email_message_id($postid, $usertoid, $hostname) {
    return '<'.hash('sha256',$postid.'to'.$usertoid).'@'.$hostname.'>';
}

/**
 * Removes properties from user record that are not necessary
 * for sending post notifications.
 * @param stdClass $user
 * @return void, $user parameter is modified
 */
function newslettertemp_cron_minimise_user_record(stdClass $user) {

    // We store large amount of users in one huge array,
    // make sure we do not store info there we do not actually need
    // in mail generation code or messaging.

    unset($user->institution);
    unset($user->department);
    unset($user->address);
    unset($user->city);
    unset($user->url);
    unset($user->currentlogin);
    unset($user->description);
    unset($user->descriptionformat);
}

/**
 * Function to be run periodically according to the moodle cron
 * Finds all posts that have yet to be mailed out, and mails them
 * out to all subscribers
 *
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses CONTEXT_COURSE
 * @uses SITEID
 * @uses FORMAT_PLAIN
 * @return void
 */
function newslettertemp_cron() {
    global $CFG, $USER, $DB;

    $site = get_site();

    // All users that are subscribed to any post that needs sending,
    // please increase $CFG->extramemorylimit on large sites that
    // send notifications to a large number of users.
    $users = array();
    $userscount = 0; // Cached user counter - count($users) in PHP is horribly slow!!!

    // status arrays
    $mailcount  = array();
    $errorcount = array();

    // caches
    $discussions     = array();
    $newslettertemps          = array();
    $courses         = array();
    $coursemodules   = array();
    $subscribedusers = array();


    // Posts older than 2 days will not be mailed.  This is to avoid the problem where
    // cron has not been running for a long time, and then suddenly people are flooded
    // with mail from the past few weeks or months
    $timenow   = time();
    $endtime   = $timenow - $CFG->maxeditingtime;
    $starttime = $endtime - 48 * 3600;   // Two days earlier

    if ($posts = newslettertemp_get_unmailed_posts($starttime, $endtime, $timenow)) {
        // Mark them all now as being mailed.  It's unlikely but possible there
        // might be an error later so that a post is NOT actually mailed out,
        // but since mail isn't crucial, we can accept this risk.  Doing it now
        // prevents the risk of duplicated mails, which is a worse problem.

        if (!newslettertemp_mark_old_posts_as_mailed($endtime)) {
            mtrace('Errors occurred while trying to mark some posts as being mailed.');
            return false;  // Don't continue trying to mail them, in case we are in a cron loop
        }

        // checking post validity, and adding users to loop through later
        foreach ($posts as $pid => $post) {

            $discussionid = $post->discussion;
            if (!isset($discussions[$discussionid])) {
                if ($discussion = $DB->get_record('newslettertemp_discussions', array('id'=> $post->discussion))) {
                    $discussions[$discussionid] = $discussion;
                } else {
                    mtrace('Could not find discussion '.$discussionid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            $newslettertempid = $discussions[$discussionid]->newslettertemp;
            if (!isset($newslettertemps[$newslettertempid])) {
                if ($newslettertemp = $DB->get_record('newslettertemp', array('id' => $newslettertempid))) {
                    $newslettertemps[$newslettertempid] = $newslettertemp;
                } else {
                    mtrace('Could not find newslettertemp '.$newslettertempid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            $courseid = $newslettertemps[$newslettertempid]->course;
            if (!isset($courses[$courseid])) {
                if ($course = $DB->get_record('course', array('id' => $courseid))) {
                    $courses[$courseid] = $course;
                } else {
                    mtrace('Could not find course '.$courseid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            if (!isset($coursemodules[$newslettertempid])) {
                if ($cm = get_coursemodule_from_instance('newslettertemp', $newslettertempid, $courseid)) {
                    $coursemodules[$newslettertempid] = $cm;
                } else {
                    mtrace('Could not find course module for newslettertemp '.$newslettertempid);
                    unset($posts[$pid]);
                    continue;
                }
            }


            // caching subscribed users of each newslettertemp
            if (!isset($subscribedusers[$newslettertempid])) {
                $modcontext = context_module::instance($coursemodules[$newslettertempid]->id);
                if ($subusers = newslettertemp_subscribed_users($courses[$courseid], $newslettertemps[$newslettertempid], 0, $modcontext, "u.*")) {
                    foreach ($subusers as $postuser) {
                        // this user is subscribed to this newslettertemp
                        $subscribedusers[$newslettertempid][$postuser->id] = $postuser->id;
                        $userscount++;
                        if ($userscount > NEWSLETTERTEMP_CRON_USER_CACHE) {
                            // Store minimal user info.
                            $minuser = new stdClass();
                            $minuser->id = $postuser->id;
                            $users[$postuser->id] = $minuser;
                        } else {
                            // Cache full user record.
                            newslettertemp_cron_minimise_user_record($postuser);
                            $users[$postuser->id] = $postuser;
                        }
                    }
                    // Release memory.
                    unset($subusers);
                    unset($postuser);
                }
            }

            $mailcount[$pid] = 0;
            $errorcount[$pid] = 0;
        }
    }

    if ($users && $posts) {

        $urlinfo = parse_url($CFG->wwwroot);
        $hostname = $urlinfo['host'];

        foreach ($users as $userto) {

            @set_time_limit(120); // terminate if processing of any account takes longer than 2 minutes

            mtrace('Processing user '.$userto->id);

            // Init user caches - we keep the cache for one cycle only,
            // otherwise it could consume too much memory.
            if (isset($userto->username)) {
                $userto = clone($userto);
            } else {
                $userto = $DB->get_record('user', array('id' => $userto->id));
                newslettertemp_cron_minimise_user_record($userto);
            }
            $userto->viewfullnames = array();
            $userto->canpost       = array();
            $userto->markposts     = array();

            // set this so that the capabilities are cached, and environment matches receiving user
            cron_setup_user($userto);

            // reset the caches
            foreach ($coursemodules as $newslettertempid=>$unused) {
                $coursemodules[$newslettertempid]->cache       = new stdClass();
                $coursemodules[$newslettertempid]->cache->caps = array();
                unset($coursemodules[$newslettertempid]->uservisible);
            }

            foreach ($posts as $pid => $post) {

                // Set up the environment for the post, discussion, newslettertemp, course
                $discussion = $discussions[$post->discussion];
                $newslettertemp      = $newslettertemps[$discussion->newslettertemp];
                $course     = $courses[$newslettertemp->course];
                $cm         =& $coursemodules[$newslettertemp->id];

                // Do some checks  to see if we can bail out now
                // Only active enrolled users are in the list of subscribers
                if (!isset($subscribedusers[$newslettertemp->id][$userto->id])) {
                    continue; // user does not subscribe to this newslettertemp
                }

                // Don't send email if the newslettertemp is Q&A and the user has not posted
                // Initial topics are still mailed
                if ($newslettertemp->type == 'qanda' && !newslettertemp_get_user_posted_time($discussion->id, $userto->id) && $pid != $discussion->firstpost) {
                    mtrace('Did not email '.$userto->id.' because user has not posted in discussion');
                    continue;
                }

                // Get info about the sending user
                if (array_key_exists($post->userid, $users)) { // we might know him/her already
                    $userfrom = $users[$post->userid];
                    if (!isset($userfrom->idnumber)) {
                        // Minimalised user info, fetch full record.
                        $userfrom = $DB->get_record('user', array('id' => $userfrom->id));
                        newslettertemp_cron_minimise_user_record($userfrom);
                    }

                } else if ($userfrom = $DB->get_record('user', array('id' => $post->userid))) {
                    newslettertemp_cron_minimise_user_record($userfrom);
                    // Fetch only once if possible, we can add it to user list, it will be skipped anyway.
                    if ($userscount <= NEWSLETTERTEMP_CRON_USER_CACHE) {
                        $userscount++;
                        $users[$userfrom->id] = $userfrom;
                    }

                } else {
                    mtrace('Could not find user '.$post->userid);
                    continue;
                }

                //if we want to check that userto and userfrom are not the same person this is probably the spot to do it

                // setup global $COURSE properly - needed for roles and languages
                cron_setup_user($userto, $course);

                // Fill caches
                if (!isset($userto->viewfullnames[$newslettertemp->id])) {
                    $modcontext = context_module::instance($cm->id);
                    $userto->viewfullnames[$newslettertemp->id] = has_capability('moodle/site:viewfullnames', $modcontext);
                }
                if (!isset($userto->canpost[$discussion->id])) {
                    $modcontext = context_module::instance($cm->id);
                    $userto->canpost[$discussion->id] = newslettertemp_user_can_post($newslettertemp, $discussion, $userto, $cm, $course, $modcontext);
                }
                if (!isset($userfrom->groups[$newslettertemp->id])) {
                    if (!isset($userfrom->groups)) {
                        $userfrom->groups = array();
                        if (isset($users[$userfrom->id])) {
                            $users[$userfrom->id]->groups = array();
                        }
                    }
                    $userfrom->groups[$newslettertemp->id] = groups_get_all_groups($course->id, $userfrom->id, $cm->groupingid);
                    if (isset($users[$userfrom->id])) {
                        $users[$userfrom->id]->groups[$newslettertemp->id] = $userfrom->groups[$newslettertemp->id];
                    }
                }

                // Make sure groups allow this user to see this email
                if ($discussion->groupid > 0 and $groupmode = groups_get_activity_groupmode($cm, $course)) {   // Groups are being used
                    if (!groups_group_exists($discussion->groupid)) { // Can't find group
                        continue;                           // Be safe and don't send it to anyone
                    }

                    if (!groups_is_member($discussion->groupid) and !has_capability('moodle/site:accessallgroups', $modcontext)) {
                        // do not send posts from other groups when in SEPARATEGROUPS or VISIBLEGROUPS
                        continue;
                    }
                }

                // Make sure we're allowed to see it...
                if (!newslettertemp_user_can_see_post($newslettertemp, $discussion, $post, NULL, $cm)) {
                    mtrace('user '.$userto->id. ' can not see '.$post->id);
                    continue;
                }

                // OK so we need to send the email.

                // Does the user want this post in a digest?  If so postpone it for now.
                if ($userto->maildigest > 0) {
                    // This user wants the mails to be in digest form
                    $queue = new stdClass();
                    $queue->userid       = $userto->id;
                    $queue->discussionid = $discussion->id;
                    $queue->postid       = $post->id;
                    $queue->timemodified = $post->created;
                    $DB->insert_record('newslettertemp_queue', $queue);
                    continue;
                }


                // Prepare to actually send the post now, and build up the content

                $cleannewslettertempname = str_replace('"', "'", strip_tags(format_string($newslettertemp->name)));

                $userfrom->customheaders = array (  // Headers to make emails easier to track
                           'Precedence: Bulk',
                           'List-Id: "'.$cleannewslettertempname.'" <moodlenewslettertemp'.$newslettertemp->id.'@'.$hostname.'>',
                           'List-Help: '.$CFG->wwwroot.'/mod/newslettertemp/view.php?f='.$newslettertemp->id,
                           'Message-ID: '.newslettertemp_get_email_message_id($post->id, $userto->id, $hostname),
                           'X-Course-Id: '.$course->id,
                           'X-Course-Name: '.format_string($course->fullname, true)
                );

                if ($post->parent) {  // This post is a reply, so add headers for threading (see MDL-22551)
                    $userfrom->customheaders[] = 'In-Reply-To: '.newslettertemp_get_email_message_id($post->parent, $userto->id, $hostname);
                    $userfrom->customheaders[] = 'References: '.newslettertemp_get_email_message_id($post->parent, $userto->id, $hostname);
                }

                $shortname = format_string($course->shortname, true, array('context' => context_course::instance($course->id)));

                $postsubject = html_to_text("$shortname: ".format_string($post->subject, true));
                $posttext = newslettertemp_make_mail_text($course, $cm, $newslettertemp, $discussion, $post, $userfrom, $userto);
                $posthtml = newslettertemp_make_mail_html($course, $cm, $newslettertemp, $discussion, $post, $userfrom, $userto);

                // Send the post now!

                mtrace('Sending ', '');

                $eventdata = new stdClass();
                $eventdata->component        = 'mod_newslettertemp';
                $eventdata->name             = 'posts';
                $eventdata->userfrom         = $userfrom;
                $eventdata->userto           = $userto;
                $eventdata->subject          = $postsubject;
                $eventdata->fullmessage      = $posttext;
                $eventdata->fullmessageformat = FORMAT_PLAIN;
                $eventdata->fullmessagehtml  = $posthtml;
                $eventdata->notification = 1;

                $smallmessagestrings = new stdClass();
                $smallmessagestrings->user = fullname($userfrom);
                $smallmessagestrings->newslettertempname = "$shortname: ".format_string($newslettertemp->name,true).": ".$discussion->name;
                $smallmessagestrings->message = $post->message;
                //make sure strings are in message recipients language
                $eventdata->smallmessage = get_string_manager()->get_string('smallmessage', 'newslettertemp', $smallmessagestrings, $userto->lang);

                $eventdata->contexturl = "{$CFG->wwwroot}/mod/newslettertemp/discuss.php?d={$discussion->id}#p{$post->id}";
                $eventdata->contexturlname = $discussion->name;

                $mailresult = message_send($eventdata);
                if (!$mailresult){
                    mtrace("Error: mod/newslettertemp/lib.php newslettertemp_cron(): Could not send out mail for id $post->id to user $userto->id".
                         " ($userto->email) .. not trying again.");
                    add_to_log($course->id, 'newslettertemp', 'mail error', "discuss.php?d=$discussion->id#p$post->id",
                               substr(format_string($post->subject,true),0,30), $cm->id, $userto->id);
                    $errorcount[$post->id]++;
                } else {
                    $mailcount[$post->id]++;

                // Mark post as read if newslettertemp_usermarksread is set off
                    if (!$CFG->newslettertemp_usermarksread) {
                        $userto->markposts[$post->id] = $post->id;
                    }
                }

                mtrace('post '.$post->id. ': '.$post->subject);
            }

            // mark processed posts as read
            newslettertemp_tp_mark_posts_read($userto, $userto->markposts);
            unset($userto);
        }
    }

    if ($posts) {
        foreach ($posts as $post) {
            mtrace($mailcount[$post->id]." users were sent post $post->id, '$post->subject'");
            if ($errorcount[$post->id]) {
                $DB->set_field("newslettertemp_posts", "mailed", "2", array("id" => "$post->id"));
            }
        }
    }

    // release some memory
    unset($subscribedusers);
    unset($mailcount);
    unset($errorcount);

    cron_setup_user();

    $sitetimezone = $CFG->timezone;

    // Now see if there are any digest mails waiting to be sent, and if we should send them

    mtrace('Starting digest processing...');

    @set_time_limit(300); // terminate if not able to fetch all digests in 5 minutes

    if (!isset($CFG->digestmailtimelast)) {    // To catch the first time
        set_config('digestmailtimelast', 0);
    }

    $timenow = time();
    $digesttime = usergetmidnight($timenow, $sitetimezone) + ($CFG->digestmailtime * 3600);

    // Delete any really old ones (normally there shouldn't be any)
    $weekago = $timenow - (7 * 24 * 3600);
    $DB->delete_records_select('newslettertemp_queue', "timemodified < ?", array($weekago));
    mtrace ('Cleaned old digest records');

    if ($CFG->digestmailtimelast < $digesttime and $timenow > $digesttime) {

        mtrace('Sending newslettertemp digests: '.userdate($timenow, '', $sitetimezone));

        $digestposts_rs = $DB->get_recordset_select('newslettertemp_queue', "timemodified < ?", array($digesttime));

        if ($digestposts_rs->valid()) {

            // We have work to do
            $usermailcount = 0;

            //caches - reuse the those filled before too
            $discussionposts = array();
            $userdiscussions = array();

            foreach ($digestposts_rs as $digestpost) {
                if (!isset($posts[$digestpost->postid])) {
                    if ($post = $DB->get_record('newslettertemp_posts', array('id' => $digestpost->postid))) {
                        $posts[$digestpost->postid] = $post;
                    } else {
                        continue;
                    }
                }
                $discussionid = $digestpost->discussionid;
                if (!isset($discussions[$discussionid])) {
                    if ($discussion = $DB->get_record('newslettertemp_discussions', array('id' => $discussionid))) {
                        $discussions[$discussionid] = $discussion;
                    } else {
                        continue;
                    }
                }
                $newslettertempid = $discussions[$discussionid]->newslettertemp;
                if (!isset($newslettertemps[$newslettertempid])) {
                    if ($newslettertemp = $DB->get_record('newslettertemp', array('id' => $newslettertempid))) {
                        $newslettertemps[$newslettertempid] = $newslettertemp;
                    } else {
                        continue;
                    }
                }

                $courseid = $newslettertemps[$newslettertempid]->course;
                if (!isset($courses[$courseid])) {
                    if ($course = $DB->get_record('course', array('id' => $courseid))) {
                        $courses[$courseid] = $course;
                    } else {
                        continue;
                    }
                }

                if (!isset($coursemodules[$newslettertempid])) {
                    if ($cm = get_coursemodule_from_instance('newslettertemp', $newslettertempid, $courseid)) {
                        $coursemodules[$newslettertempid] = $cm;
                    } else {
                        continue;
                    }
                }
                $userdiscussions[$digestpost->userid][$digestpost->discussionid] = $digestpost->discussionid;
                $discussionposts[$digestpost->discussionid][$digestpost->postid] = $digestpost->postid;
            }
            $digestposts_rs->close(); /// Finished iteration, let's close the resultset

            // Data collected, start sending out emails to each user
            foreach ($userdiscussions as $userid => $thesediscussions) {

                @set_time_limit(120); // terminate if processing of any account takes longer than 2 minutes

                cron_setup_user();

                mtrace(get_string('processingdigest', 'newslettertemp', $userid), '... ');

                // First of all delete all the queue entries for this user
                $DB->delete_records_select('newslettertemp_queue', "userid = ? AND timemodified < ?", array($userid, $digesttime));

                // Init user caches - we keep the cache for one cycle only,
                // otherwise it would unnecessarily consume memory.
                if (array_key_exists($userid, $users) and isset($users[$userid]->username)) {
                    $userto = clone($users[$userid]);
                } else {
                    $userto = $DB->get_record('user', array('id' => $userid));
                    newslettertemp_cron_minimise_user_record($userto);
                }
                $userto->viewfullnames = array();
                $userto->canpost       = array();
                $userto->markposts     = array();

                // Override the language and timezone of the "current" user, so that
                // mail is customised for the receiver.
                cron_setup_user($userto);

                $postsubject = get_string('digestmailsubject', 'newslettertemp', format_string($site->shortname, true));

                $headerdata = new stdClass();
                $headerdata->sitename = format_string($site->fullname, true);
                $headerdata->userprefs = $CFG->wwwroot.'/user/edit.php?id='.$userid.'&amp;course='.$site->id;

                $posttext = get_string('digestmailheader', 'newslettertemp', $headerdata)."\n\n";
                $headerdata->userprefs = '<a target="_blank" href="'.$headerdata->userprefs.'">'.get_string('digestmailprefs', 'newslettertemp').'</a>';

                $posthtml = "<head>";
/*                foreach ($CFG->stylesheets as $stylesheet) {
                    //TODO: MDL-21120
                    $posthtml .= '<link rel="stylesheet" type="text/css" href="'.$stylesheet.'" />'."\n";
                }*/
                $posthtml .= "</head>\n<body id=\"email\">\n";
                $posthtml .= '<p>'.get_string('digestmailheader', 'newslettertemp', $headerdata).'</p><br /><hr size="1" noshade="noshade" />';

                foreach ($thesediscussions as $discussionid) {

                    @set_time_limit(120);   // to be reset for each post

                    $discussion = $discussions[$discussionid];
                    $newslettertemp      = $newslettertemps[$discussion->newslettertemp];
                    $course     = $courses[$newslettertemp->course];
                    $cm         = $coursemodules[$newslettertemp->id];

                    //override language
                    cron_setup_user($userto, $course);

                    // Fill caches
                    if (!isset($userto->viewfullnames[$newslettertemp->id])) {
                        $modcontext = context_module::instance($cm->id);
                        $userto->viewfullnames[$newslettertemp->id] = has_capability('moodle/site:viewfullnames', $modcontext);
                    }
                    if (!isset($userto->canpost[$discussion->id])) {
                        $modcontext = context_module::instance($cm->id);
                        $userto->canpost[$discussion->id] = newslettertemp_user_can_post($newslettertemp, $discussion, $userto, $cm, $course, $modcontext);
                    }

                    $strnewslettertemps      = get_string('newslettertemps', 'newslettertemp');
                    $canunsubscribe = ! newslettertemp_is_forcesubscribed($newslettertemp);
                    $canreply       = $userto->canpost[$discussion->id];
                    $shortname = format_string($course->shortname, true, array('context' => context_course::instance($course->id)));

                    $posttext .= "\n \n";
                    $posttext .= '=====================================================================';
                    $posttext .= "\n \n";
                    $posttext .= "$shortname -> $strnewslettertemps -> ".format_string($newslettertemp->name,true);
                    if ($discussion->name != $newslettertemp->name) {
                        $posttext  .= " -> ".format_string($discussion->name,true);
                    }
                    $posttext .= "\n";

                    $posthtml .= "<p><font face=\"sans-serif\">".
                    "<a target=\"_blank\" href=\"$CFG->wwwroot/course/view.php?id=$course->id\">$shortname</a> -> ".
                    "<a target=\"_blank\" href=\"$CFG->wwwroot/mod/newslettertemp/index.php?id=$course->id\">$strnewslettertemps</a> -> ".
                    "<a target=\"_blank\" href=\"$CFG->wwwroot/mod/newslettertemp/view.php?f=$newslettertemp->id\">".format_string($newslettertemp->name,true)."</a>";
                    if ($discussion->name == $newslettertemp->name) {
                        $posthtml .= "</font></p>";
                    } else {
                        $posthtml .= " -> <a target=\"_blank\" href=\"$CFG->wwwroot/mod/newslettertemp/discuss.php?d=$discussion->id\">".format_string($discussion->name,true)."</a></font></p>";
                    }
                    $posthtml .= '<p>';

                    $postsarray = $discussionposts[$discussionid];
                    sort($postsarray);

                    foreach ($postsarray as $postid) {
                        $post = $posts[$postid];

                        if (array_key_exists($post->userid, $users)) { // we might know him/her already
                            $userfrom = $users[$post->userid];
                            if (!isset($userfrom->idnumber)) {
                                $userfrom = $DB->get_record('user', array('id' => $userfrom->id));
                                newslettertemp_cron_minimise_user_record($userfrom);
                            }

                        } else if ($userfrom = $DB->get_record('user', array('id' => $post->userid))) {
                            newslettertemp_cron_minimise_user_record($userfrom);
                            if ($userscount <= NEWSLETTERTEMP_CRON_USER_CACHE) {
                                $userscount++;
                                $users[$userfrom->id] = $userfrom;
                            }

                        } else {
                            mtrace('Could not find user '.$post->userid);
                            continue;
                        }

                        if (!isset($userfrom->groups[$newslettertemp->id])) {
                            if (!isset($userfrom->groups)) {
                                $userfrom->groups = array();
                                if (isset($users[$userfrom->id])) {
                                    $users[$userfrom->id]->groups = array();
                                }
                            }
                            $userfrom->groups[$newslettertemp->id] = groups_get_all_groups($course->id, $userfrom->id, $cm->groupingid);
                            if (isset($users[$userfrom->id])) {
                                $users[$userfrom->id]->groups[$newslettertemp->id] = $userfrom->groups[$newslettertemp->id];
                            }
                        }

                        $userfrom->customheaders = array ("Precedence: Bulk");

                        if ($userto->maildigest == 2) {
                            // Subjects only
                            $by = new stdClass();
                            $by->name = fullname($userfrom);
                            $by->date = userdate($post->modified);
                            $posttext .= "\n".format_string($post->subject,true).' '.get_string("bynameondate", "newslettertemp", $by);
                            $posttext .= "\n---------------------------------------------------------------------";

                            $by->name = "<a target=\"_blank\" href=\"$CFG->wwwroot/user/view.php?id=$userfrom->id&amp;course=$course->id\">$by->name</a>";
                            $posthtml .= '<div><a target="_blank" href="'.$CFG->wwwroot.'/mod/newslettertemp/discuss.php?d='.$discussion->id.'#p'.$post->id.'">'.format_string($post->subject,true).'</a> '.get_string("bynameondate", "newslettertemp", $by).'</div>';

                        } else {
                            // The full treatment
                            $posttext .= newslettertemp_make_mail_text($course, $cm, $newslettertemp, $discussion, $post, $userfrom, $userto, true);
                            $posthtml .= newslettertemp_make_mail_post($course, $cm, $newslettertemp, $discussion, $post, $userfrom, $userto, false, $canreply, true, false);

                        // Create an array of postid's for this user to mark as read.
                            if (!$CFG->newslettertemp_usermarksread) {
                                $userto->markposts[$post->id] = $post->id;
                            }
                        }
                    }
                    if ($canunsubscribe) {
                        $posthtml .= "\n<div class='mdl-right'><font size=\"1\"><a href=\"$CFG->wwwroot/mod/newslettertemp/subscribe.php?id=$newslettertemp->id\">".get_string("unsubscribe", "newslettertemp")."</a></font></div>";
                    } else {
                        $posthtml .= "\n<div class='mdl-right'><font size=\"1\">".get_string("everyoneissubscribed", "newslettertemp")."</font></div>";
                    }
                    $posthtml .= '<hr size="1" noshade="noshade" /></p>';
                }
                $posthtml .= '</body>';

                if (empty($userto->mailformat) || $userto->mailformat != 1) {
                    // This user DOESN'T want to receive HTML
                    $posthtml = '';
                }

                $attachment = $attachname='';
                $usetrueaddress = true;
                // Directly email newslettertemp digests rather than sending them via messaging, use the
                // site shortname as 'from name', the noreply address will be used by email_to_user.
                $site->shortname = 'ivan.sakic3@gmail.com'; // TODO: HACK by isakic
                $mailresult = email_to_user($userto, $site->shortname, $postsubject, $posttext, $posthtml, $attachment, $attachname, $usetrueaddress, $CFG->newslettertemp_replytouser);

                if (!$mailresult) {
                    mtrace("ERROR!");
                    echo "Error: mod/newslettertemp/cron.php: Could not send out digest mail to user $userto->id ($userto->email)... not trying again.\n";
                    add_to_log($course->id, 'newslettertemp', 'mail digest error', '', '', $cm->id, $userto->id);
                } else {
                    mtrace("success.");
                    $usermailcount++;

                    // Mark post as read if newslettertemp_usermarksread is set off
                    newslettertemp_tp_mark_posts_read($userto, $userto->markposts);
                }
            }
        }
    /// We have finishied all digest emails, update $CFG->digestmailtimelast
        set_config('digestmailtimelast', $timenow);
    }

    cron_setup_user();

    if (!empty($usermailcount)) {
        mtrace(get_string('digestsentusers', 'newslettertemp', $usermailcount));
    }

    if (!empty($CFG->newslettertemp_lastreadclean)) {
        $timenow = time();
        if ($CFG->newslettertemp_lastreadclean + (24*3600) < $timenow) {
            set_config('newslettertemp_lastreadclean', $timenow);
            mtrace('Removing old newslettertemp read tracking info...');
            newslettertemp_tp_clean_read_records();
        }
    } else {
        set_config('newslettertemp_lastreadclean', time());
    }


    return true;
}

/**
 * Builds and returns the body of the email notification in plain text.
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @param object $course
 * @param object $cm
 * @param object $newslettertemp
 * @param object $discussion
 * @param object $post
 * @param object $userfrom
 * @param object $userto
 * @param boolean $bare
 * @return string The email body in plain text format.
 */
function newslettertemp_make_mail_text($course, $cm, $newslettertemp, $discussion, $post, $userfrom, $userto, $bare = false) {
    global $CFG, $USER;

    $modcontext = context_module::instance($cm->id);

    if (!isset($userto->viewfullnames[$newslettertemp->id])) {
        $viewfullnames = has_capability('moodle/site:viewfullnames', $modcontext, $userto->id);
    } else {
        $viewfullnames = $userto->viewfullnames[$newslettertemp->id];
    }

    if (!isset($userto->canpost[$discussion->id])) {
        $canreply = newslettertemp_user_can_post($newslettertemp, $discussion, $userto, $cm, $course, $modcontext);
    } else {
        $canreply = $userto->canpost[$discussion->id];
    }

    $strnewslettertemps = get_string('newslettertemps', 'newslettertemp');

    $canunsubscribe = ! newslettertemp_is_forcesubscribed($newslettertemp);

    $posttext = '';

    // add absolute file links
    $post->message = file_rewrite_pluginfile_urls($post->message, 'pluginfile.php', $modcontext->id, 'mod_newslettertemp', 'post', $post->id);

    $posttext .= format_string($post->subject,true);

    $posttext .= "\n";
    $posttext .= format_text_email($post->message, $post->messageformat);
    $posttext .= "\n\n";
    $posttext .= newslettertemp_print_attachments($post, $cm, "text");


    if (!$bare && $canunsubscribe) {
        $posttext .= "\n---------------------------------------------------------------------\n";
        $posttext .= get_string("unsubscribe", "newslettertemp");
        $posttext .= ": $CFG->wwwroot/mod/newslettertemp/subscribe.php?id=$newslettertemp->id\n";
    }

    return $posttext;
}

/**
 * Builds and returns the body of the email notification in html format.
 *
 * @global object
 * @param object $course
 * @param object $cm
 * @param object $newslettertemp
 * @param object $discussion
 * @param object $post
 * @param object $userfrom
 * @param object $userto
 * @return string The email text in HTML format
 */
function newslettertemp_make_mail_html($course, $cm, $newslettertemp, $discussion, $post, $userfrom, $userto) {
    global $CFG;

    if ($userto->mailformat != 1) {  // Needs to be HTML
        return '';
    }

    if (!isset($userto->canpost[$discussion->id])) {
        $canreply = newslettertemp_user_can_post($newslettertemp, $discussion, $userto, $cm, $course);
    } else {
        $canreply = $userto->canpost[$discussion->id];
    }

    $strnewslettertemps = get_string('newslettertemps', 'newslettertemp');
    $canunsubscribe = ! newslettertemp_is_forcesubscribed($newslettertemp);
    $shortname = format_string($course->shortname, true, array('context' => context_course::instance($course->id)));

    $posthtml = '<head>';
/*    foreach ($CFG->stylesheets as $stylesheet) {
        //TODO: MDL-21120
        $posthtml .= '<link rel="stylesheet" type="text/css" href="'.$stylesheet.'" />'."\n";
    }*/
    $posthtml .= '</head>';
    $posthtml .= "\n<body id=\"email\">\n\n";
	/*
    $posthtml .= '<div class="navbar">'.
    '<a target="_blank" href="'.$CFG->wwwroot.'/course/view.php?id='.$course->id.'">'.$shortname.'</a> &raquo; '.
    '<a target="_blank" href="'.$CFG->wwwroot.'/mod/newslettertemp/index.php?id='.$course->id.'">'.$strnewslettertemps.'</a> &raquo; '.
    '<a target="_blank" href="'.$CFG->wwwroot.'/mod/newslettertemp/view.php?f='.$newslettertemp->id.'">'.format_string($newslettertemp->name,true).'</a>';
    if ($discussion->name == $newslettertemp->name) {
        $posthtml .= '</div>';
    } else {
        $posthtml .= ' &raquo; <a target="_blank" href="'.$CFG->wwwroot.'/mod/newslettertemp/discuss.php?d='.$discussion->id.'">'.
                     format_string($discussion->name,true).'</a></div>';
    }
    */
    $posthtml .= newslettertemp_make_mail_post($course, $cm, $newslettertemp, $discussion, $post, $userfrom, $userto, false, $canreply, true, false);

    if ($canunsubscribe) {
        $posthtml .= '<hr /><div class="mdl-align unsubscribelink">
                      <a href="'.$CFG->wwwroot.'/mod/newslettertemp/subscribe.php?id='.$newslettertemp->id.'">'.get_string('unsubscribe', 'newslettertemp').'</a></div>';
    }

    $posthtml .= '</body>';

    return $posthtml;
}


/**
 *
 * @param object $course
 * @param object $user
 * @param object $mod TODO this is not used in this function, refactor
 * @param object $newslettertemp
 * @return object A standard object with 2 variables: info (number of posts for this user) and time (last modified)
 */
function newslettertemp_user_outline($course, $user, $mod, $newslettertemp) {
    global $CFG;
    require_once("$CFG->libdir/gradelib.php");
    $grades = grade_get_grades($course->id, 'mod', 'newslettertemp', $newslettertemp->id, $user->id);
    if (empty($grades->items[0]->grades)) {
        $grade = false;
    } else {
        $grade = reset($grades->items[0]->grades);
    }

    $count = newslettertemp_count_user_posts($newslettertemp->id, $user->id);

    if ($count && $count->postcount > 0) {
        $result = new stdClass();
        $result->info = get_string("numposts", "newslettertemp", $count->postcount);
        $result->time = $count->lastpost;
        if ($grade) {
            $result->info .= ', ' . get_string('grade') . ': ' . $grade->str_long_grade;
        }
        return $result;
    } else if ($grade) {
        $result = new stdClass();
        $result->info = get_string('grade') . ': ' . $grade->str_long_grade;

        //datesubmitted == time created. dategraded == time modified or time overridden
        //if grade was last modified by the user themselves use date graded. Otherwise use date submitted
        //TODO: move this copied & pasted code somewhere in the grades API. See MDL-26704
        if ($grade->usermodified == $user->id || empty($grade->datesubmitted)) {
            $result->time = $grade->dategraded;
        } else {
            $result->time = $grade->datesubmitted;
        }

        return $result;
    }
    return NULL;
}


/**
 * @global object
 * @global object
 * @param object $coure
 * @param object $user
 * @param object $mod
 * @param object $newslettertemp
 */
function newslettertemp_user_complete($course, $user, $mod, $newslettertemp) {
    global $CFG,$USER, $OUTPUT;
    require_once("$CFG->libdir/gradelib.php");

    $grades = grade_get_grades($course->id, 'mod', 'newslettertemp', $newslettertemp->id, $user->id);
    if (!empty($grades->items[0]->grades)) {
        $grade = reset($grades->items[0]->grades);
        echo $OUTPUT->container(get_string('grade').': '.$grade->str_long_grade);
        if ($grade->str_feedback) {
            echo $OUTPUT->container(get_string('feedback').': '.$grade->str_feedback);
        }
    }

    if ($posts = newslettertemp_get_user_posts($newslettertemp->id, $user->id)) {

        if (!$cm = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id, $course->id)) {
            print_error('invalidcoursemodule');
        }
        $discussions = newslettertemp_get_user_involved_discussions($newslettertemp->id, $user->id);

        foreach ($posts as $post) {
            if (!isset($discussions[$post->discussion])) {
                continue;
            }
            $discussion = $discussions[$post->discussion];

            newslettertemp_print_post($post, $discussion, $newslettertemp, $cm, $course, false, false, false);
        }
    } else {
        echo "<p>".get_string("noposts", "newslettertemp")."</p>";
    }
}






/**
 * @global object
 * @global object
 * @global object
 * @param array $courses
 * @param array $htmlarray
 */
function newslettertemp_print_overview($courses,&$htmlarray) {
    global $USER, $CFG, $DB, $SESSION;

    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return array();
    }

    if (!$newslettertemps = get_all_instances_in_courses('newslettertemp',$courses)) {
        return;
    }

    // Courses to search for new posts
    $coursessqls = array();
    $params = array();
    foreach ($courses as $course) {

        // If the user has never entered into the course all posts are pending
        if ($course->lastaccess == 0) {
            $coursessqls[] = '(f.course = ?)';
            $params[] = $course->id;

        // Only posts created after the course last access
        } else {
            $coursessqls[] = '(f.course = ? AND p.created > ?)';
            $params[] = $course->id;
            $params[] = $course->lastaccess;
        }
    }
    $params[] = $USER->id;
    $coursessql = implode(' OR ', $coursessqls);

    $sql = "SELECT f.id, COUNT(*) as count "
                .'FROM {newslettertemp} f '
                .'JOIN {newslettertemp_discussions} d ON d.newslettertemp  = f.id '
                .'JOIN {newslettertemp_posts} p ON p.discussion = d.id '
                ."WHERE ($coursessql) "
                .'AND p.userid != ? '
                .'GROUP BY f.id';

    if (!$new = $DB->get_records_sql($sql, $params)) {
        $new = array(); // avoid warnings
    }

    // also get all newslettertemp tracking stuff ONCE.
    $trackingnewslettertemps = array();
    foreach ($newslettertemps as $newslettertemp) {
        if (newslettertemp_tp_can_track_newslettertemps($newslettertemp)) {
            $trackingnewslettertemps[$newslettertemp->id] = $newslettertemp;
        }
    }

    if (count($trackingnewslettertemps) > 0) {
        $cutoffdate = isset($CFG->newslettertemp_oldpostdays) ? (time() - ($CFG->newslettertemp_oldpostdays*24*60*60)) : 0;
        $sql = 'SELECT d.newslettertemp,d.course,COUNT(p.id) AS count '.
            ' FROM {newslettertemp_posts} p '.
            ' JOIN {newslettertemp_discussions} d ON p.discussion = d.id '.
            ' LEFT JOIN {newslettertemp_read} r ON r.postid = p.id AND r.userid = ? WHERE (';
        $params = array($USER->id);

        foreach ($trackingnewslettertemps as $track) {
            $sql .= '(d.newslettertemp = ? AND (d.groupid = -1 OR d.groupid = 0 OR d.groupid = ?)) OR ';
            $params[] = $track->id;
            if (isset($SESSION->currentgroup[$track->course])) {
                $groupid =  $SESSION->currentgroup[$track->course];
            } else {
                // get first groupid
                $groupids = groups_get_all_groups($track->course, $USER->id);
                if ($groupids) {
                    reset($groupids);
                    $groupid = key($groupids);
                    $SESSION->currentgroup[$track->course] = $groupid;
                } else {
                    $groupid = 0;
                }
                unset($groupids);
            }
            $params[] = $groupid;
        }
        $sql = substr($sql,0,-3); // take off the last OR
        $sql .= ') AND p.modified >= ? AND r.id is NULL GROUP BY d.newslettertemp,d.course';
        $params[] = $cutoffdate;

        if (!$unread = $DB->get_records_sql($sql, $params)) {
            $unread = array();
        }
    } else {
        $unread = array();
    }

    if (empty($unread) and empty($new)) {
        return;
    }

    $strnewslettertemp = get_string('modulename','newslettertemp');

    foreach ($newslettertemps as $newslettertemp) {
        $str = '';
        $count = 0;
        $thisunread = 0;
        $showunread = false;
        // either we have something from logs, or trackposts, or nothing.
        if (array_key_exists($newslettertemp->id, $new) && !empty($new[$newslettertemp->id])) {
            $count = $new[$newslettertemp->id]->count;
        }
        if (array_key_exists($newslettertemp->id,$unread)) {
            $thisunread = $unread[$newslettertemp->id]->count;
            $showunread = true;
        }
        if ($count > 0 || $thisunread > 0) {
            $str .= '<div class="overview newslettertemp"><div class="name">'.$strnewslettertemp.': <a title="'.$strnewslettertemp.'" href="'.$CFG->wwwroot.'/mod/newslettertemp/view.php?f='.$newslettertemp->id.'">'.
                $newslettertemp->name.'</a></div>';
            $str .= '<div class="info"><span class="postsincelogin">';
            $str .= get_string('overviewnumpostssince', 'newslettertemp', $count)."</span>";
            if (!empty($showunread)) {
                $str .= '<div class="unreadposts">'.get_string('overviewnumunread', 'newslettertemp', $thisunread).'</div>';
            }
            $str .= '</div></div>';
        }
        if (!empty($str)) {
            if (!array_key_exists($newslettertemp->course,$htmlarray)) {
                $htmlarray[$newslettertemp->course] = array();
            }
            if (!array_key_exists('newslettertemp',$htmlarray[$newslettertemp->course])) {
                $htmlarray[$newslettertemp->course]['newslettertemp'] = ''; // initialize, avoid warnings
            }
            $htmlarray[$newslettertemp->course]['newslettertemp'] .= $str;
        }
    }
}

/**
 * Given a course and a date, prints a summary of all the new
 * messages posted in the course since that date
 *
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $course
 * @param bool $viewfullnames capability
 * @param int $timestart
 * @return bool success
 */
function newslettertemp_print_recent_activity($course, $viewfullnames, $timestart) {
    global $CFG, $USER, $DB, $OUTPUT;

    // do not use log table if possible, it may be huge and is expensive to join with other tables

    if (!$posts = $DB->get_records_sql("SELECT p.*, f.type AS newslettertemptype, d.newslettertemp, d.groupid,
                                              d.timestart, d.timeend, d.userid AS duserid,
                                              u.firstname, u.lastname, u.email, u.picture
                                         FROM {newslettertemp_posts} p
                                              JOIN {newslettertemp_discussions} d ON d.id = p.discussion
                                              JOIN {newslettertemp} f             ON f.id = d.newslettertemp
                                              JOIN {user} u              ON u.id = p.userid
                                        WHERE p.created > ? AND f.course = ?
                                     ORDER BY p.id ASC", array($timestart, $course->id))) { // order by initial posting date
         return false;
    }

    $modinfo = get_fast_modinfo($course);

    $groupmodes = array();
    $cms    = array();

    $strftimerecent = get_string('strftimerecent');

    $printposts = array();
    foreach ($posts as $post) {
        if (!isset($modinfo->instances['newslettertemp'][$post->newslettertemp])) {
            // not visible
            continue;
        }
        $cm = $modinfo->instances['newslettertemp'][$post->newslettertemp];
        if (!$cm->uservisible) {
            continue;
        }
        $context = context_module::instance($cm->id);

        if (!has_capability('mod/newslettertemp:viewdiscussion', $context)) {
            continue;
        }

        if (!empty($CFG->newslettertemp_enabletimedposts) and $USER->id != $post->duserid
          and (($post->timestart > 0 and $post->timestart > time()) or ($post->timeend > 0 and $post->timeend < time()))) {
            if (!has_capability('mod/newslettertemp:viewhiddentimedposts', $context)) {
                continue;
            }
        }

        $groupmode = groups_get_activity_groupmode($cm, $course);

        if ($groupmode) {
            if ($post->groupid == -1 or $groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $context)) {
                // oki (Open discussions have groupid -1)
            } else {
                // separate mode
                if (isguestuser()) {
                    // shortcut
                    continue;
                }

                if (is_null($modinfo->groups)) {
                    $modinfo->groups = groups_get_user_groups($course->id); // load all my groups and cache it in modinfo
                }

                if (!array_key_exists($post->groupid, $modinfo->groups[0])) {
                    continue;
                }
            }
        }

        $printposts[] = $post;
    }
    unset($posts);

    if (!$printposts) {
        return false;
    }

    echo $OUTPUT->heading(get_string('newnewslettertempposts', 'newslettertemp').':', 3);
    echo "\n<ul class='unlist'>\n";

    foreach ($printposts as $post) {
        $subjectclass = empty($post->parent) ? ' bold' : '';

        echo '<li><div class="head">'.
               '<div class="date">'.userdate($post->modified, $strftimerecent).'</div>'.
               '<div class="name">'.fullname($post, $viewfullnames).'</div>'.
             '</div>';
        echo '<div class="info'.$subjectclass.'">';
        if (empty($post->parent)) {
            echo '"<a href="'.$CFG->wwwroot.'/mod/newslettertemp/discuss.php?d='.$post->discussion.'">';
        } else {
            echo '"<a href="'.$CFG->wwwroot.'/mod/newslettertemp/discuss.php?d='.$post->discussion.'&amp;parent='.$post->parent.'#p'.$post->id.'">';
        }
        $post->subject = break_up_long_words(format_string($post->subject, true));
        echo $post->subject;
        echo "</a>\"</div></li>\n";
    }

    echo "</ul>\n";

    return true;
}

/**
 * Return grade for given user or all users.
 *
 * @global object
 * @global object
 * @param object $newslettertemp
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none
 */
function newslettertemp_get_user_grades($newslettertemp, $userid = 0) {
    global $CFG;

    require_once($CFG->dirroot.'/rating/lib.php');

    $ratingoptions = new stdClass;
    $ratingoptions->component = 'mod_newslettertemp';
    $ratingoptions->ratingarea = 'post';

    //need these to work backwards to get a context id. Is there a better way to get contextid from a module instance?
    $ratingoptions->modulename = 'newslettertemp';
    $ratingoptions->moduleid   = $newslettertemp->id;
    $ratingoptions->userid = $userid;
    $ratingoptions->aggregationmethod = $newslettertemp->assessed;
    $ratingoptions->scaleid = $newslettertemp->scale;
    $ratingoptions->itemtable = 'newslettertemp_posts';
    $ratingoptions->itemtableusercolumn = 'userid';

    $rm = new rating_manager();
    return $rm->get_user_grades($ratingoptions);
}

/**
 * Update activity grades
 *
 * @category grade
 * @param object $newslettertemp
 * @param int $userid specific user only, 0 means all
 * @param boolean $nullifnone return null if grade does not exist
 * @return void
 */
function newslettertemp_update_grades($newslettertemp, $userid=0, $nullifnone=true) {
    global $CFG, $DB;
    require_once($CFG->libdir.'/gradelib.php');

    if (!$newslettertemp->assessed) {
        newslettertemp_grade_item_update($newslettertemp);

    } else if ($grades = newslettertemp_get_user_grades($newslettertemp, $userid)) {
        newslettertemp_grade_item_update($newslettertemp, $grades);

    } else if ($userid and $nullifnone) {
        $grade = new stdClass();
        $grade->userid   = $userid;
        $grade->rawgrade = NULL;
        newslettertemp_grade_item_update($newslettertemp, $grade);

    } else {
        newslettertemp_grade_item_update($newslettertemp);
    }
}

/**
 * Update all grades in gradebook.
 * @global object
 */
function newslettertemp_upgrade_grades() {
    global $DB;

    $sql = "SELECT COUNT('x')
              FROM {newslettertemp} f, {course_modules} cm, {modules} m
             WHERE m.name='newslettertemp' AND m.id=cm.module AND cm.instance=f.id";
    $count = $DB->count_records_sql($sql);

    $sql = "SELECT f.*, cm.idnumber AS cmidnumber, f.course AS courseid
              FROM {newslettertemp} f, {course_modules} cm, {modules} m
             WHERE m.name='newslettertemp' AND m.id=cm.module AND cm.instance=f.id";
    $rs = $DB->get_recordset_sql($sql);
    if ($rs->valid()) {
        $pbar = new progress_bar('newslettertempupgradegrades', 500, true);
        $i=0;
        foreach ($rs as $newslettertemp) {
            $i++;
            upgrade_set_timeout(60*5); // set up timeout, may also abort execution
            newslettertemp_update_grades($newslettertemp, 0, false);
            $pbar->update($i, $count, "Updating Forum grades ($i/$count).");
        }
    }
    $rs->close();
}

/**
 * Create/update grade item for given newslettertemp
 *
 * @category grade
 * @uses GRADE_TYPE_NONE
 * @uses GRADE_TYPE_VALUE
 * @uses GRADE_TYPE_SCALE
 * @param stdClass $newslettertemp Forum object with extra cmidnumber
 * @param mixed $grades Optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok
 */
function newslettertemp_grade_item_update($newslettertemp, $grades=NULL) {
    global $CFG;
    if (!function_exists('grade_update')) { //workaround for buggy PHP versions
        require_once($CFG->libdir.'/gradelib.php');
    }

    $params = array('itemname'=>$newslettertemp->name, 'idnumber'=>$newslettertemp->cmidnumber);

    if (!$newslettertemp->assessed or $newslettertemp->scale == 0) {
        $params['gradetype'] = GRADE_TYPE_NONE;

    } else if ($newslettertemp->scale > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $newslettertemp->scale;
        $params['grademin']  = 0;

    } else if ($newslettertemp->scale < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid']   = -$newslettertemp->scale;
    }

    if ($grades  === 'reset') {
        $params['reset'] = true;
        $grades = NULL;
    }

    return grade_update('mod/newslettertemp', $newslettertemp->course, 'mod', 'newslettertemp', $newslettertemp->id, 0, $grades, $params);
}

/**
 * Delete grade item for given newslettertemp
 *
 * @category grade
 * @param stdClass $newslettertemp Forum object
 * @return grade_item
 */
function newslettertemp_grade_item_delete($newslettertemp) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    return grade_update('mod/newslettertemp', $newslettertemp->course, 'mod', 'newslettertemp', $newslettertemp->id, 0, NULL, array('deleted'=>1));
}


/**
 * This function returns if a scale is being used by one newslettertemp
 *
 * @global object
 * @param int $newslettertempid
 * @param int $scaleid negative number
 * @return bool
 */
function newslettertemp_scale_used ($newslettertempid,$scaleid) {
    global $DB;
    $return = false;

    $rec = $DB->get_record("newslettertemp",array("id" => "$newslettertempid","scale" => "-$scaleid"));

    if (!empty($rec) && !empty($scaleid)) {
        $return = true;
    }

    return $return;
}

/**
 * Checks if scale is being used by any instance of newslettertemp
 *
 * This is used to find out if scale used anywhere
 *
 * @global object
 * @param $scaleid int
 * @return boolean True if the scale is used by any newslettertemp
 */
function newslettertemp_scale_used_anywhere($scaleid) {
    global $DB;
    if ($scaleid and $DB->record_exists('newslettertemp', array('scale' => -$scaleid))) {
        return true;
    } else {
        return false;
    }
}

// SQL FUNCTIONS ///////////////////////////////////////////////////////////

/**
 * Gets a post with all info ready for newslettertemp_print_post
 * Most of these joins are just to get the newslettertemp id
 *
 * @global object
 * @global object
 * @param int $postid
 * @return mixed array of posts or false
 */
function newslettertemp_get_post_full($postid) {
    global $CFG, $DB;

    return $DB->get_record_sql("SELECT p.*, d.newslettertemp, u.firstname, u.lastname, u.email, u.picture, u.imagealt
                             FROM {newslettertemp_posts} p
                                  JOIN {newslettertemp_discussions} d ON p.discussion = d.id
                                  LEFT JOIN {user} u ON p.userid = u.id
                            WHERE p.id = ?", array($postid));
}

/**
 * Gets posts with all info ready for newslettertemp_print_post
 * We pass newslettertempid in because we always know it so no need to make a
 * complicated join to find it out.
 *
 * @global object
 * @global object
 * @return mixed array of posts or false
 */
function newslettertemp_get_discussion_posts($discussion, $sort, $newslettertempid) {
    global $CFG, $DB;

    return $DB->get_records_sql("SELECT p.*, $newslettertempid AS newslettertemp, u.firstname, u.lastname, u.email, u.picture, u.imagealt
                              FROM {newslettertemp_posts} p
                         LEFT JOIN {user} u ON p.userid = u.id
                             WHERE p.discussion = ?
                               AND p.parent > 0 $sort", array($discussion));
}

/**
 * Gets all posts in discussion including top parent.
 *
 * @global object
 * @global object
 * @global object
 * @param int $discussionid
 * @param string $sort
 * @param bool $tracking does user track the newslettertemp?
 * @return array of posts
 */
function newslettertemp_get_all_discussion_posts($discussionid, $sort, $tracking=false) {
    global $CFG, $DB, $USER;

    $tr_sel  = "";
    $tr_join = "";
    $params = array();

    if ($tracking) {
        $now = time();
        $cutoffdate = $now - ($CFG->newslettertemp_oldpostdays * 24 * 3600);
        $tr_sel  = ", fr.id AS postread";
        $tr_join = "LEFT JOIN {newslettertemp_read} fr ON (fr.postid = p.id AND fr.userid = ?)";
        $params[] = $USER->id;
    }

    $params[] = $discussionid;
    if (!$posts = $DB->get_records_sql("SELECT p.*, u.firstname, u.lastname, u.email, u.picture, u.imagealt $tr_sel
                                     FROM {newslettertemp_posts} p
                                          LEFT JOIN {user} u ON p.userid = u.id
                                          $tr_join
                                    WHERE p.discussion = ?
                                 ORDER BY $sort", $params)) {
        return array();
    }

    foreach ($posts as $pid=>$p) {
        if ($tracking) {
            if (newslettertemp_tp_is_post_old($p)) {
                 $posts[$pid]->postread = true;
            }
        }
        if (!$p->parent) {
            continue;
        }
        if (!isset($posts[$p->parent])) {
            continue; // parent does not exist??
        }
        if (!isset($posts[$p->parent]->children)) {
            $posts[$p->parent]->children = array();
        }
        $posts[$p->parent]->children[$pid] =& $posts[$pid];
    }

    return $posts;
}

/**
 * Gets posts with all info ready for newslettertemp_print_post
 * We pass newslettertempid in because we always know it so no need to make a
 * complicated join to find it out.
 *
 * @global object
 * @global object
 * @param int $parent
 * @param int $newslettertempid
 * @return array
 */
function newslettertemp_get_child_posts($parent, $newslettertempid) {
    global $CFG, $DB;

    return $DB->get_records_sql("SELECT p.*, $newslettertempid AS newslettertemp, u.firstname, u.lastname, u.email, u.picture, u.imagealt
                              FROM {newslettertemp_posts} p
                         LEFT JOIN {user} u ON p.userid = u.id
                             WHERE p.parent = ?
                          ORDER BY p.created ASC", array($parent));
}

/**
 * An array of newslettertemp objects that the user is allowed to read/search through.
 *
 * @global object
 * @global object
 * @global object
 * @param int $userid
 * @param int $courseid if 0, we look for newslettertemps throughout the whole site.
 * @return array of newslettertemp objects, or false if no matches
 *         Forum objects have the following attributes:
 *         id, type, course, cmid, cmvisible, cmgroupmode, accessallgroups,
 *         viewhiddentimedposts
 */
function newslettertemp_get_readable_newslettertemps($userid, $courseid=0) {

    global $CFG, $DB, $USER;
    require_once($CFG->dirroot.'/course/lib.php');

    if (!$newslettertempmod = $DB->get_record('modules', array('name' => 'newslettertemp'))) {
        print_error('notinstalled', 'newslettertemp');
    }

    if ($courseid) {
        $courses = $DB->get_records('course', array('id' => $courseid));
    } else {
        // If no course is specified, then the user can see SITE + his courses.
        $courses1 = $DB->get_records('course', array('id' => SITEID));
        $courses2 = enrol_get_users_courses($userid, true, array('modinfo'));
        $courses = array_merge($courses1, $courses2);
    }
    if (!$courses) {
        return array();
    }

    $readablenewslettertemps = array();

    foreach ($courses as $course) {

        $modinfo = get_fast_modinfo($course);
        if (is_null($modinfo->groups)) {
            $modinfo->groups = groups_get_user_groups($course->id, $userid);
        }

        if (empty($modinfo->instances['newslettertemp'])) {
            // hmm, no newslettertemps?
            continue;
        }

        $coursenewslettertemps = $DB->get_records('newslettertemp', array('course' => $course->id));

        foreach ($modinfo->instances['newslettertemp'] as $newslettertempid => $cm) {
            if (!$cm->uservisible or !isset($coursenewslettertemps[$newslettertempid])) {
                continue;
            }
            $context = context_module::instance($cm->id);
            $newslettertemp = $coursenewslettertemps[$newslettertempid];
            $newslettertemp->context = $context;
            $newslettertemp->cm = $cm;

            if (!has_capability('mod/newslettertemp:viewdiscussion', $context)) {
                continue;
            }

         /// group access
            if (groups_get_activity_groupmode($cm, $course) == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', $context)) {
                if (is_null($modinfo->groups)) {
                    $modinfo->groups = groups_get_user_groups($course->id, $USER->id);
                }
                if (isset($modinfo->groups[$cm->groupingid])) {
                    $newslettertemp->onlygroups = $modinfo->groups[$cm->groupingid];
                    $newslettertemp->onlygroups[] = -1;
                } else {
                    $newslettertemp->onlygroups = array(-1);
                }
            }

        /// hidden timed discussions
            $newslettertemp->viewhiddentimedposts = true;
            if (!empty($CFG->newslettertemp_enabletimedposts)) {
                if (!has_capability('mod/newslettertemp:viewhiddentimedposts', $context)) {
                    $newslettertemp->viewhiddentimedposts = false;
                }
            }

        /// qanda access
            if ($newslettertemp->type == 'qanda'
                    && !has_capability('mod/newslettertemp:viewqandawithoutposting', $context)) {

                // We need to check whether the user has posted in the qanda newslettertemp.
                $newslettertemp->onlydiscussions = array();  // Holds discussion ids for the discussions
                                                    // the user is allowed to see in this newslettertemp.
                if ($discussionspostedin = newslettertemp_discussions_user_has_posted_in($newslettertemp->id, $USER->id)) {
                    foreach ($discussionspostedin as $d) {
                        $newslettertemp->onlydiscussions[] = $d->id;
                    }
                }
            }

            $readablenewslettertemps[$newslettertemp->id] = $newslettertemp;
        }

        unset($modinfo);

    } // End foreach $courses

    return $readablenewslettertemps;
}

/**
 * Returns a list of posts found using an array of search terms.
 *
 * @global object
 * @global object
 * @global object
 * @param array $searchterms array of search terms, e.g. word +word -word
 * @param int $courseid if 0, we search through the whole site
 * @param int $limitfrom
 * @param int $limitnum
 * @param int &$totalcount
 * @param string $extrasql
 * @return array|bool Array of posts found or false
 */
function newslettertemp_search_posts($searchterms, $courseid=0, $limitfrom=0, $limitnum=50,
                            &$totalcount, $extrasql='') {
    global $CFG, $DB, $USER;
    require_once($CFG->libdir.'/searchlib.php');

    $newslettertemps = newslettertemp_get_readable_newslettertemps($USER->id, $courseid);

    if (count($newslettertemps) == 0) {
        $totalcount = 0;
        return false;
    }

    $now = round(time(), -2); // db friendly

    $fullaccess = array();
    $where = array();
    $params = array();

    foreach ($newslettertemps as $newslettertempid => $newslettertemp) {
        $select = array();

        if (!$newslettertemp->viewhiddentimedposts) {
            $select[] = "(d.userid = :userid{$newslettertempid} OR (d.timestart < :timestart{$newslettertempid} AND (d.timeend = 0 OR d.timeend > :timeend{$newslettertempid})))";
            $params = array_merge($params, array('userid'.$newslettertempid=>$USER->id, 'timestart'.$newslettertempid=>$now, 'timeend'.$newslettertempid=>$now));
        }

        $cm = $newslettertemp->cm;
        $context = $newslettertemp->context;

        if ($newslettertemp->type == 'qanda'
            && !has_capability('mod/newslettertemp:viewqandawithoutposting', $context)) {
            if (!empty($newslettertemp->onlydiscussions)) {
                list($discussionid_sql, $discussionid_params) = $DB->get_in_or_equal($newslettertemp->onlydiscussions, SQL_PARAMS_NAMED, 'qanda'.$newslettertempid.'_');
                $params = array_merge($params, $discussionid_params);
                $select[] = "(d.id $discussionid_sql OR p.parent = 0)";
            } else {
                $select[] = "p.parent = 0";
            }
        }

        if (!empty($newslettertemp->onlygroups)) {
            list($groupid_sql, $groupid_params) = $DB->get_in_or_equal($newslettertemp->onlygroups, SQL_PARAMS_NAMED, 'grps'.$newslettertempid.'_');
            $params = array_merge($params, $groupid_params);
            $select[] = "d.groupid $groupid_sql";
        }

        if ($select) {
            $selects = implode(" AND ", $select);
            $where[] = "(d.newslettertemp = :newslettertemp{$newslettertempid} AND $selects)";
            $params['newslettertemp'.$newslettertempid] = $newslettertempid;
        } else {
            $fullaccess[] = $newslettertempid;
        }
    }

    if ($fullaccess) {
        list($fullid_sql, $fullid_params) = $DB->get_in_or_equal($fullaccess, SQL_PARAMS_NAMED, 'fula');
        $params = array_merge($params, $fullid_params);
        $where[] = "(d.newslettertemp $fullid_sql)";
    }

    $selectdiscussion = "(".implode(" OR ", $where).")";

    $messagesearch = '';
    $searchstring = '';

    // Need to concat these back together for parser to work.
    foreach($searchterms as $searchterm){
        if ($searchstring != '') {
            $searchstring .= ' ';
        }
        $searchstring .= $searchterm;
    }

    // We need to allow quoted strings for the search. The quotes *should* be stripped
    // by the parser, but this should be examined carefully for security implications.
    $searchstring = str_replace("\\\"","\"",$searchstring);
    $parser = new search_parser();
    $lexer = new search_lexer($parser);

    if ($lexer->parse($searchstring)) {
        $parsearray = $parser->get_parsed_array();
    // Experimental feature under 1.8! MDL-8830
    // Use alternative text searches if defined
    // This feature only works under mysql until properly implemented for other DBs
    // Requires manual creation of text index for newslettertemp_posts before enabling it:
    // CREATE FULLTEXT INDEX foru_post_tix ON [prefix]newslettertemp_posts (subject, message)
    // Experimental feature under 1.8! MDL-8830
        if (!empty($CFG->newslettertemp_usetextsearches)) {
            list($messagesearch, $msparams) = search_generate_text_SQL($parsearray, 'p.message', 'p.subject',
                                                 'p.userid', 'u.id', 'u.firstname',
                                                 'u.lastname', 'p.modified', 'd.newslettertemp');
        } else {
            list($messagesearch, $msparams) = search_generate_SQL($parsearray, 'p.message', 'p.subject',
                                                 'p.userid', 'u.id', 'u.firstname',
                                                 'u.lastname', 'p.modified', 'd.newslettertemp');
        }
        $params = array_merge($params, $msparams);
    }

    $fromsql = "{newslettertemp_posts} p,
                  {newslettertemp_discussions} d,
                  {user} u";

    $selectsql = " $messagesearch
               AND p.discussion = d.id
               AND p.userid = u.id
               AND $selectdiscussion
                   $extrasql";

    $countsql = "SELECT COUNT(*)
                   FROM $fromsql
                  WHERE $selectsql";

    $searchsql = "SELECT p.*,
                         d.newslettertemp,
                         u.firstname,
                         u.lastname,
                         u.email,
                         u.picture,
                         u.imagealt
                    FROM $fromsql
                   WHERE $selectsql
                ORDER BY p.modified DESC";

    $totalcount = $DB->count_records_sql($countsql, $params);

    return $DB->get_records_sql($searchsql, $params, $limitfrom, $limitnum);
}

/**
 * Returns a list of ratings for a particular post - sorted.
 *
 * TODO: Check if this function is actually used anywhere.
 * Up until the fix for MDL-27471 this function wasn't even returning.
 *
 * @param stdClass $context
 * @param int $postid
 * @param string $sort
 * @return array Array of ratings or false
 */
function newslettertemp_get_ratings($context, $postid, $sort = "u.firstname ASC") {
    $options = new stdClass;
    $options->context = $context;
    $options->component = 'mod_newslettertemp';
    $options->ratingarea = 'post';
    $options->itemid = $postid;
    $options->sort = "ORDER BY $sort";

    $rm = new rating_manager();
    return $rm->get_all_ratings_for_item($options);
}

/**
 * Returns a list of all new posts that have not been mailed yet
 *
 * @param int $starttime posts created after this time
 * @param int $endtime posts created before this
 * @param int $now used for timed discussions only
 * @return array
 */
function newslettertemp_get_unmailed_posts($starttime, $endtime, $now=null) {
    global $CFG, $DB;

    $params = array($starttime, $endtime);
    if (!empty($CFG->newslettertemp_enabletimedposts)) {
        if (empty($now)) {
            $now = time();
        }
        $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    return $DB->get_records_sql("SELECT p.*, d.course, d.newslettertemp
                              FROM {newslettertemp_posts} p
                                   JOIN {newslettertemp_discussions} d ON d.id = p.discussion
                             WHERE p.mailed = 0
                                   AND p.created >= ?
                                   AND (p.created < ? OR p.mailnow = 1)
                                   $timedsql
                          ORDER BY p.modified ASC", $params);
}

/**
 * Marks posts before a certain time as being mailed already
 *
 * @global object
 * @global object
 * @param int $endtime
 * @param int $now Defaults to time()
 * @return bool
 */
function newslettertemp_mark_old_posts_as_mailed($endtime, $now=null) {
    global $CFG, $DB;
    if (empty($now)) {
        $now = time();
    }

    if (empty($CFG->newslettertemp_enabletimedposts)) {
        return $DB->execute("UPDATE {newslettertemp_posts}
                               SET mailed = '1'
                             WHERE (created < ? OR mailnow = 1)
                                   AND mailed = 0", array($endtime));

    } else {
        return $DB->execute("UPDATE {newslettertemp_posts}
                               SET mailed = '1'
                             WHERE discussion NOT IN (SELECT d.id
                                                        FROM {newslettertemp_discussions} d
                                                       WHERE d.timestart > ?)
                                   AND (created < ? OR mailnow = 1)
                                   AND mailed = 0", array($now, $endtime));
    }
}

/**
 * Get all the posts for a user in a newslettertemp suitable for newslettertemp_print_post
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @return array
 */
function newslettertemp_get_user_posts($newslettertempid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = array($newslettertempid, $userid);

    if (!empty($CFG->newslettertemp_enabletimedposts)) {
        $cm = get_coursemodule_from_instance('newslettertemp', $newslettertempid);
        if (!has_capability('mod/newslettertemp:viewhiddentimedposts' , context_module::instance($cm->id))) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    return $DB->get_records_sql("SELECT p.*, d.newslettertemp, u.firstname, u.lastname, u.email, u.picture, u.imagealt
                              FROM {newslettertemp} f
                                   JOIN {newslettertemp_discussions} d ON d.newslettertemp = f.id
                                   JOIN {newslettertemp_posts} p       ON p.discussion = d.id
                                   JOIN {user} u              ON u.id = p.userid
                             WHERE f.id = ?
                                   AND p.userid = ?
                                   $timedsql
                          ORDER BY p.modified ASC", $params);
}

/**
 * Get all the discussions user participated in
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @param int $newslettertempid
 * @param int $userid
 * @return array Array or false
 */
function newslettertemp_get_user_involved_discussions($newslettertempid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = array($newslettertempid, $userid);
    if (!empty($CFG->newslettertemp_enabletimedposts)) {
        $cm = get_coursemodule_from_instance('newslettertemp', $newslettertempid);
        if (!has_capability('mod/newslettertemp:viewhiddentimedposts' , context_module::instance($cm->id))) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    return $DB->get_records_sql("SELECT DISTINCT d.*
                              FROM {newslettertemp} f
                                   JOIN {newslettertemp_discussions} d ON d.newslettertemp = f.id
                                   JOIN {newslettertemp_posts} p       ON p.discussion = d.id
                             WHERE f.id = ?
                                   AND p.userid = ?
                                   $timedsql", $params);
}

/**
 * Get all the posts for a user in a newslettertemp suitable for newslettertemp_print_post
 *
 * @global object
 * @global object
 * @param int $newslettertempid
 * @param int $userid
 * @return array of counts or false
 */
function newslettertemp_count_user_posts($newslettertempid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = array($newslettertempid, $userid);
    if (!empty($CFG->newslettertemp_enabletimedposts)) {
        $cm = get_coursemodule_from_instance('newslettertemp', $newslettertempid);
        if (!has_capability('mod/newslettertemp:viewhiddentimedposts' , context_module::instance($cm->id))) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    return $DB->get_record_sql("SELECT COUNT(p.id) AS postcount, MAX(p.modified) AS lastpost
                             FROM {newslettertemp} f
                                  JOIN {newslettertemp_discussions} d ON d.newslettertemp = f.id
                                  JOIN {newslettertemp_posts} p       ON p.discussion = d.id
                                  JOIN {user} u              ON u.id = p.userid
                            WHERE f.id = ?
                                  AND p.userid = ?
                                  $timedsql", $params);
}

/**
 * Given a log entry, return the newslettertemp post details for it.
 *
 * @global object
 * @global object
 * @param object $log
 * @return array|null
 */
function newslettertemp_get_post_from_log($log) {
    global $CFG, $DB;

    if ($log->action == "add post") {

        return $DB->get_record_sql("SELECT p.*, f.type AS newslettertemptype, d.newslettertemp, d.groupid,
                                           u.firstname, u.lastname, u.email, u.picture
                                 FROM {newslettertemp_discussions} d,
                                      {newslettertemp_posts} p,
                                      {newslettertemp} f,
                                      {user} u
                                WHERE p.id = ?
                                  AND d.id = p.discussion
                                  AND p.userid = u.id
                                  AND u.deleted <> '1'
                                  AND f.id = d.newslettertemp", array($log->info));


    } else if ($log->action == "add discussion") {

        return $DB->get_record_sql("SELECT p.*, f.type AS newslettertemptype, d.newslettertemp, d.groupid,
                                           u.firstname, u.lastname, u.email, u.picture
                                 FROM {newslettertemp_discussions} d,
                                      {newslettertemp_posts} p,
                                      {newslettertemp} f,
                                      {user} u
                                WHERE d.id = ?
                                  AND d.firstpost = p.id
                                  AND p.userid = u.id
                                  AND u.deleted <> '1'
                                  AND f.id = d.newslettertemp", array($log->info));
    }
    return NULL;
}

/**
 * Given a discussion id, return the first post from the discussion
 *
 * @global object
 * @global object
 * @param int $dicsussionid
 * @return array
 */
function newslettertemp_get_firstpost_from_discussion($discussionid) {
    global $CFG, $DB;

    return $DB->get_record_sql("SELECT p.*
                             FROM {newslettertemp_discussions} d,
                                  {newslettertemp_posts} p
                            WHERE d.id = ?
                              AND d.firstpost = p.id ", array($discussionid));
}

/**
 * Returns an array of counts of replies to each discussion
 *
 * @global object
 * @global object
 * @param int $newslettertempid
 * @param string $newslettertempsort
 * @param int $limit
 * @param int $page
 * @param int $perpage
 * @return array
 */
function newslettertemp_count_discussion_replies($newslettertempid, $newslettertempsort="", $limit=-1, $page=-1, $perpage=0) {
    global $CFG, $DB;

    if ($limit > 0) {
        $limitfrom = 0;
        $limitnum  = $limit;
    } else if ($page != -1) {
        $limitfrom = $page*$perpage;
        $limitnum  = $perpage;
    } else {
        $limitfrom = 0;
        $limitnum  = 0;
    }

    if ($newslettertempsort == "") {
        $orderby = "";
        $groupby = "";

    } else {
        $orderby = "ORDER BY $newslettertempsort";
        $groupby = ", ".strtolower($newslettertempsort);
        $groupby = str_replace('desc', '', $groupby);
        $groupby = str_replace('asc', '', $groupby);
    }

    if (($limitfrom == 0 and $limitnum == 0) or $newslettertempsort == "") {
        $sql = "SELECT p.discussion, COUNT(p.id) AS replies, MAX(p.id) AS lastpostid
                  FROM {newslettertemp_posts} p
                       JOIN {newslettertemp_discussions} d ON p.discussion = d.id
                 WHERE p.parent > 0 AND d.newslettertemp = ?
              GROUP BY p.discussion";
        return $DB->get_records_sql($sql, array($newslettertempid));

    } else {
        $sql = "SELECT p.discussion, (COUNT(p.id) - 1) AS replies, MAX(p.id) AS lastpostid
                  FROM {newslettertemp_posts} p
                       JOIN {newslettertemp_discussions} d ON p.discussion = d.id
                 WHERE d.newslettertemp = ?
              GROUP BY p.discussion $groupby
              $orderby";
        return $DB->get_records_sql("SELECT * FROM ($sql) sq", array($newslettertempid), $limitfrom, $limitnum);
    }
}

/**
 * @global object
 * @global object
 * @global object
 * @staticvar array $cache
 * @param object $newslettertemp
 * @param object $cm
 * @param object $course
 * @return mixed
 */
function newslettertemp_count_discussions($newslettertemp, $cm, $course) {
    global $CFG, $DB, $USER;

    static $cache = array();

    $now = round(time(), -2); // db cache friendliness

    $params = array($course->id);

    if (!isset($cache[$course->id])) {
        if (!empty($CFG->newslettertemp_enabletimedposts)) {
            $timedsql = "AND d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)";
            $params[] = $now;
            $params[] = $now;
        } else {
            $timedsql = "";
        }

        $sql = "SELECT f.id, COUNT(d.id) as dcount
                  FROM {newslettertemp} f
                       JOIN {newslettertemp_discussions} d ON d.newslettertemp = f.id
                 WHERE f.course = ?
                       $timedsql
              GROUP BY f.id";

        if ($counts = $DB->get_records_sql($sql, $params)) {
            foreach ($counts as $count) {
                $counts[$count->id] = $count->dcount;
            }
            $cache[$course->id] = $counts;
        } else {
            $cache[$course->id] = array();
        }
    }

    if (empty($cache[$course->id][$newslettertemp->id])) {
        return 0;
    }

    $groupmode = groups_get_activity_groupmode($cm, $course);

    if ($groupmode != SEPARATEGROUPS) {
        return $cache[$course->id][$newslettertemp->id];
    }

    if (has_capability('moodle/site:accessallgroups', context_module::instance($cm->id))) {
        return $cache[$course->id][$newslettertemp->id];
    }

    require_once($CFG->dirroot.'/course/lib.php');

    $modinfo = get_fast_modinfo($course);
    if (is_null($modinfo->groups)) {
        $modinfo->groups = groups_get_user_groups($course->id, $USER->id);
    }

    if (array_key_exists($cm->groupingid, $modinfo->groups)) {
        $mygroups = $modinfo->groups[$cm->groupingid];
    } else {
        $mygroups = false; // Will be set below
    }

    // add all groups posts
    if (empty($mygroups)) {
        $mygroups = array(-1=>-1);
    } else {
        $mygroups[-1] = -1;
    }

    list($mygroups_sql, $params) = $DB->get_in_or_equal($mygroups);
    $params[] = $newslettertemp->id;

    if (!empty($CFG->newslettertemp_enabletimedposts)) {
        $timedsql = "AND d.timestart < $now AND (d.timeend = 0 OR d.timeend > $now)";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    $sql = "SELECT COUNT(d.id)
              FROM {newslettertemp_discussions} d
             WHERE d.groupid $mygroups_sql AND d.newslettertemp = ?
                   $timedsql";

    return $DB->get_field_sql($sql, $params);
}

/**
 * How many posts by other users are unrated by a given user in the given discussion?
 *
 * TODO: Is this function still used anywhere?
 *
 * @param int $discussionid
 * @param int $userid
 * @return mixed
 */
function newslettertemp_count_unrated_posts($discussionid, $userid) {
    global $CFG, $DB;

    $sql = "SELECT COUNT(*) as num
              FROM {newslettertemp_posts}
             WHERE parent > 0
               AND discussion = :discussionid
               AND userid <> :userid";
    $params = array('discussionid' => $discussionid, 'userid' => $userid);
    $posts = $DB->get_record_sql($sql, $params);
    if ($posts) {
        $sql = "SELECT count(*) as num
                  FROM {newslettertemp_posts} p,
                       {rating} r
                 WHERE p.discussion = :discussionid AND
                       p.id = r.itemid AND
                       r.userid = userid AND
                       r.component = 'mod_newslettertemp' AND
                       r.ratingarea = 'post'";
        $rated = $DB->get_record_sql($sql, $params);
        if ($rated) {
            if ($posts->num > $rated->num) {
                return $posts->num - $rated->num;
            } else {
                return 0;    // Just in case there was a counting error
            }
        } else {
            return $posts->num;
        }
    } else {
        return 0;
    }
}

/**
 * Get all discussions in a newslettertemp
 *
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $cm
 * @param string $newslettertempsort
 * @param bool $fullpost
 * @param int $unused
 * @param int $limit
 * @param bool $userlastmodified
 * @param int $page
 * @param int $perpage
 * @return array
 */
function newslettertemp_get_discussions($cm, $newslettertempsort="d.timemodified DESC", $fullpost=true, $unused=-1, $limit=-1, $userlastmodified=false, $page=-1, $perpage=0) {
    global $CFG, $DB, $USER;

    $timelimit = '';

    $now = round(time(), -2);
    $params = array($cm->instance);

    $modcontext = context_module::instance($cm->id);

    if (!has_capability('mod/newslettertemp:viewdiscussion', $modcontext)) { /// User must have perms to view discussions
        return array();
    }

    if (!empty($CFG->newslettertemp_enabletimedposts)) { /// Users must fulfill timed posts

        if (!has_capability('mod/newslettertemp:viewhiddentimedposts', $modcontext)) {
            $timelimit = " AND ((d.timestart <= ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
            if (isloggedin()) {
                $timelimit .= " OR d.userid = ?";
                $params[] = $USER->id;
            }
            $timelimit .= ")";
        }
    }

    if ($limit > 0) {
        $limitfrom = 0;
        $limitnum  = $limit;
    } else if ($page != -1) {
        $limitfrom = $page*$perpage;
        $limitnum  = $perpage;
    } else {
        $limitfrom = 0;
        $limitnum  = 0;
    }

    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    if ($groupmode) {
        if (empty($modcontext)) {
            $modcontext = context_module::instance($cm->id);
        }

        if ($groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "";
            }

        } else {
            //seprate groups without access all
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "AND d.groupid = -1";
            }
        }
    } else {
        $groupselect = "";
    }


    if (empty($newslettertempsort)) {
        $newslettertempsort = "d.timemodified DESC";
    }
    if (empty($fullpost)) {
        $postdata = "p.id,p.subject,p.modified,p.discussion,p.userid";
    } else {
        $postdata = "p.*";
    }

    if (empty($userlastmodified)) {  // We don't need to know this
        $umfields = "";
        $umtable  = "";
    } else {
        $umfields = ", um.firstname AS umfirstname, um.lastname AS umlastname";
        $umtable  = " LEFT JOIN {user} um ON (d.usermodified = um.id)";
    }

    $sql = "SELECT $postdata, d.name, d.timemodified, d.usermodified, d.groupid, d.timestart, d.timeend,
                   u.firstname, u.lastname, u.email, u.picture, u.imagealt $umfields
              FROM {newslettertemp_discussions} d
                   JOIN {newslettertemp_posts} p ON p.discussion = d.id
                   JOIN {user} u ON p.userid = u.id
                   $umtable
             WHERE d.newslettertemp = ? AND p.parent = 0
                   $timelimit $groupselect
          ORDER BY $newslettertempsort";
    return $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
}

/**
 *
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $cm
 * @return array
 */
function newslettertemp_get_discussions_unread($cm) {
    global $CFG, $DB, $USER;

    $now = round(time(), -2);
    $cutoffdate = $now - ($CFG->newslettertemp_oldpostdays*24*60*60);

    $params = array();
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    if ($groupmode) {
        $modcontext = context_module::instance($cm->id);

        if ($groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = :currentgroup OR d.groupid = -1)";
                $params['currentgroup'] = $currentgroup;
            } else {
                $groupselect = "";
            }

        } else {
            //separate groups without access all
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = :currentgroup OR d.groupid = -1)";
                $params['currentgroup'] = $currentgroup;
            } else {
                $groupselect = "AND d.groupid = -1";
            }
        }
    } else {
        $groupselect = "";
    }

    if (!empty($CFG->newslettertemp_enabletimedposts)) {
        $timedsql = "AND d.timestart < :now1 AND (d.timeend = 0 OR d.timeend > :now2)";
        $params['now1'] = $now;
        $params['now2'] = $now;
    } else {
        $timedsql = "";
    }

    $sql = "SELECT d.id, COUNT(p.id) AS unread
              FROM {newslettertemp_discussions} d
                   JOIN {newslettertemp_posts} p     ON p.discussion = d.id
                   LEFT JOIN {newslettertemp_read} r ON (r.postid = p.id AND r.userid = $USER->id)
             WHERE d.newslettertemp = {$cm->instance}
                   AND p.modified >= :cutoffdate AND r.id is NULL
                   $groupselect
                   $timedsql
          GROUP BY d.id";
    $params['cutoffdate'] = $cutoffdate;

    if ($unreads = $DB->get_records_sql($sql, $params)) {
        foreach ($unreads as $unread) {
            $unreads[$unread->id] = $unread->unread;
        }
        return $unreads;
    } else {
        return array();
    }
}

/**
 * @global object
 * @global object
 * @global object
 * @uses CONEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $cm
 * @return array
 */
function newslettertemp_get_discussions_count($cm) {
    global $CFG, $DB, $USER;

    $now = round(time(), -2);
    $params = array($cm->instance);
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    if ($groupmode) {
        $modcontext = context_module::instance($cm->id);

        if ($groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "";
            }

        } else {
            //seprate groups without access all
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "AND d.groupid = -1";
            }
        }
    } else {
        $groupselect = "";
    }

    $cutoffdate = $now - ($CFG->newslettertemp_oldpostdays*24*60*60);

    $timelimit = "";

    if (!empty($CFG->newslettertemp_enabletimedposts)) {

        $modcontext = context_module::instance($cm->id);

        if (!has_capability('mod/newslettertemp:viewhiddentimedposts', $modcontext)) {
            $timelimit = " AND ((d.timestart <= ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
            if (isloggedin()) {
                $timelimit .= " OR d.userid = ?";
                $params[] = $USER->id;
            }
            $timelimit .= ")";
        }
    }

    $sql = "SELECT COUNT(d.id)
              FROM {newslettertemp_discussions} d
                   JOIN {newslettertemp_posts} p ON p.discussion = d.id
             WHERE d.newslettertemp = ? AND p.parent = 0
                   $groupselect $timelimit";

    return $DB->get_field_sql($sql, $params);
}


/**
 * Get all discussions started by a particular user in a course (or group)
 * This function no longer used ...
 *
 * @todo Remove this function if no longer used
 * @global object
 * @global object
 * @param int $courseid
 * @param int $userid
 * @param int $groupid
 * @return array
 */
function newslettertemp_get_user_discussions($courseid, $userid, $groupid=0) {
    global $CFG, $DB;
    $params = array($courseid, $userid);
    if ($groupid) {
        $groupselect = " AND d.groupid = ? ";
        $params[] = $groupid;
    } else  {
        $groupselect = "";
    }

    return $DB->get_records_sql("SELECT p.*, d.groupid, u.firstname, u.lastname, u.email, u.picture, u.imagealt,
                                   f.type as newslettertemptype, f.name as newslettertempname, f.id as newslettertempid
                              FROM {newslettertemp_discussions} d,
                                   {newslettertemp_posts} p,
                                   {user} u,
                                   {newslettertemp} f
                             WHERE d.course = ?
                               AND p.discussion = d.id
                               AND p.parent = 0
                               AND p.userid = u.id
                               AND u.id = ?
                               AND d.newslettertemp = f.id $groupselect
                          ORDER BY p.created DESC", $params);
}

/**
 * Get the list of potential subscribers to a newslettertemp.
 *
 * @param object $newslettertempcontext the newslettertemp context.
 * @param integer $groupid the id of a group, or 0 for all groups.
 * @param string $fields the list of fields to return for each user. As for get_users_by_capability.
 * @param string $sort sort order. As for get_users_by_capability.
 * @return array list of users.
 */
function newslettertemp_get_potential_subscribers($newslettertempcontext, $groupid, $fields, $sort = '') {
    global $DB;

    // only active enrolled users or everybody on the frontpage
    list($esql, $params) = get_enrolled_sql($newslettertempcontext, 'mod/newslettertemp:allowforcesubscribe', $groupid, true);
    if (!$sort) {
        list($sort, $sortparams) = users_order_by_sql('u');
        $params = array_merge($params, $sortparams);
    }

    $sql = "SELECT $fields
              FROM {user} u
              JOIN ($esql) je ON je.id = u.id
          ORDER BY $sort";

    return $DB->get_records_sql($sql, $params);
}

/**
 * Returns list of user objects that are subscribed to this newslettertemp
 *
 * @global object
 * @global object
 * @param object $course the course
 * @param newslettertemp $newslettertemp the newslettertemp
 * @param integer $groupid group id, or 0 for all.
 * @param object $context the newslettertemp context, to save re-fetching it where possible.
 * @param string $fields requested user fields (with "u." table prefix)
 * @return array list of users.
 */
function newslettertemp_subscribed_users($course, $newslettertemp, $groupid=0, $context = null, $fields = null) {
    global $CFG, $DB;

    if (empty($fields)) {
        $fields ="u.id,
                  u.username,
                  u.firstname,
                  u.lastname,
                  u.maildisplay,
                  u.mailformat,
                  u.maildigest,
                  u.imagealt,
                  u.email,
                  u.emailstop,
                  u.city,
                  u.country,
                  u.lastaccess,
                  u.lastlogin,
                  u.picture,
                  u.timezone,
                  u.theme,
                  u.lang,
                  u.trackforums,
                  u.mnethostid";
    }

    if (empty($context)) {
        $cm = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id, $course->id);
        $context = context_module::instance($cm->id);
    }

    if (newslettertemp_is_forcesubscribed($newslettertemp)) {
        $results = newslettertemp_get_potential_subscribers($context, $groupid, $fields, "u.email ASC");

    } else {
        // only active enrolled users or everybody on the frontpage
        list($esql, $params) = get_enrolled_sql($context, '', $groupid, true);
        $params['newslettertempid'] = $newslettertemp->id;
        $results = $DB->get_records_sql("SELECT $fields
                                           FROM {user} u
                                           JOIN ($esql) je ON je.id = u.id
                                           JOIN {newslettertemp_subscriptions} s ON s.userid = u.id
                                          WHERE s.newslettertemp = :newslettertempid
                                       ORDER BY u.email ASC", $params);
    }

    // Guest user should never be subscribed to a newslettertemp.
    unset($results[$CFG->siteguest]);

    return $results;
}



// OTHER FUNCTIONS ///////////////////////////////////////////////////////////


/**
 * @global object
 * @global object
 * @param int $courseid
 * @param string $type
 */
function newslettertemp_get_course_newslettertemp($courseid, $type) {
// How to set up special 1-per-course newslettertemps
    global $CFG, $DB, $OUTPUT;

    if ($newslettertemps = $DB->get_records_select("newslettertemp", "course = ? AND type = ?", array($courseid, $type), "id ASC")) {
        // There should always only be ONE, but with the right combination of
        // errors there might be more.  In this case, just return the oldest one (lowest ID).
        foreach ($newslettertemps as $newslettertemp) {
            return $newslettertemp;   // ie the first one
        }
    }

    // Doesn't exist, so create one now.
    $newslettertemp = new stdClass();
    $newslettertemp->course = $courseid;
    $newslettertemp->type = "$type";
    switch ($newslettertemp->type) {
        case "news":
            $newslettertemp->name  = get_string("namenews", "newslettertemp");
            $newslettertemp->intro = get_string("intronews", "newslettertemp");
            $newslettertemp->forcesubscribe = NEWSLETTERTEMP_FORCESUBSCRIBE;
            $newslettertemp->assessed = 0;
            if ($courseid == SITEID) {
                $newslettertemp->name  = get_string("sitenews");
                $newslettertemp->forcesubscribe = 0;
            }
            break;
        case "social":
            $newslettertemp->name  = get_string("namesocial", "newslettertemp");
            $newslettertemp->intro = get_string("introsocial", "newslettertemp");
            $newslettertemp->assessed = 0;
            $newslettertemp->forcesubscribe = 0;
            break;
        case "blog":
            $newslettertemp->name = get_string('blognewslettertemp', 'newslettertemp');
            $newslettertemp->intro = get_string('introblog', 'newslettertemp');
            $newslettertemp->assessed = 0;
            $newslettertemp->forcesubscribe = 0;
            break;
        default:
            echo $OUTPUT->notification("That newslettertemp type doesn't exist!");
            return false;
            break;
    }

    $newslettertemp->timemodified = time();
    $newslettertemp->id = $DB->insert_record("newslettertemp", $newslettertemp);

    if (! $module = $DB->get_record("modules", array("name" => "newslettertemp"))) {
        echo $OUTPUT->notification("Could not find newslettertemp module!!");
        return false;
    }
    $mod = new stdClass();
    $mod->course = $courseid;
    $mod->module = $module->id;
    $mod->instance = $newslettertemp->id;
    $mod->section = 0;
    include_once("$CFG->dirroot/course/lib.php");
    if (! $mod->coursemodule = add_course_module($mod) ) {
        echo $OUTPUT->notification("Could not add a new course module to the course '" . $courseid . "'");
        return false;
    }
    $sectionid = course_add_cm_to_section($courseid, $mod->coursemodule, 0);
    return $DB->get_record("newslettertemp", array("id" => "$newslettertemp->id"));
}


/**
 * Given the data about a posting, builds up the HTML to display it and
 * returns the HTML in a string.  This is designed for sending via HTML email.
 *
 * @global object
 * @param object $course
 * @param object $cm
 * @param object $newslettertemp
 * @param object $discussion
 * @param object $post
 * @param object $userform
 * @param object $userto
 * @param bool $ownpost
 * @param bool $reply
 * @param bool $link
 * @param bool $rate
 * @param string $footer
 * @return string
 */
function newslettertemp_make_mail_post($course, $cm, $newslettertemp, $discussion, $post, $userfrom, $userto,
                                       $ownpost = false, $reply = false, $link = false, $rate = false, $footer = "") {

    global $CFG, $OUTPUT;

    $modcontext = context_module::instance($cm->id);

    // add absolute file links
    $post->message = file_rewrite_pluginfile_urls($post->message, 'pluginfile.php', $modcontext->id, 'mod_newslettertemp', 'post', $post->id);

    $header = '';

    $attachments = newslettertemp_print_attachments($post, $cm, 'html');
    if ($attachments !== '') {
        $header = $attachments;
    }

    $content = newsletter_purify_html($post->message);

    $output = 
        "<table style=\"\">
          <tbody>
            <tr style=\"\">$header</tr>
            <tr style=\"\">$content</tr>
            <tr style=\"\">$footer</tr>
          </tbody>
        </table>";

    return $output;
}

function newsletter_purify_html($text, $options = array()) {
    global $CFG;

    $type = !empty($options['allowid']) ? 'allowid' : 'normal';
    static $purifiers = array();
    if (empty($purifiers[$type])) {

        // make sure the serializer dir exists, it should be fine if it disappears later during cache reset
        $cachedir = $CFG->cachedir.'/htmlpurifier';
        check_dir_exists($cachedir);

        require_once $CFG->libdir.'/htmlpurifier/HTMLPurifier.safe-includes.php';
        require_once $CFG->libdir.'/htmlpurifier/locallib.php';
        $config = HTMLPurifier_Config::createDefault();
        
        $config->set('HTML.DefinitionID', 'moodlehtml');
        $config->set('HTML.DefinitionRev', 2);
        $config->set('Cache.SerializerPath', $cachedir);
        $config->set('Cache.SerializerPermissions', $CFG->directorypermissions);
        $config->set('Core.NormalizeNewlines', false);
        $config->set('Core.ConvertDocumentToFragment', true);
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'XHTML 1.0 Transitional');
        $config->set('URI.AllowedSchemes', array('http'=>true, 'https'=>true, 'ftp'=>true, 'irc'=>true, 'nntp'=>true, 'news'=>true, 'rtsp'=>true, 'teamspeak'=>true, 'gopher'=>true, 'mms'=>true, 'mailto'=>true));
        $config->set('Attr.AllowedFrameTargets', array('_blank'));

        $config->set('Attr.EnableID', true);
        $config->set('HTML.ForbiddenAttributes', '*@id');

        if (!empty($CFG->allowobjectembed)) {
            $config->set('HTML.SafeObject', true);
            $config->set('Output.FlashCompat', true);
            $config->set('HTML.SafeEmbed', true);
        }

        if ($type === 'allowid') {
            $config->set('Attr.EnableID', true);
        }

        if ($def = $config->maybeGetRawHTMLDefinition()) {
            $def->addElement('nolink', 'Block', 'Flow', array());                       // skip our filters inside
            $def->addElement('tex', 'Inline', 'Inline', array());                       // tex syntax, equivalent to $$xx$$
            $def->addElement('algebra', 'Inline', 'Inline', array());                   // algebra syntax, equivalent to @@xx@@
            $def->addElement('lang', 'Block', 'Flow', array(), array('lang'=>'CDATA')); // old and future style multilang - only our hacked lang attribute
            $def->addAttribute('span', 'xxxlang', 'CDATA');                             // current problematic multilang
        }

        $purifier = new HTMLPurifier($config);
        $purifiers[$type] = $purifier;
    } else {
        $purifier = $purifiers[$type];
    }

    $multilang = (strpos($text, 'class="multilang"') !== false);

    if ($multilang) {
        $text = preg_replace('/<span(\s+lang="([a-zA-Z0-9_-]+)"|\s+class="multilang"){2}\s*>/', '<span xxxlang="${2}">', $text);
    }
    
    $text = $purifier->purify($text);
    
    if ($multilang) {
        $text = preg_replace('/<span xxxlang="([a-zA-Z0-9_-]+)">/', '<span lang="${1}" class="multilang">', $text);
    }

    return $text;
}

/**
 * Print a newslettertemp post
 *
 * @global object
 * @global object
 * @uses NEWSLETTERTEMP_MODE_THREADED
 * @uses PORTFOLIO_FORMAT_PLAINHTML
 * @uses PORTFOLIO_FORMAT_FILE
 * @uses PORTFOLIO_FORMAT_RICHHTML
 * @uses PORTFOLIO_ADD_TEXT_LINK
 * @uses CONTEXT_MODULE
 * @param object $post The post to print.
 * @param object $discussion
 * @param object $newslettertemp
 * @param object $cm
 * @param object $course
 * @param boolean $ownpost Whether this post belongs to the current user.
 * @param boolean $reply Whether to print a 'reply' link at the bottom of the message.
 * @param boolean $link Just print a shortened version of the post as a link to the full post.
 * @param string $footer Extra stuff to print after the message.
 * @param string $highlight Space-separated list of terms to highlight.
 * @param int $post_read true, false or -99. If we already know whether this user
 *          has read this post, pass that in, otherwise, pass in -99, and this
 *          function will work it out.
 * @param boolean $dummyifcantsee When newslettertemp_user_can_see_post says that
 *          the current user can't see this post, if this argument is true
 *          (the default) then print a dummy 'you can't see this post' post.
 *          If false, don't output anything at all.
 * @param bool|null $istracked
 * @return void
 */
function newslettertemp_print_post($post, $discussion, $newslettertemp, &$cm, $course, $ownpost=false, $reply=false, $link=false,
                          $footer="", $highlight="", $postisread=null, $dummyifcantsee=true, $istracked=null, $return=false) {
    global $USER, $CFG, $OUTPUT;

    require_once($CFG->libdir . '/filelib.php');

    // String cache
    static $str;

    $modcontext = context_module::instance($cm->id);

    $post->course = $course->id;
    $post->newslettertemp  = $newslettertemp->id;
    $post->message = file_rewrite_pluginfile_urls($post->message, 'pluginfile.php', $modcontext->id, 'mod_newslettertemp', 'post', $post->id);
    if (!empty($CFG->enableplagiarism)) {
        require_once($CFG->libdir.'/plagiarismlib.php');
        $post->message .= plagiarism_get_links(array('userid' => $post->userid,
            'content' => $post->message,
            'cmid' => $cm->id,
            'course' => $post->course,
            'newslettertemp' => $post->newslettertemp));
    }

    // caching
    if (!isset($cm->cache)) {
        $cm->cache = new stdClass;
    }

    if (!isset($cm->cache->caps)) {
        $cm->cache->caps = array();
        $cm->cache->caps['mod/newslettertemp:viewdiscussion']   = has_capability('mod/newslettertemp:viewdiscussion', $modcontext);
        $cm->cache->caps['moodle/site:viewfullnames']  = has_capability('moodle/site:viewfullnames', $modcontext);
        $cm->cache->caps['mod/newslettertemp:editanypost']      = has_capability('mod/newslettertemp:editanypost', $modcontext);
        $cm->cache->caps['mod/newslettertemp:splitdiscussions'] = has_capability('mod/newslettertemp:splitdiscussions', $modcontext);
        $cm->cache->caps['mod/newslettertemp:deleteownpost']    = has_capability('mod/newslettertemp:deleteownpost', $modcontext);
        $cm->cache->caps['mod/newslettertemp:deleteanypost']    = has_capability('mod/newslettertemp:deleteanypost', $modcontext);
        $cm->cache->caps['mod/newslettertemp:viewanyrating']    = has_capability('mod/newslettertemp:viewanyrating', $modcontext);
        $cm->cache->caps['mod/newslettertemp:exportpost']       = has_capability('mod/newslettertemp:exportpost', $modcontext);
        $cm->cache->caps['mod/newslettertemp:exportownpost']    = has_capability('mod/newslettertemp:exportownpost', $modcontext);
    }

    if (!isset($cm->uservisible)) {
        $cm->uservisible = coursemodule_visible_for_user($cm);
    }

    if ($istracked && is_null($postisread)) {
        $postisread = newslettertemp_tp_is_post_read($USER->id, $post);
    }

    if (!newslettertemp_user_can_see_post($newslettertemp, $discussion, $post, NULL, $cm)) {
        $output = '';
        if (!$dummyifcantsee) {
            if ($return) {
                return $output;
            }
            echo $output;
            return;
        }
        $output .= html_writer::tag('a', '', array('id'=>'p'.$post->id));
        $output .= html_writer::start_tag('div', array('class'=>'newslettertemppost clearfix'));
        $output .= html_writer::start_tag('div', array('class'=>'row header'));
        $output .= html_writer::tag('div', '', array('class'=>'left picture')); // Picture
        if ($post->parent) {
            $output .= html_writer::start_tag('div', array('class'=>'topic'));
        } else {
            $output .= html_writer::start_tag('div', array('class'=>'topic starter'));
        }
        $output .= html_writer::tag('div', get_string('newslettertempsubjecthidden','newslettertemp'), array('class'=>'subject')); // Subject
        $output .= html_writer::tag('div', get_string('newslettertempauthorhidden','newslettertemp'), array('class'=>'author')); // author
        $output .= html_writer::end_tag('div');
        $output .= html_writer::end_tag('div'); // row
        $output .= html_writer::start_tag('div', array('class'=>'row'));
        $output .= html_writer::tag('div', '&nbsp;', array('class'=>'left side')); // Groups
        $output .= html_writer::tag('div', get_string('newslettertempbodyhidden','newslettertemp'), array('class'=>'content')); // Content
        $output .= html_writer::end_tag('div'); // row
        $output .= html_writer::end_tag('div'); // newslettertemppost

        if ($return) {
            return $output;
        }
        echo $output;
        return;
    }

    if (empty($str)) {
        $str = new stdClass;
        $str->edit         = get_string('edit', 'newslettertemp');
        $str->delete       = get_string('delete', 'newslettertemp');
        $str->reply        = get_string('reply', 'newslettertemp');
        $str->parent       = get_string('parent', 'newslettertemp');
        $str->pruneheading = get_string('pruneheading', 'newslettertemp');
        $str->prune        = get_string('prune', 'newslettertemp');
        $str->displaymode     = get_user_preferences('newslettertemp_displaymode', $CFG->newslettertemp_displaymode);
        $str->markread     = get_string('markread', 'newslettertemp');
        $str->markunread   = get_string('markunread', 'newslettertemp');
    }

    $discussionlink = new moodle_url('/mod/newslettertemp/discuss.php', array('d'=>$post->discussion));

    // Build an object that represents the posting user
    $postuser = new stdClass;
    $postuser->id        = $post->userid;
    $postuser->firstname = $post->firstname;
    $postuser->lastname  = $post->lastname;
    $postuser->imagealt  = $post->imagealt;
    $postuser->picture   = $post->picture;
    $postuser->email     = $post->email;
    // Some handy things for later on
    $postuser->fullname    = fullname($postuser, $cm->cache->caps['moodle/site:viewfullnames']);
    $postuser->profilelink = new moodle_url('/user/view.php', array('id'=>$post->userid, 'course'=>$course->id));

    // Prepare the groups the posting user belongs to
    if (isset($cm->cache->usersgroups)) {
        $groups = array();
        if (isset($cm->cache->usersgroups[$post->userid])) {
            foreach ($cm->cache->usersgroups[$post->userid] as $gid) {
                $groups[$gid] = $cm->cache->groups[$gid];
            }
        }
    } else {
        $groups = groups_get_all_groups($course->id, $post->userid, $cm->groupingid);
    }

    // Prepare the attachements for the post, files then images
    list($attachments, $attachedimages) = newslettertemp_print_attachments($post, $cm, 'separateimages');

    // Determine if we need to shorten this post
    $shortenpost = ($link && (strlen(strip_tags($post->message)) > $CFG->newslettertemp_longpost));


    // Prepare an array of commands
    $commands = array();

    // SPECIAL CASE: The front page can display a news item post to non-logged in users.
    // Don't display the mark read / unread controls in this case.
    if ($istracked && $CFG->newslettertemp_usermarksread && isloggedin()) {
        $url = new moodle_url($discussionlink, array('postid'=>$post->id, 'mark'=>'unread'));
        $text = $str->markunread;
        if (!$postisread) {
            $url->param('mark', 'read');
            $text = $str->markread;
        }
        if ($str->displaymode == NEWSLETTERTEMP_MODE_THREADED) {
            $url->param('parent', $post->parent);
        } else {
            $url->set_anchor('p'.$post->id);
        }
        $commands[] = array('url'=>$url, 'text'=>$text);
    }

    // Zoom in to the parent specifically
    if ($post->parent) {
        $url = new moodle_url($discussionlink);
        if ($str->displaymode == NEWSLETTERTEMP_MODE_THREADED) {
            $url->param('parent', $post->parent);
        } else {
            $url->set_anchor('p'.$post->parent);
        }
        $commands[] = array('url'=>$url, 'text'=>$str->parent);
    }

    // Hack for allow to edit news posts those are not displayed yet until they are displayed
    $age = time() - $post->created;
    if (!$post->parent && $newslettertemp->type == 'news' && $discussion->timestart > time()) {
        $age = 0;
    }
    if (($ownpost && $age < $CFG->maxeditingtime) || $cm->cache->caps['mod/newslettertemp:editanypost']) {
        $commands[] = array('url'=>new moodle_url('/mod/newslettertemp/post.php', array('edit'=>$post->id)), 'text'=>$str->edit);
    }

    if ($cm->cache->caps['mod/newslettertemp:splitdiscussions'] && $post->parent && $newslettertemp->type != 'single') {
        $commands[] = array('url'=>new moodle_url('/mod/newslettertemp/post.php', array('prune'=>$post->id)), 'text'=>$str->prune, 'title'=>$str->pruneheading);
    }

    if (($ownpost && $age < $CFG->maxeditingtime && $cm->cache->caps['mod/newslettertemp:deleteownpost']) || $cm->cache->caps['mod/newslettertemp:deleteanypost']) {
        $commands[] = array('url'=>new moodle_url('/mod/newslettertemp/post.php', array('delete'=>$post->id)), 'text'=>$str->delete);
    }

    if ($reply) {
        $commands[] = array('url'=>new moodle_url('/mod/newslettertemp/post.php#mformnewslettertemp', array('reply'=>$post->id)), 'text'=>$str->reply);
    }

    if ($CFG->enableportfolios && ($cm->cache->caps['mod/newslettertemp:exportpost'] || ($ownpost && $cm->cache->caps['mod/newslettertemp:exportownpost']))) {
        $p = array('postid' => $post->id);
        require_once($CFG->libdir.'/portfoliolib.php');
        $button = new portfolio_add_button();
        $button->set_callback_options('newslettertemp_portfolio_caller', array('postid' => $post->id), 'mod_newslettertemp');
        if (empty($attachments)) {
            $button->set_formats(PORTFOLIO_FORMAT_PLAINHTML);
        } else {
            $button->set_formats(PORTFOLIO_FORMAT_RICHHTML);
        }

        $porfoliohtml = $button->to_html(PORTFOLIO_ADD_TEXT_LINK);
        if (!empty($porfoliohtml)) {
            $commands[] = $porfoliohtml;
        }
    }
    // Finished building commands


    // Begin output

    $output  = '';

    if ($istracked) {
        if ($postisread) {
            $newslettertemppostclass = ' read';
        } else {
            $newslettertemppostclass = ' unread';
            $output .= html_writer::tag('a', '', array('name'=>'unread'));
        }
    } else {
        // ignore trackign status if not tracked or tracked param missing
        $newslettertemppostclass = '';
    }

    $topicclass = '';
    if (empty($post->parent)) {
        $topicclass = ' firstpost starter';
    }

    $output .= html_writer::tag('a', '', array('id'=>'p'.$post->id));
    $output .= html_writer::start_tag('div', array('class'=>'newslettertemppost clearfix'.$newslettertemppostclass.$topicclass));
    $output .= html_writer::start_tag('div', array('class'=>'row header clearfix'));
    $output .= html_writer::start_tag('div', array('class'=>'left picture'));
    $output .= $OUTPUT->user_picture($postuser, array('courseid'=>$course->id));
    $output .= html_writer::end_tag('div');


    $output .= html_writer::start_tag('div', array('class'=>'topic'.$topicclass));

    $postsubject = $post->subject;
    if (empty($post->subjectnoformat)) {
        $postsubject = format_string($postsubject);
    }
    $output .= html_writer::tag('div', $postsubject, array('class'=>'subject'));

    $by = new stdClass();
    $by->name = html_writer::link($postuser->profilelink, $postuser->fullname);
    $by->date = userdate($post->modified);
    $output .= html_writer::tag('div', get_string('bynameondate', 'newslettertemp', $by), array('class'=>'author'));

    $output .= html_writer::end_tag('div'); //topic
    $output .= html_writer::end_tag('div'); //row

    $output .= html_writer::start_tag('div', array('class'=>'row maincontent clearfix'));
    $output .= html_writer::start_tag('div', array('class'=>'left'));

    $groupoutput = '';
    if ($groups) {
        $groupoutput = print_group_picture($groups, $course->id, false, true, true);
    }
    if (empty($groupoutput)) {
        $groupoutput = '&nbsp;';
    }
    $output .= html_writer::tag('div', $groupoutput, array('class'=>'grouppictures'));

    $output .= html_writer::end_tag('div'); //left side
    $output .= html_writer::start_tag('div', array('class'=>'no-overflow'));
    $output .= html_writer::start_tag('div', array('class'=>'content'));
    if (!empty($attachments)) {
        $output .= html_writer::tag('div', $attachments, array('class'=>'attachments'));
    }

    $options = new stdClass;
    $options->para    = false;
    $options->trusted = $post->messagetrust;
    $options->context = $modcontext;
    if ($shortenpost) {
        // Prepare shortened version
        $postclass    = 'shortenedpost';
        $postcontent  = format_text(newslettertemp_shorten_post($post->message), $post->messageformat, $options, $course->id);
        $postcontent .= html_writer::link($discussionlink, get_string('readtherest', 'newslettertemp'));
        $postcontent .= html_writer::tag('span', '('.get_string('numwords', 'moodle', count_words(strip_tags($post->message))).')...', array('class'=>'post-word-count'));
    } else {
        // Prepare whole post
        $postclass    = 'fullpost';
        $postcontent  = format_text($post->message, $post->messageformat, $options, $course->id);
        if (!empty($highlight)) {
            $postcontent = highlight($highlight, $postcontent);
        }
        $postcontent .= html_writer::tag('div', $attachedimages, array('class'=>'attachedimages'));
    }
    // Output the post content
    $output .= html_writer::tag('div', $postcontent, array('class'=>'posting '.$postclass));
    $output .= html_writer::end_tag('div'); // Content
    $output .= html_writer::end_tag('div'); // Content mask
    $output .= html_writer::end_tag('div'); // Row

    $output .= html_writer::start_tag('div', array('class'=>'row side'));
    $output .= html_writer::tag('div','&nbsp;', array('class'=>'left'));
    $output .= html_writer::start_tag('div', array('class'=>'options clearfix'));

    // Output ratings
    if (!empty($post->rating)) {
        $output .= html_writer::tag('div', $OUTPUT->render($post->rating), array('class'=>'newslettertemp-post-rating'));
    }

    // Output the commands
    $commandhtml = array();
    foreach ($commands as $command) {
        if (is_array($command)) {
            $commandhtml[] = html_writer::link($command['url'], $command['text']);
        } else {
            $commandhtml[] = $command;
        }
    }
    $output .= html_writer::tag('div', implode(' | ', $commandhtml), array('class'=>'commands'));

    // Output link to post if required
    if ($link) {
        if ($post->replies == 1) {
            $replystring = get_string('repliesone', 'newslettertemp', $post->replies);
        } else {
            $replystring = get_string('repliesmany', 'newslettertemp', $post->replies);
        }

        $output .= html_writer::start_tag('div', array('class'=>'link'));
        $output .= html_writer::link($discussionlink, get_string('discussthistopic', 'newslettertemp'));
        $output .= '&nbsp;('.$replystring.')';
        $output .= html_writer::end_tag('div'); // link
    }

    // Output footer if required
    if ($footer) {
        $output .= html_writer::tag('div', $footer, array('class'=>'footer'));
    }

    // Close remaining open divs
    $output .= html_writer::end_tag('div'); // content
    $output .= html_writer::end_tag('div'); // row
    $output .= html_writer::end_tag('div'); // newslettertemppost

    // Mark the newslettertemp post as read if required
    if ($istracked && !$CFG->newslettertemp_usermarksread && !$postisread) {
        newslettertemp_tp_mark_post_read($USER->id, $post, $newslettertemp->id);
    }

    if ($return) {
        return $output;
    }
    echo $output;
    return;
}

/**
 * Return rating related permissions
 *
 * @param string $options the context id
 * @return array an associative array of the user's rating permissions
 */
function newslettertemp_rating_permissions($contextid, $component, $ratingarea) {
    $context = context::instance_by_id($contextid, MUST_EXIST);
    if ($component != 'mod_newslettertemp' || $ratingarea != 'post') {
        // We don't know about this component/ratingarea so just return null to get the
        // default restrictive permissions.
        return null;
    }
    return array(
        'view'    => has_capability('mod/newslettertemp:viewrating', $context),
        'viewany' => has_capability('mod/newslettertemp:viewanyrating', $context),
        'viewall' => has_capability('mod/newslettertemp:viewallratings', $context),
        'rate'    => has_capability('mod/newslettertemp:rate', $context)
    );
}

/**
 * Validates a submitted rating
 * @param array $params submitted data
 *            context => object the context in which the rated items exists [required]
 *            component => The component for this module - should always be mod_newslettertemp [required]
 *            ratingarea => object the context in which the rated items exists [required]
 *            itemid => int the ID of the object being rated [required]
 *            scaleid => int the scale from which the user can select a rating. Used for bounds checking. [required]
 *            rating => int the submitted rating [required]
 *            rateduserid => int the id of the user whose items have been rated. NOT the user who submitted the ratings. 0 to update all. [required]
 *            aggregation => int the aggregation method to apply when calculating grades ie RATING_AGGREGATE_AVERAGE [required]
 * @return boolean true if the rating is valid. Will throw rating_exception if not
 */
function newslettertemp_rating_validate($params) {
    global $DB, $USER;

    // Check the component is mod_newslettertemp
    if ($params['component'] != 'mod_newslettertemp') {
        throw new rating_exception('invalidcomponent');
    }

    // Check the ratingarea is post (the only rating area in newslettertemp)
    if ($params['ratingarea'] != 'post') {
        throw new rating_exception('invalidratingarea');
    }

    // Check the rateduserid is not the current user .. you can't rate your own posts
    if ($params['rateduserid'] == $USER->id) {
        throw new rating_exception('nopermissiontorate');
    }

    // Fetch all the related records ... we need to do this anyway to call newslettertemp_user_can_see_post
    $post = $DB->get_record('newslettertemp_posts', array('id' => $params['itemid'], 'userid' => $params['rateduserid']), '*', MUST_EXIST);
    $discussion = $DB->get_record('newslettertemp_discussions', array('id' => $post->discussion), '*', MUST_EXIST);
    $newslettertemp = $DB->get_record('newslettertemp', array('id' => $discussion->newslettertemp), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $newslettertemp->course), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id, $course->id , false, MUST_EXIST);
    $context = context_module::instance($cm->id);

    // Make sure the context provided is the context of the newslettertemp
    if ($context->id != $params['context']->id) {
        throw new rating_exception('invalidcontext');
    }

    if ($newslettertemp->scale != $params['scaleid']) {
        //the scale being submitted doesnt match the one in the database
        throw new rating_exception('invalidscaleid');
    }

    // check the item we're rating was created in the assessable time window
    if (!empty($newslettertemp->assesstimestart) && !empty($newslettertemp->assesstimefinish)) {
        if ($post->created < $newslettertemp->assesstimestart || $post->created > $newslettertemp->assesstimefinish) {
            throw new rating_exception('notavailable');
        }
    }

    //check that the submitted rating is valid for the scale

    // lower limit
    if ($params['rating'] < 0  && $params['rating'] != RATING_UNSET_RATING) {
        throw new rating_exception('invalidnum');
    }

    // upper limit
    if ($newslettertemp->scale < 0) {
        //its a custom scale
        $scalerecord = $DB->get_record('scale', array('id' => -$newslettertemp->scale));
        if ($scalerecord) {
            $scalearray = explode(',', $scalerecord->scale);
            if ($params['rating'] > count($scalearray)) {
                throw new rating_exception('invalidnum');
            }
        } else {
            throw new rating_exception('invalidscaleid');
        }
    } else if ($params['rating'] > $newslettertemp->scale) {
        //if its numeric and submitted rating is above maximum
        throw new rating_exception('invalidnum');
    }

    // Make sure groups allow this user to see the item they're rating
    if ($discussion->groupid > 0 and $groupmode = groups_get_activity_groupmode($cm, $course)) {   // Groups are being used
        if (!groups_group_exists($discussion->groupid)) { // Can't find group
            throw new rating_exception('cannotfindgroup');//something is wrong
        }

        if (!groups_is_member($discussion->groupid) and !has_capability('moodle/site:accessallgroups', $context)) {
            // do not allow rating of posts from other groups when in SEPARATEGROUPS or VISIBLEGROUPS
            throw new rating_exception('notmemberofgroup');
        }
    }

    // perform some final capability checks
    if (!newslettertemp_user_can_see_post($newslettertemp, $discussion, $post, $USER, $cm)) {
        throw new rating_exception('nopermissiontorate');
    }

    return true;
}


/**
 * This function prints the overview of a discussion in the newslettertemp listing.
 * It needs some discussion information and some post information, these
 * happen to be combined for efficiency in the $post parameter by the function
 * that calls this one: newslettertemp_print_latest_discussions()
 *
 * @global object
 * @global object
 * @param object $post The post object (passed by reference for speed).
 * @param object $newslettertemp The newslettertemp object.
 * @param int $group Current group.
 * @param string $datestring Format to use for the dates.
 * @param boolean $cantrack Is tracking enabled for this newslettertemp.
 * @param boolean $newslettertemptracked Is the user tracking this newslettertemp.
 * @param boolean $canviewparticipants True if user has the viewparticipants permission for this course
 */
function newslettertemp_print_discussion_header(&$post, $newslettertemp, $group=-1, $datestring="",
                                        $cantrack=true, $newslettertemptracked=true, $canviewparticipants=true, $modcontext=NULL) {

    global $USER, $CFG, $OUTPUT;

    static $rowcount;
    static $strmarkalldread;

    if (empty($modcontext)) {
        if (!$cm = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id, $newslettertemp->course)) {
            print_error('invalidcoursemodule');
        }
        $modcontext = context_module::instance($cm->id);
    }

    if (!isset($rowcount)) {
        $rowcount = 0;
        $strmarkalldread = get_string('markalldread', 'newslettertemp');
    } else {
        $rowcount = ($rowcount + 1) % 2;
    }

    $post->subject = format_string($post->subject,true);

    echo "\n\n";
    echo '<tr class="discussion r'.$rowcount.'">';

    // Topic
    echo '<td class="topic starter">';
    echo '<a href="'.$CFG->wwwroot.'/mod/newslettertemp/discuss.php?d='.$post->discussion.'">'.$post->subject.'</a>';
    echo "</td>\n";

    // Picture
    $postuser = new stdClass();
    $postuser->id = $post->userid;
    $postuser->firstname = $post->firstname;
    $postuser->lastname = $post->lastname;
    $postuser->imagealt = $post->imagealt;
    $postuser->picture = $post->picture;
    $postuser->email = $post->email;

    echo '<td class="picture">';
    echo $OUTPUT->user_picture($postuser, array('courseid'=>$newslettertemp->course));
    echo "</td>\n";

    // User name
    $fullname = fullname($post, has_capability('moodle/site:viewfullnames', $modcontext));
    echo '<td class="author">';
    echo '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$post->userid.'&amp;course='.$newslettertemp->course.'">'.$fullname.'</a>';
    echo "</td>\n";

    // Group picture
    if ($group !== -1) {  // Groups are active - group is a group data object or NULL
        echo '<td class="picture group">';
        if (!empty($group->picture) and empty($group->hidepicture)) {
            print_group_picture($group, $newslettertemp->course, false, false, true);
        } else if (isset($group->id)) {
            if($canviewparticipants) {
                echo '<a href="'.$CFG->wwwroot.'/user/index.php?id='.$newslettertemp->course.'&amp;group='.$group->id.'">'.$group->name.'</a>';
            } else {
                echo $group->name;
            }
        }
        echo "</td>\n";
    }

    if (has_capability('mod/newslettertemp:viewdiscussion', $modcontext)) {   // Show the column with replies
        echo '<td class="replies">';
        echo '<a href="'.$CFG->wwwroot.'/mod/newslettertemp/discuss.php?d='.$post->discussion.'">';
        echo $post->replies.'</a>';
        echo "</td>\n";

        if ($cantrack) {
            echo '<td class="replies">';
            if ($newslettertemptracked) {
                if ($post->unread > 0) {
                    echo '<span class="unread">';
                    echo '<a href="'.$CFG->wwwroot.'/mod/newslettertemp/discuss.php?d='.$post->discussion.'#unread">';
                    echo $post->unread;
                    echo '</a>';
                    echo '<a title="'.$strmarkalldread.'" href="'.$CFG->wwwroot.'/mod/newslettertemp/markposts.php?f='.
                         $newslettertemp->id.'&amp;d='.$post->discussion.'&amp;mark=read&amp;returnpage=view.php">' .
                         '<img src="'.$OUTPUT->pix_url('t/markasread') . '" class="iconsmall" alt="'.$strmarkalldread.'" /></a>';
                    echo '</span>';
                } else {
                    echo '<span class="read">';
                    echo $post->unread;
                    echo '</span>';
                }
            } else {
                echo '<span class="read">';
                echo '-';
                echo '</span>';
            }
            echo "</td>\n";
        }
    }

    echo '<td class="lastpost">';
    $usedate = (empty($post->timemodified)) ? $post->modified : $post->timemodified;  // Just in case
    $parenturl = (empty($post->lastpostid)) ? '' : '&amp;parent='.$post->lastpostid;
    $usermodified = new stdClass();
    $usermodified->id        = $post->usermodified;
    $usermodified->firstname = $post->umfirstname;
    $usermodified->lastname  = $post->umlastname;
    echo '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$post->usermodified.'&amp;course='.$newslettertemp->course.'">'.
         fullname($usermodified).'</a><br />';
    echo '<a href="'.$CFG->wwwroot.'/mod/newslettertemp/discuss.php?d='.$post->discussion.$parenturl.'">'.
          userdate($usedate, $datestring).'</a>';
    echo "</td>\n";

    echo "</tr>\n\n";

}


/**
 * Given a post object that we already know has a long message
 * this function truncates the message nicely to the first
 * sane place between $CFG->newslettertemp_longpost and $CFG->newslettertemp_shortpost
 *
 * @global object
 * @param string $message
 * @return string
 */
function newslettertemp_shorten_post($message) {

   global $CFG;

   $i = 0;
   $tag = false;
   $length = strlen($message);
   $count = 0;
   $stopzone = false;
   $truncate = 0;

   for ($i=0; $i<$length; $i++) {
       $char = $message[$i];

       switch ($char) {
           case "<":
               $tag = true;
               break;
           case ">":
               $tag = false;
               break;
           default:
               if (!$tag) {
                   if ($stopzone) {
                       if ($char == ".") {
                           $truncate = $i+1;
                           break 2;
                       }
                   }
                   $count++;
               }
               break;
       }
       if (!$stopzone) {
           if ($count > $CFG->newslettertemp_shortpost) {
               $stopzone = true;
           }
       }
   }

   if (!$truncate) {
       $truncate = $i;
   }

   return substr($message, 0, $truncate);
}

/**
 * Print the drop down that allows the user to select how they want to have
 * the discussion displayed.
 *
 * @param int $id newslettertemp id if $newslettertemptype is 'single',
 *              discussion id for any other newslettertemp type
 * @param mixed $mode newslettertemp layout mode
 * @param string $newslettertemptype optional
 */
function newslettertemp_print_mode_form($id, $mode, $newslettertemptype='') {
    global $OUTPUT;
    if ($newslettertemptype == 'single') {
        $select = new single_select(new moodle_url("/mod/newslettertemp/view.php", array('f'=>$id)), 'mode', newslettertemp_get_layout_modes(), $mode, null, "mode");
        $select->set_label(get_string('displaymode', 'newslettertemp'), array('class' => 'accesshide'));
        $select->class = "newslettertempmode";
    } else {
        $select = new single_select(new moodle_url("/mod/newslettertemp/discuss.php", array('d'=>$id)), 'mode', newslettertemp_get_layout_modes(), $mode, null, "mode");
        $select->set_label(get_string('displaymode', 'newslettertemp'), array('class' => 'accesshide'));
    }
    echo $OUTPUT->render($select);
}

/**
 * @global object
 * @param object $course
 * @param string $search
 * @return string
 */
function newslettertemp_search_form($course, $search='') {
    global $CFG, $OUTPUT;

    $output  = '<div class="newslettertempsearch">';
    $output .= '<form action="'.$CFG->wwwroot.'/mod/newslettertemp/search.php" style="display:inline">';
    $output .= '<fieldset class="invisiblefieldset">';
    $output .= $OUTPUT->help_icon('search');
    $output .= '<label class="accesshide" for="search" >'.get_string('search', 'newslettertemp').'</label>';
    $output .= '<input id="search" name="search" type="text" size="18" value="'.s($search, true).'" alt="search" />';
    $output .= '<label class="accesshide" for="searchnewslettertemps" >'.get_string('searchnewslettertemps', 'newslettertemp').'</label>';
    $output .= '<input id="searchnewslettertemps" value="'.get_string('searchnewslettertemps', 'newslettertemp').'" type="submit" />';
    $output .= '<input name="id" type="hidden" value="'.$course->id.'" />';
    $output .= '</fieldset>';
    $output .= '</form>';
    $output .= '</div>';

    return $output;
}


/**
 * @global object
 * @global object
 */
function newslettertemp_set_return() {
    global $CFG, $SESSION;

    if (! isset($SESSION->fromdiscussion)) {
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $referer = $_SERVER['HTTP_REFERER'];
        } else {
            $referer = "";
        }
        // If the referer is NOT a login screen then save it.
        if (! strncasecmp("$CFG->wwwroot/login", $referer, 300)) {
            $SESSION->fromdiscussion = $_SERVER["HTTP_REFERER"];
        }
    }
}


/**
 * @global object
 * @param string $default
 * @return string
 */
function newslettertemp_go_back_to($default) {
    global $SESSION;

    if (!empty($SESSION->fromdiscussion)) {
        $returnto = $SESSION->fromdiscussion;
        unset($SESSION->fromdiscussion);
        return $returnto;
    } else {
        return $default;
    }
}

/**
 * Given a discussion object that is being moved to $newslettertempto,
 * this function checks all posts in that discussion
 * for attachments, and if any are found, these are
 * moved to the new newslettertemp directory.
 *
 * @global object
 * @param object $discussion
 * @param int $newslettertempfrom source newslettertemp id
 * @param int $newslettertempto target newslettertemp id
 * @return bool success
 */
function newslettertemp_move_attachments($discussion, $newslettertempfrom, $newslettertempto) {
    global $DB;

    $fs = get_file_storage();

    $newcm = get_coursemodule_from_instance('newslettertemp', $newslettertempto);
    $oldcm = get_coursemodule_from_instance('newslettertemp', $newslettertempfrom);

    $newcontext = context_module::instance($newcm->id);
    $oldcontext = context_module::instance($oldcm->id);

    // loop through all posts, better not use attachment flag ;-)
    if ($posts = $DB->get_records('newslettertemp_posts', array('discussion'=>$discussion->id), '', 'id, attachment')) {
        foreach ($posts as $post) {
            $fs->move_area_files_to_new_context($oldcontext->id,
                    $newcontext->id, 'mod_newslettertemp', 'post', $post->id);
            $attachmentsmoved = $fs->move_area_files_to_new_context($oldcontext->id,
                    $newcontext->id, 'mod_newslettertemp', 'attachment', $post->id);
            if ($attachmentsmoved > 0 && $post->attachment != '1') {
                // Weird - let's fix it
                $post->attachment = '1';
                $DB->update_record('newslettertemp_posts', $post);
            } else if ($attachmentsmoved == 0 && $post->attachment != '') {
                // Weird - let's fix it
                $post->attachment = '';
                $DB->update_record('newslettertemp_posts', $post);
            }
        }
    }

    return true;
}

/**
 * Returns attachments as formated text/html optionally with separate images
 *
 * @global object
 * @global object
 * @global object
 * @param object $post
 * @param object $cm
 * @param string $type html/text/separateimages
 * @return mixed string or array of (html text withouth images and image HTML)
 */
function newslettertemp_print_attachments($post, $cm, $type) {
    global $CFG, $DB, $USER, $OUTPUT;

    if (empty($post->attachment)) {
        return $type !== 'separateimages' ? '' : array('', '');
    }

    if (!in_array($type, array('separateimages', 'html', 'text'))) {
        return $type !== 'separateimages' ? '' : array('', '');
    }

    if (!$context = context_module::instance($cm->id)) {
        return $type !== 'separateimages' ? '' : array('', '');
    }
    $strattachment = get_string('attachment', 'newslettertemp');

    $fs = get_file_storage();

    $imagereturn = '';
    $output = '';

    $canexport = !empty($CFG->enableportfolios) && (has_capability('mod/newslettertemp:exportpost', $context) || ($post->userid == $USER->id && has_capability('mod/newslettertemp:exportownpost', $context)));

    if ($canexport) {
        require_once($CFG->libdir.'/portfoliolib.php');
    }

    $files = $fs->get_area_files($context->id, 'mod_newslettertemp', 'attachment', $post->id, "timemodified", false);
    if ($files) {
        if ($canexport) {
            $button = new portfolio_add_button();
        }
        foreach ($files as $file) {
            $filename = $file->get_filename();
            $mimetype = $file->get_mimetype();
            $iconimage = $OUTPUT->pix_icon(file_file_icon($file), get_mimetype_description($file), 'moodle', array('class' => 'icon'));
            $path = file_encode_url($CFG->wwwroot.'/pluginfile.php', '/'.$context->id.'/mod_newslettertemp/attachment/'.$post->id.'/'.$filename);

            if ($type == 'html') {
                $output .= "<a href=\"$path\">$iconimage</a> ";
                $output .= "<a href=\"$path\">".s($filename)."</a>";
                if ($canexport) {
                    $button->set_callback_options('newslettertemp_portfolio_caller', array('postid' => $post->id, 'attachment' => $file->get_id()), 'mod_newslettertemp');
                    $button->set_format_by_file($file);
                    $output .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                }
                $output .= "<br />";

            } else if ($type == 'text') {
                $output .= "$strattachment ".s($filename).":\n$path\n";

            } else { //'returnimages'
                if (in_array($mimetype, array('image/gif', 'image/jpeg', 'image/png'))) {
                    // Image attachments don't get printed as links
                    $imagereturn .= "<br /><img src=\"$path\" alt=\"\" />";
                    if ($canexport) {
                        $button->set_callback_options('newslettertemp_portfolio_caller', array('postid' => $post->id, 'attachment' => $file->get_id()), 'mod_newslettertemp');
                        $button->set_format_by_file($file);
                        $imagereturn .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                    }
                } else {
                    $output .= "<a href=\"$path\">$iconimage</a> ";
                    $output .= format_text("<a href=\"$path\">".s($filename)."</a>", FORMAT_HTML, array('context'=>$context));
                    if ($canexport) {
                        $button->set_callback_options('newslettertemp_portfolio_caller', array('postid' => $post->id, 'attachment' => $file->get_id()), 'mod_newslettertemp');
                        $button->set_format_by_file($file);
                        $output .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                    }
                    $output .= '<br />';
                }
            }

            if (!empty($CFG->enableplagiarism)) {
                require_once($CFG->libdir.'/plagiarismlib.php');
                $output .= plagiarism_get_links(array('userid' => $post->userid,
                    'file' => $file,
                    'cmid' => $cm->id,
                    'course' => $post->course,
                    'newslettertemp' => $post->newslettertemp));
                $output .= '<br />';
            }
        }
    }

    if ($type !== 'separateimages') {
        return $output;

    } else {
        return array($output, $imagereturn);
    }
}

////////////////////////////////////////////////////////////////////////////////
// File API                                                                   //
////////////////////////////////////////////////////////////////////////////////

/**
 * Lists all browsable file areas
 *
 * @package  mod_newslettertemp
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @return array
 */
function newslettertemp_get_file_areas($course, $cm, $context) {
    return array(
        'attachment' => get_string('areaattachment', 'mod_newslettertemp'),
        'post' => get_string('areapost', 'mod_newslettertemp'),
    );
}

/**
 * File browsing support for newslettertemp module.
 *
 * @package  mod_newslettertemp
 * @category files
 * @param stdClass $browser file browser object
 * @param stdClass $areas file areas
 * @param stdClass $course course object
 * @param stdClass $cm course module
 * @param stdClass $context context module
 * @param string $filearea file area
 * @param int $itemid item ID
 * @param string $filepath file path
 * @param string $filename file name
 * @return file_info instance or null if not found
 */
function newslettertemp_get_file_info($browser, $areas, $course, $cm, $context, $filearea, $itemid, $filepath, $filename) {
    global $CFG, $DB, $USER;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return null;
    }

    // filearea must contain a real area
    if (!isset($areas[$filearea])) {
        return null;
    }

    // Note that newslettertemp_user_can_see_post() additionally allows access for parent roles
    // and it explicitly checks qanda newslettertemp type, too. One day, when we stop requiring
    // course:managefiles, we will need to extend this.
    if (!has_capability('mod/newslettertemp:viewdiscussion', $context)) {
        return null;
    }

    if (is_null($itemid)) {
        require_once($CFG->dirroot.'/mod/newslettertemp/locallib.php');
        return new newslettertemp_file_info_container($browser, $course, $cm, $context, $areas, $filearea);
    }

    static $cached = array();
    // $cached will store last retrieved post, discussion and newslettertemp. To make sure that the cache
    // is cleared between unit tests we check if this is the same session
    if (!isset($cached['sesskey']) || $cached['sesskey'] != sesskey()) {
        $cached = array('sesskey' => sesskey());
    }

    if (isset($cached['post']) && $cached['post']->id == $itemid) {
        $post = $cached['post'];
    } else if ($post = $DB->get_record('newslettertemp_posts', array('id' => $itemid))) {
        $cached['post'] = $post;
    } else {
        return null;
    }

    if (isset($cached['discussion']) && $cached['discussion']->id == $post->discussion) {
        $discussion = $cached['discussion'];
    } else if ($discussion = $DB->get_record('newslettertemp_discussions', array('id' => $post->discussion))) {
        $cached['discussion'] = $discussion;
    } else {
        return null;
    }

    if (isset($cached['newslettertemp']) && $cached['newslettertemp']->id == $cm->instance) {
        $newslettertemp = $cached['newslettertemp'];
    } else if ($newslettertemp = $DB->get_record('newslettertemp', array('id' => $cm->instance))) {
        $cached['newslettertemp'] = $newslettertemp;
    } else {
        return null;
    }

    $fs = get_file_storage();
    $filepath = is_null($filepath) ? '/' : $filepath;
    $filename = is_null($filename) ? '.' : $filename;
    if (!($storedfile = $fs->get_file($context->id, 'mod_newslettertemp', $filearea, $itemid, $filepath, $filename))) {
        return null;
    }

    // Checks to see if the user can manage files or is the owner.
    // TODO MDL-33805 - Do not use userid here and move the capability check above.
    if (!has_capability('moodle/course:managefiles', $context) && $storedfile->get_userid() != $USER->id) {
        return null;
    }
    // Make sure groups allow this user to see this file
    if ($discussion->groupid > 0 && !has_capability('moodle/site:accessallgroups', $context)) {
        $groupmode = groups_get_activity_groupmode($cm, $course);
        if ($groupmode == SEPARATEGROUPS && !groups_is_member($discussion->groupid)) {
            return null;
        }
    }

    // Make sure we're allowed to see it...
    if (!newslettertemp_user_can_see_post($newslettertemp, $discussion, $post, NULL, $cm)) {
        return null;
    }

    $urlbase = $CFG->wwwroot.'/pluginfile.php';
    return new file_info_stored($browser, $context, $storedfile, $urlbase, $itemid, true, true, false, false);
}

/**
 * Serves the newslettertemp attachments. Implements needed access control ;-)
 *
 * @package  mod_newslettertemp
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */
function newslettertemp_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
    global $CFG, $DB;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_course_login($course, true, $cm);

    $areas = newslettertemp_get_file_areas($course, $cm, $context);

    // filearea must contain a real area
    if (!isset($areas[$filearea])) {
        return false;
    }

    $postid = (int)array_shift($args);

    if (!$post = $DB->get_record('newslettertemp_posts', array('id'=>$postid))) {
        return false;
    }

    if (!$discussion = $DB->get_record('newslettertemp_discussions', array('id'=>$post->discussion))) {
        return false;
    }

    if (!$newslettertemp = $DB->get_record('newslettertemp', array('id'=>$cm->instance))) {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_newslettertemp/$filearea/$postid/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }

    // Make sure groups allow this user to see this file
    if ($discussion->groupid > 0) {
        $groupmode = groups_get_activity_groupmode($cm, $course);
        if ($groupmode == SEPARATEGROUPS) {
            if (!groups_is_member($discussion->groupid) and !has_capability('moodle/site:accessallgroups', $context)) {
                return false;
            }
        }
    }

    // Make sure we're allowed to see it...
    if (!newslettertemp_user_can_see_post($newslettertemp, $discussion, $post, NULL, $cm)) {
        return false;
    }

    // finally send the file
    send_stored_file($file, 0, 0, true, $options); // download MUST be forced - security!
}

/**
 * If successful, this function returns the name of the file
 *
 * @global object
 * @param object $post is a full post record, including course and newslettertemp
 * @param object $newslettertemp
 * @param object $cm
 * @param mixed $mform
 * @param string $message
 * @return bool
 */
function newslettertemp_add_attachment($post, $newslettertemp, $cm, $mform=null, &$message=null) {
    global $DB;

    if (empty($mform)) {
        return false;
    }

    if (empty($post->attachments)) {
        return true;   // Nothing to do
    }

    $context = context_module::instance($cm->id);

    $info = file_get_draft_area_info($post->attachments);
    $present = ($info['filecount']>0) ? '1' : '';
    file_save_draft_area_files($post->attachments, $context->id, 'mod_newslettertemp', 'attachment', $post->id,
            mod_newslettertemp_post_form::attachment_options($newslettertemp));

    $DB->set_field('newslettertemp_posts', 'attachment', $present, array('id'=>$post->id));

    return true;
}

/**
 * Add a new post in an existing discussion.
 *
 * @global object
 * @global object
 * @global object
 * @param object $post
 * @param mixed $mform
 * @param string $message
 * @return int
 */
function newslettertemp_add_new_post($post, $mform, &$message) {
    global $USER, $CFG, $DB;

    $discussion = $DB->get_record('newslettertemp_discussions', array('id' => $post->discussion));
    $newslettertemp      = $DB->get_record('newslettertemp', array('id' => $discussion->newslettertemp));
    $cm         = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id);
    $context    = context_module::instance($cm->id);

    $post->created    = $post->modified = time();
    $post->mailed     = "0";
    $post->userid     = $USER->id;
    $post->attachment = "";

    $post->id = $DB->insert_record("newslettertemp_posts", $post);
    $post->message = file_save_draft_area_files($post->itemid, $context->id, 'mod_newslettertemp', 'post', $post->id,
            mod_newslettertemp_post_form::editor_options(), $post->message);
    $DB->set_field('newslettertemp_posts', 'message', $post->message, array('id'=>$post->id));
    newslettertemp_add_attachment($post, $newslettertemp, $cm, $mform, $message);

    // Update discussion modified date
    $DB->set_field("newslettertemp_discussions", "timemodified", $post->modified, array("id" => $post->discussion));
    $DB->set_field("newslettertemp_discussions", "usermodified", $post->userid, array("id" => $post->discussion));

    if (newslettertemp_tp_can_track_newslettertemps($newslettertemp) && newslettertemp_tp_is_tracked($newslettertemp)) {
        newslettertemp_tp_mark_post_read($post->userid, $post, $post->newslettertemp);
    }

    // Let Moodle know that assessable content is uploaded (eg for plagiarism detection)
    newslettertemp_trigger_content_uploaded_event($post, $cm, 'newslettertemp_add_new_post');

    return $post->id;
}

/**
 * Update a post
 *
 * @global object
 * @global object
 * @global object
 * @param object $post
 * @param mixed $mform
 * @param string $message
 * @return bool
 */
function newslettertemp_update_post($post, $mform, &$message) {
    global $USER, $CFG, $DB;

    $discussion = $DB->get_record('newslettertemp_discussions', array('id' => $post->discussion));
    $newslettertemp      = $DB->get_record('newslettertemp', array('id' => $discussion->newslettertemp));
    $cm         = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id);
    $context    = context_module::instance($cm->id);

    $post->modified = time();

    $DB->update_record('newslettertemp_posts', $post);

    $discussion->timemodified = $post->modified; // last modified tracking
    $discussion->usermodified = $post->userid;   // last modified tracking

    if (!$post->parent) {   // Post is a discussion starter - update discussion title and times too
        $discussion->name      = $post->subject;
        $discussion->timestart = $post->timestart;
        $discussion->timeend   = $post->timeend;
    }
    $post->message = file_save_draft_area_files($post->itemid, $context->id, 'mod_newslettertemp', 'post', $post->id,
            mod_newslettertemp_post_form::editor_options(), $post->message);
    $DB->set_field('newslettertemp_posts', 'message', $post->message, array('id'=>$post->id));

    $DB->update_record('newslettertemp_discussions', $discussion);

    newslettertemp_add_attachment($post, $newslettertemp, $cm, $mform, $message);

    if (newslettertemp_tp_can_track_newslettertemps($newslettertemp) && newslettertemp_tp_is_tracked($newslettertemp)) {
        newslettertemp_tp_mark_post_read($post->userid, $post, $post->newslettertemp);
    }

    // Let Moodle know that assessable content is uploaded (eg for plagiarism detection)
    newslettertemp_trigger_content_uploaded_event($post, $cm, 'newslettertemp_update_post');

    return true;
}

/**
 * Given an object containing all the necessary data,
 * create a new discussion and return the id
 *
 * @global object
 * @global object
 * @global object
 * @param object $post
 * @param mixed $mform
 * @param string $message
 * @param int $userid
 * @return object
 */
function newslettertemp_add_discussion($discussion, $mform=null, &$message=null, $userid=null) {
    global $USER, $CFG, $DB;

    $timenow = time();

    if (is_null($userid)) {
        $userid = $USER->id;
    }

    // The first post is stored as a real post, and linked
    // to from the discuss entry.

    $newslettertemp = $DB->get_record('newslettertemp', array('id'=>$discussion->newslettertemp));
    $cm    = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id);

    $post = new stdClass();
    $post->discussion    = 0;
    $post->parent        = 0;
    $post->userid        = $userid;
    $post->created       = $timenow;
    $post->modified      = $timenow;
    $post->mailed        = 0;
    $post->subject       = $discussion->name;
    $post->message       = $discussion->message;
    $post->messageformat = $discussion->messageformat;
    $post->messagetrust  = $discussion->messagetrust;
    $post->attachments   = isset($discussion->attachments) ? $discussion->attachments : null;
    $post->newslettertemp         = $newslettertemp->id;     // speedup
    $post->course        = $newslettertemp->course; // speedup
    $post->mailnow       = $discussion->mailnow;

    $post->id = $DB->insert_record("newslettertemp_posts", $post);

    // TODO: Fix the calling code so that there always is a $cm when this function is called
    if (!empty($cm->id) && !empty($discussion->itemid)) {   // In "single simple discussions" this may not exist yet
        $context = context_module::instance($cm->id);
        $text = file_save_draft_area_files($discussion->itemid, $context->id, 'mod_newslettertemp', 'post', $post->id,
                mod_newslettertemp_post_form::editor_options(), $post->message);
        $DB->set_field('newslettertemp_posts', 'message', $text, array('id'=>$post->id));
    }

    // Now do the main entry for the discussion, linking to this first post

    $discussion->firstpost    = $post->id;
    $discussion->timemodified = $timenow;
    $discussion->usermodified = $post->userid;
    $discussion->userid       = $userid;

    $post->discussion = $DB->insert_record("newslettertemp_discussions", $discussion);

    // Finally, set the pointer on the post.
    $DB->set_field("newslettertemp_posts", "discussion", $post->discussion, array("id"=>$post->id));

    if (!empty($cm->id)) {
        newslettertemp_add_attachment($post, $newslettertemp, $cm, $mform, $message);
    }

    if (newslettertemp_tp_can_track_newslettertemps($newslettertemp) && newslettertemp_tp_is_tracked($newslettertemp)) {
        newslettertemp_tp_mark_post_read($post->userid, $post, $post->newslettertemp);
    }

    // Let Moodle know that assessable content is uploaded (eg for plagiarism detection)
    if (!empty($cm->id)) {
        newslettertemp_trigger_content_uploaded_event($post, $cm, 'newslettertemp_add_discussion');
    }

    return $post->discussion;
}


/**
 * Deletes a discussion and handles all associated cleanup.
 *
 * @global object
 * @param object $discussion Discussion to delete
 * @param bool $fulldelete True when deleting entire newslettertemp
 * @param object $course Course
 * @param object $cm Course-module
 * @param object $newslettertemp Forum
 * @return bool
 */
function newslettertemp_delete_discussion($discussion, $fulldelete, $course, $cm, $newslettertemp) {
    global $DB, $CFG;
    require_once($CFG->libdir.'/completionlib.php');

    $result = true;

    if ($posts = $DB->get_records("newslettertemp_posts", array("discussion" => $discussion->id))) {
        foreach ($posts as $post) {
            $post->course = $discussion->course;
            $post->newslettertemp  = $discussion->newslettertemp;
            if (!newslettertemp_delete_post($post, 'ignore', $course, $cm, $newslettertemp, $fulldelete)) {
                $result = false;
            }
        }
    }

    newslettertemp_tp_delete_read_records(-1, -1, $discussion->id);

    if (!$DB->delete_records("newslettertemp_discussions", array("id"=>$discussion->id))) {
        $result = false;
    }

    // Update completion state if we are tracking completion based on number of posts
    // But don't bother when deleting whole thing
    if (!$fulldelete) {
        $completion = new completion_info($course);
        if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC &&
           ($newslettertemp->completiondiscussions || $newslettertemp->completionreplies || $newslettertemp->completionposts)) {
            $completion->update_state($cm, COMPLETION_INCOMPLETE, $discussion->userid);
        }
    }

    return $result;
}


/**
 * Deletes a single newslettertemp post.
 *
 * @global object
 * @param object $post Forum post object
 * @param mixed $children Whether to delete children. If false, returns false
 *   if there are any children (without deleting the post). If true,
 *   recursively deletes all children. If set to special value 'ignore', deletes
 *   post regardless of children (this is for use only when deleting all posts
 *   in a disussion).
 * @param object $course Course
 * @param object $cm Course-module
 * @param object $newslettertemp Forum
 * @param bool $skipcompletion True to skip updating completion state if it
 *   would otherwise be updated, i.e. when deleting entire newslettertemp anyway.
 * @return bool
 */
function newslettertemp_delete_post($post, $children, $course, $cm, $newslettertemp, $skipcompletion=false) {
    global $DB, $CFG;
    require_once($CFG->libdir.'/completionlib.php');

    $context = context_module::instance($cm->id);

    if ($children !== 'ignore' && ($childposts = $DB->get_records('newslettertemp_posts', array('parent'=>$post->id)))) {
       if ($children) {
           foreach ($childposts as $childpost) {
               newslettertemp_delete_post($childpost, true, $course, $cm, $newslettertemp, $skipcompletion);
           }
       } else {
           return false;
       }
    }

    //delete ratings
    require_once($CFG->dirroot.'/rating/lib.php');
    $delopt = new stdClass;
    $delopt->contextid = $context->id;
    $delopt->component = 'mod_newslettertemp';
    $delopt->ratingarea = 'post';
    $delopt->itemid = $post->id;
    $rm = new rating_manager();
    $rm->delete_ratings($delopt);

    //delete attachments
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'mod_newslettertemp', 'attachment', $post->id);
    $fs->delete_area_files($context->id, 'mod_newslettertemp', 'post', $post->id);

    if ($DB->delete_records("newslettertemp_posts", array("id" => $post->id))) {

        newslettertemp_tp_delete_read_records(-1, $post->id);

    // Just in case we are deleting the last post
        newslettertemp_discussion_update_last_post($post->discussion);

        // Update completion state if we are tracking completion based on number of posts
        // But don't bother when deleting whole thing

        if (!$skipcompletion) {
            $completion = new completion_info($course);
            if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC &&
               ($newslettertemp->completiondiscussions || $newslettertemp->completionreplies || $newslettertemp->completionposts)) {
                $completion->update_state($cm, COMPLETION_INCOMPLETE, $post->userid);
            }
        }

        return true;
    }
    return false;
}

/**
 * Sends post content to plagiarism plugin
 * @param object $post Forum post object
 * @param object $cm Course-module
 * @param string $name
 * @return bool
*/
function newslettertemp_trigger_content_uploaded_event($post, $cm, $name) {
    $context = context_module::instance($cm->id);
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_newslettertemp', 'attachment', $post->id, "timemodified", false);
    $eventdata = new stdClass();
    $eventdata->modulename   = 'newslettertemp';
    $eventdata->name         = $name;
    $eventdata->cmid         = $cm->id;
    $eventdata->itemid       = $post->id;
    $eventdata->courseid     = $post->course;
    $eventdata->userid       = $post->userid;
    $eventdata->content      = $post->message;
    if ($files) {
        $eventdata->pathnamehashes = array_keys($files);
    }
    events_trigger('assessable_content_uploaded', $eventdata);

    return true;
}

/**
 * @global object
 * @param object $post
 * @param bool $children
 * @return int
 */
function newslettertemp_count_replies($post, $children=true) {
    global $DB;
    $count = 0;

    if ($children) {
        if ($childposts = $DB->get_records('newslettertemp_posts', array('parent' => $post->id))) {
           foreach ($childposts as $childpost) {
               $count ++;                   // For this child
               $count += newslettertemp_count_replies($childpost, true);
           }
        }
    } else {
        $count += $DB->count_records('newslettertemp_posts', array('parent' => $post->id));
    }

    return $count;
}


/**
 * @global object
 * @param int $newslettertempid
 * @param mixed $value
 * @return bool
 */
function newslettertemp_forcesubscribe($newslettertempid, $value=1) {
    global $DB;
    return $DB->set_field("newslettertemp", "forcesubscribe", $value, array("id" => $newslettertempid));
}

/**
 * @global object
 * @param object $newslettertemp
 * @return bool
 */
function newslettertemp_is_forcesubscribed($newslettertemp) {
    global $DB;
    if (isset($newslettertemp->forcesubscribe)) {    // then we use that
        return ($newslettertemp->forcesubscribe == NEWSLETTERTEMP_FORCESUBSCRIBE);
    } else {   // Check the database
       return ($DB->get_field('newslettertemp', 'forcesubscribe', array('id' => $newslettertemp)) == NEWSLETTERTEMP_FORCESUBSCRIBE);
    }
}

function newslettertemp_get_forcesubscribed($newslettertemp) {
    global $DB;
    if (isset($newslettertemp->forcesubscribe)) {    // then we use that
        return $newslettertemp->forcesubscribe;
    } else {   // Check the database
        return $DB->get_field('newslettertemp', 'forcesubscribe', array('id' => $newslettertemp));
    }
}

/**
 * @global object
 * @param int $userid
 * @param object $newslettertemp
 * @return bool
 */
function newslettertemp_is_subscribed($userid, $newslettertemp) {
    global $DB;
    if (is_numeric($newslettertemp)) {
        $newslettertemp = $DB->get_record('newslettertemp', array('id' => $newslettertemp));
    }
    // If newslettertemp is force subscribed and has allowforcesubscribe, then user is subscribed.
    $cm = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id);
    if (newslettertemp_is_forcesubscribed($newslettertemp) && $cm &&
            has_capability('mod/newslettertemp:allowforcesubscribe', context_module::instance($cm->id), $userid)) {
        return true;
    }
    return $DB->record_exists("newslettertemp_subscriptions", array("userid" => $userid, "newslettertemp" => $newslettertemp->id));
}

function newslettertemp_get_subscribed_newslettertemps($course) {
    global $USER, $CFG, $DB;
    $sql = "SELECT f.id
              FROM {newslettertemp} f
                   LEFT JOIN {newslettertemp_subscriptions} fs ON (fs.newslettertemp = f.id AND fs.userid = ?)
             WHERE f.course = ?
                   AND f.forcesubscribe <> ".NEWSLETTERTEMP_DISALLOWSUBSCRIBE."
                   AND (f.forcesubscribe = ".NEWSLETTERTEMP_FORCESUBSCRIBE." OR fs.id IS NOT NULL)";
    if ($subscribed = $DB->get_records_sql($sql, array($USER->id, $course->id))) {
        foreach ($subscribed as $s) {
            $subscribed[$s->id] = $s->id;
        }
        return $subscribed;
    } else {
        return array();
    }
}

/**
 * Returns an array of newslettertemps that the current user is subscribed to and is allowed to unsubscribe from
 *
 * @return array An array of unsubscribable newslettertemps
 */
function newslettertemp_get_optional_subscribed_newslettertemps() {
    global $USER, $DB;

    // Get courses that $USER is enrolled in and can see
    $courses = enrol_get_my_courses();
    if (empty($courses)) {
        return array();
    }

    $courseids = array();
    foreach($courses as $course) {
        $courseids[] = $course->id;
    }
    list($coursesql, $courseparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');

    // get all newslettertemps from the user's courses that they are subscribed to and which are not set to forced
    $sql = "SELECT f.id, cm.id as cm, cm.visible
              FROM {newslettertemp} f
                   JOIN {course_modules} cm ON cm.instance = f.id
                   JOIN {modules} m ON m.name = :modulename AND m.id = cm.module
                   LEFT JOIN {newslettertemp_subscriptions} fs ON (fs.newslettertemp = f.id AND fs.userid = :userid)
             WHERE f.forcesubscribe <> :forcesubscribe AND fs.id IS NOT NULL
                   AND cm.course $coursesql";
    $params = array_merge($courseparams, array('modulename'=>'newslettertemp', 'userid'=>$USER->id, 'forcesubscribe'=>NEWSLETTERTEMP_FORCESUBSCRIBE));
    if (!$newslettertemps = $DB->get_records_sql($sql, $params)) {
        return array();
    }

    $unsubscribablenewslettertemps = array(); // Array to return

    foreach($newslettertemps as $newslettertemp) {

        if (empty($newslettertemp->visible)) {
            // the newslettertemp is hidden
            $context = context_module::instance($newslettertemp->cm);
            if (!has_capability('moodle/course:viewhiddenactivities', $context)) {
                // the user can't see the hidden newslettertemp
                continue;
            }
        }

        // subscribe.php only requires 'mod/newslettertemp:managesubscriptions' when
        // unsubscribing a user other than yourself so we don't require it here either

        // A check for whether the newslettertemp has subscription set to forced is built into the SQL above

        $unsubscribablenewslettertemps[] = $newslettertemp;
    }

    return $unsubscribablenewslettertemps;
}

/**
 * Adds user to the subscriber list
 *
 * @global object
 * @param int $userid
 * @param int $newslettertempid
 */
function newslettertemp_subscribe($userid, $newslettertempid) {
    global $DB;

    if ($DB->record_exists("newslettertemp_subscriptions", array("userid"=>$userid, "newslettertemp"=>$newslettertempid))) {
        return true;
    }

    $sub = new stdClass();
    $sub->userid  = $userid;
    $sub->newslettertemp = $newslettertempid;

    return $DB->insert_record("newslettertemp_subscriptions", $sub);
}

/**
 * Removes user from the subscriber list
 *
 * @global object
 * @param int $userid
 * @param int $newslettertempid
 */
function newslettertemp_unsubscribe($userid, $newslettertempid) {
    global $DB;
    return $DB->delete_records("newslettertemp_subscriptions", array("userid"=>$userid, "newslettertemp"=>$newslettertempid));
}

/**
 * Given a new post, subscribes or unsubscribes as appropriate.
 * Returns some text which describes what happened.
 *
 * @global objec
 * @param object $post
 * @param object $newslettertemp
 */
function newslettertemp_post_subscription($post, $newslettertemp) {

    global $USER;

    $action = '';
    $subscribed = newslettertemp_is_subscribed($USER->id, $newslettertemp);

    if ($newslettertemp->forcesubscribe == NEWSLETTERTEMP_FORCESUBSCRIBE) { // database ignored
        return "";

    } elseif (($newslettertemp->forcesubscribe == NEWSLETTERTEMP_DISALLOWSUBSCRIBE)
        && !has_capability('moodle/course:manageactivities', context_course::instance($newslettertemp->course), $USER->id)) {
        if ($subscribed) {
            $action = 'unsubscribe'; // sanity check, following MDL-14558
        } else {
            return "";
        }

    } else { // go with the user's choice
        if (isset($post->subscribe)) {
            // no change
            if ((!empty($post->subscribe) && $subscribed)
                || (empty($post->subscribe) && !$subscribed)) {
                return "";

            } elseif (!empty($post->subscribe) && !$subscribed) {
                $action = 'subscribe';

            } elseif (empty($post->subscribe) && $subscribed) {
                $action = 'unsubscribe';
            }
        }
    }

    $info = new stdClass();
    $info->name  = fullname($USER);
    $info->newslettertemp = format_string($newslettertemp->name);

    switch ($action) {
        case 'subscribe':
            newslettertemp_subscribe($USER->id, $post->newslettertemp);
            return "<p>".get_string("nowsubscribed", "newslettertemp", $info)."</p>";
        case 'unsubscribe':
            newslettertemp_unsubscribe($USER->id, $post->newslettertemp);
            return "<p>".get_string("nownotsubscribed", "newslettertemp", $info)."</p>";
    }
}

/**
 * Generate and return the subscribe or unsubscribe link for a newslettertemp.
 *
 * @param object $newslettertemp the newslettertemp. Fields used are $newslettertemp->id and $newslettertemp->forcesubscribe.
 * @param object $context the context object for this newslettertemp.
 * @param array $messages text used for the link in its various states
 *      (subscribed, unsubscribed, forcesubscribed or cantsubscribe).
 *      Any strings not passed in are taken from the $defaultmessages array
 *      at the top of the function.
 * @param bool $cantaccessagroup
 * @param bool $fakelink
 * @param bool $backtoindex
 * @param array $subscribed_newslettertemps
 * @return string
 */
function newslettertemp_get_subscribe_link($newslettertemp, $context, $messages = array(), $cantaccessagroup = false, $fakelink=true, $backtoindex=false, $subscribed_newslettertemps=null) {
    global $CFG, $USER, $PAGE, $OUTPUT;
    $defaultmessages = array(
        'subscribed' => get_string('unsubscribe', 'newslettertemp'),
        'unsubscribed' => get_string('subscribe', 'newslettertemp'),
        'cantaccessgroup' => get_string('no'),
        'forcesubscribed' => get_string('everyoneissubscribed', 'newslettertemp'),
        'cantsubscribe' => get_string('disallowsubscribe','newslettertemp')
    );
    $messages = $messages + $defaultmessages;

    if (newslettertemp_is_forcesubscribed($newslettertemp)) {
        return $messages['forcesubscribed'];
    } else if ($newslettertemp->forcesubscribe == NEWSLETTERTEMP_DISALLOWSUBSCRIBE && !has_capability('mod/newslettertemp:managesubscriptions', $context)) {
        return $messages['cantsubscribe'];
    } else if ($cantaccessagroup) {
        return $messages['cantaccessgroup'];
    } else {
        if (!is_enrolled($context, $USER, '', true)) {
            return '';
        }
        if (is_null($subscribed_newslettertemps)) {
            $subscribed = newslettertemp_is_subscribed($USER->id, $newslettertemp);
        } else {
            $subscribed = !empty($subscribed_newslettertemps[$newslettertemp->id]);
        }
        if ($subscribed) {
            $linktext = $messages['subscribed'];
            $linktitle = get_string('subscribestop', 'newslettertemp');
        } else {
            $linktext = $messages['unsubscribed'];
            $linktitle = get_string('subscribestart', 'newslettertemp');
        }

        $options = array();
        if ($backtoindex) {
            $backtoindexlink = '&amp;backtoindex=1';
            $options['backtoindex'] = 1;
        } else {
            $backtoindexlink = '';
        }
        $link = '';

        if ($fakelink) {
            $PAGE->requires->js('/mod/newslettertemp/newslettertemp.js');
            $PAGE->requires->js_function_call('newslettertemp_produce_subscribe_link', array($newslettertemp->id, $backtoindexlink, $linktext, $linktitle));
            $link = "<noscript>";
        }
        $options['id'] = $newslettertemp->id;
        $options['sesskey'] = sesskey();
        $url = new moodle_url('/mod/newslettertemp/subscribe.php', $options);
        $link .= $OUTPUT->single_button($url, $linktext, 'get', array('title'=>$linktitle));
        if ($fakelink) {
            $link .= '</noscript>';
        }

        return $link;
    }
}


/**
 * Generate and return the track or no track link for a newslettertemp.
 *
 * @global object
 * @global object
 * @global object
 * @param object $newslettertemp the newslettertemp. Fields used are $newslettertemp->id and $newslettertemp->forcesubscribe.
 * @param array $messages
 * @param bool $fakelink
 * @return string
 */
function newslettertemp_get_tracking_link($newslettertemp, $messages=array(), $fakelink=true) {
    global $CFG, $USER, $PAGE, $OUTPUT;

    static $strnotracknewslettertemp, $strtracknewslettertemp;

    if (isset($messages['tracknewslettertemp'])) {
         $strtracknewslettertemp = $messages['tracknewslettertemp'];
    }
    if (isset($messages['notracknewslettertemp'])) {
         $strnotracknewslettertemp = $messages['notracknewslettertemp'];
    }
    if (empty($strtracknewslettertemp)) {
        $strtracknewslettertemp = get_string('tracknewslettertemp', 'newslettertemp');
    }
    if (empty($strnotracknewslettertemp)) {
        $strnotracknewslettertemp = get_string('notracknewslettertemp', 'newslettertemp');
    }

    if (newslettertemp_tp_is_tracked($newslettertemp)) {
        $linktitle = $strnotracknewslettertemp;
        $linktext = $strnotracknewslettertemp;
    } else {
        $linktitle = $strtracknewslettertemp;
        $linktext = $strtracknewslettertemp;
    }

    $link = '';
    if ($fakelink) {
        $PAGE->requires->js('/mod/newslettertemp/newslettertemp.js');
        $PAGE->requires->js_function_call('newslettertemp_produce_tracking_link', Array($newslettertemp->id, $linktext, $linktitle));
        // use <noscript> to print button in case javascript is not enabled
        $link .= '<noscript>';
    }
    $url = new moodle_url('/mod/newslettertemp/settracking.php', array('id'=>$newslettertemp->id));
    $link .= $OUTPUT->single_button($url, $linktext, 'get', array('title'=>$linktitle));

    if ($fakelink) {
        $link .= '</noscript>';
    }

    return $link;
}



/**
 * Returns true if user created new discussion already
 *
 * @global object
 * @global object
 * @param int $newslettertempid
 * @param int $userid
 * @return bool
 */
function newslettertemp_user_has_posted_discussion($newslettertempid, $userid) {
    global $CFG, $DB;

    $sql = "SELECT 'x'
              FROM {newslettertemp_discussions} d, {newslettertemp_posts} p
             WHERE d.newslettertemp = ? AND p.discussion = d.id AND p.parent = 0 and p.userid = ?";

    return $DB->record_exists_sql($sql, array($newslettertempid, $userid));
}

/**
 * @global object
 * @global object
 * @param int $newslettertempid
 * @param int $userid
 * @return array
 */
function newslettertemp_discussions_user_has_posted_in($newslettertempid, $userid) {
    global $CFG, $DB;

    $haspostedsql = "SELECT d.id AS id,
                            d.*
                       FROM {newslettertemp_posts} p,
                            {newslettertemp_discussions} d
                      WHERE p.discussion = d.id
                        AND d.newslettertemp = ?
                        AND p.userid = ?";

    return $DB->get_records_sql($haspostedsql, array($newslettertempid, $userid));
}

/**
 * @global object
 * @global object
 * @param int $newslettertempid
 * @param int $did
 * @param int $userid
 * @return bool
 */
function newslettertemp_user_has_posted($newslettertempid, $did, $userid) {
    global $DB;

    if (empty($did)) {
        // posted in any newslettertemp discussion?
        $sql = "SELECT 'x'
                  FROM {newslettertemp_posts} p
                  JOIN {newslettertemp_discussions} d ON d.id = p.discussion
                 WHERE p.userid = :userid AND d.newslettertemp = :newslettertempid";
        return $DB->record_exists_sql($sql, array('newslettertempid'=>$newslettertempid,'userid'=>$userid));
    } else {
        return $DB->record_exists('newslettertemp_posts', array('discussion'=>$did,'userid'=>$userid));
    }
}

/**
 * Returns creation time of the first user's post in given discussion
 * @global object $DB
 * @param int $did Discussion id
 * @param int $userid User id
 * @return int|bool post creation time stamp or return false
 */
function newslettertemp_get_user_posted_time($did, $userid) {
    global $DB;

    $posttime = $DB->get_field('newslettertemp_posts', 'MIN(created)', array('userid'=>$userid, 'discussion'=>$did));
    if (empty($posttime)) {
        return false;
    }
    return $posttime;
}

/**
 * @global object
 * @param object $newslettertemp
 * @param object $currentgroup
 * @param int $unused
 * @param object $cm
 * @param object $context
 * @return bool
 */
function newslettertemp_user_can_post_discussion($newslettertemp, $currentgroup=null, $unused=-1, $cm=NULL, $context=NULL) {
// $newslettertemp is an object
    global $USER;

    // shortcut - guest and not-logged-in users can not post
    if (isguestuser() or !isloggedin()) {
        return false;
    }

    if (!$cm) {
        debugging('missing cm', DEBUG_DEVELOPER);
        if (!$cm = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id, $newslettertemp->course)) {
            print_error('invalidcoursemodule');
        }
    }

    if (!$context) {
        $context = context_module::instance($cm->id);
    }

    if ($currentgroup === null) {
        $currentgroup = groups_get_activity_group($cm);
    }

    $groupmode = groups_get_activity_groupmode($cm);

    if ($newslettertemp->type == 'news') {
        $capname = 'mod/newslettertemp:addnews';
    } else if ($newslettertemp->type == 'qanda') {
        $capname = 'mod/newslettertemp:addquestion';
    } else {
        $capname = 'mod/newslettertemp:startdiscussion';
    }

    if (!has_capability($capname, $context)) {
        return false;
    }

    if ($newslettertemp->type == 'single') {
        return false;
    }

    if ($newslettertemp->type == 'eachuser') {
        if (newslettertemp_user_has_posted_discussion($newslettertemp->id, $USER->id)) {
            return false;
        }
    }

    if (!$groupmode or has_capability('moodle/site:accessallgroups', $context)) {
        return true;
    }

    if ($currentgroup) {
        return groups_is_member($currentgroup);
    } else {
        // no group membership and no accessallgroups means no new discussions
        // reverted to 1.7 behaviour in 1.9+,  buggy in 1.8.0-1.9.0
        return false;
    }
}

/**
 * This function checks whether the user can reply to posts in a newslettertemp
 * discussion. Use newslettertemp_user_can_post_discussion() to check whether the user
 * can start discussions.
 *
 * @global object
 * @global object
 * @uses DEBUG_DEVELOPER
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $newslettertemp newslettertemp object
 * @param object $discussion
 * @param object $user
 * @param object $cm
 * @param object $course
 * @param object $context
 * @return bool
 */
function newslettertemp_user_can_post($newslettertemp, $discussion, $user=NULL, $cm=NULL, $course=NULL, $context=NULL) {
    global $USER, $DB;
    if (empty($user)) {
        $user = $USER;
    }

    // shortcut - guest and not-logged-in users can not post
    if (isguestuser($user) or empty($user->id)) {
        return false;
    }

    if (!isset($discussion->groupid)) {
        debugging('incorrect discussion parameter', DEBUG_DEVELOPER);
        return false;
    }

    if (!$cm) {
        debugging('missing cm', DEBUG_DEVELOPER);
        if (!$cm = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id, $newslettertemp->course)) {
            print_error('invalidcoursemodule');
        }
    }

    if (!$course) {
        debugging('missing course', DEBUG_DEVELOPER);
        if (!$course = $DB->get_record('course', array('id' => $newslettertemp->course))) {
            print_error('invalidcourseid');
        }
    }

    if (!$context) {
        $context = context_module::instance($cm->id);
    }

    // normal users with temporary guest access can not post, suspended users can not post either
    if (!is_viewing($context, $user->id) and !is_enrolled($context, $user->id, '', true)) {
        return false;
    }

    if ($newslettertemp->type == 'news') {
        $capname = 'mod/newslettertemp:replynews';
    } else {
        $capname = 'mod/newslettertemp:replypost';
    }

    if (!has_capability($capname, $context, $user->id)) {
        return false;
    }

    if (!$groupmode = groups_get_activity_groupmode($cm, $course)) {
        return true;
    }

    if (has_capability('moodle/site:accessallgroups', $context)) {
        return true;
    }

    if ($groupmode == VISIBLEGROUPS) {
        if ($discussion->groupid == -1) {
            // allow students to reply to all participants discussions - this was not possible in Moodle <1.8
            return true;
        }
        return groups_is_member($discussion->groupid);

    } else {
        //separate groups
        if ($discussion->groupid == -1) {
            return false;
        }
        return groups_is_member($discussion->groupid);
    }
}

/**
 * Checks to see if a user can view a particular post.
 *
 * @deprecated since Moodle 2.4 use newslettertemp_user_can_see_post() instead
 *
 * @param object $post
 * @param object $course
 * @param object $cm
 * @param object $newslettertemp
 * @param object $discussion
 * @param object $user
 * @return boolean
 */
function newslettertemp_user_can_view_post($post, $course, $cm, $newslettertemp, $discussion, $user=null){
    debugging('newslettertemp_user_can_view_post() is deprecated. Please use newslettertemp_user_can_see_post() instead.', DEBUG_DEVELOPER);
    return newslettertemp_user_can_see_post($newslettertemp, $discussion, $post, $user, $cm);
}

/**
* Check to ensure a user can view a timed discussion.
*
* @param object $discussion
* @param object $user
* @param object $context
* @return boolean returns true if they can view post, false otherwise
*/
function newslettertemp_user_can_see_timed_discussion($discussion, $user, $context) {
    global $CFG;

    // Check that the user can view a discussion that is normally hidden due to access times.
    if (!empty($CFG->newslettertemp_enabletimedposts)) {
        $time = time();
        if (($discussion->timestart != 0 && $discussion->timestart > $time)
            || ($discussion->timeend != 0 && $discussion->timeend < $time)) {
            if (!has_capability('mod/newslettertemp:viewhiddentimedposts', $context, $user->id)) {
                return false;
            }
        }
    }

    return true;
}

/**
* Check to ensure a user can view a group discussion.
*
* @param object $discussion
* @param object $cm
* @param object $context
* @return boolean returns true if they can view post, false otherwise
*/
function newslettertemp_user_can_see_group_discussion($discussion, $cm, $context) {

    // If it's a grouped discussion, make sure the user is a member.
    if ($discussion->groupid > 0) {
        $groupmode = groups_get_activity_groupmode($cm);
        if ($groupmode == SEPARATEGROUPS) {
            return groups_is_member($discussion->groupid) || has_capability('moodle/site:accessallgroups', $context);
        }
    }

    return true;
}

/**
 * @global object
 * @global object
 * @uses DEBUG_DEVELOPER
 * @param object $newslettertemp
 * @param object $discussion
 * @param object $context
 * @param object $user
 * @return bool
 */
function newslettertemp_user_can_see_discussion($newslettertemp, $discussion, $context, $user=NULL) {
    global $USER, $DB;

    if (empty($user) || empty($user->id)) {
        $user = $USER;
    }

    // retrieve objects (yuk)
    if (is_numeric($newslettertemp)) {
        debugging('missing full newslettertemp', DEBUG_DEVELOPER);
        if (!$newslettertemp = $DB->get_record('newslettertemp',array('id'=>$newslettertemp))) {
            return false;
        }
    }
    if (is_numeric($discussion)) {
        debugging('missing full discussion', DEBUG_DEVELOPER);
        if (!$discussion = $DB->get_record('newslettertemp_discussions',array('id'=>$discussion))) {
            return false;
        }
    }
    if (!$cm = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id, $newslettertemp->course)) {
        print_error('invalidcoursemodule');
    }

    if (!has_capability('mod/newslettertemp:viewdiscussion', $context)) {
        return false;
    }

    if (!newslettertemp_user_can_see_timed_discussion($discussion, $user, $context)) {
        return false;
    }

    if (!newslettertemp_user_can_see_group_discussion($discussion, $cm, $context)) {
        return false;
    }

    if ($newslettertemp->type == 'qanda' &&
            !newslettertemp_user_has_posted($newslettertemp->id, $discussion->id, $user->id) &&
            !has_capability('mod/newslettertemp:viewqandawithoutposting', $context)) {
        return false;
    }
    return true;
}

/**
 * @global object
 * @global object
 * @param object $newslettertemp
 * @param object $discussion
 * @param object $post
 * @param object $user
 * @param object $cm
 * @return bool
 */
function newslettertemp_user_can_see_post($newslettertemp, $discussion, $post, $user=NULL, $cm=NULL) {
    global $CFG, $USER, $DB;

    // Context used throughout function.
    $modcontext = context_module::instance($cm->id);

    // retrieve objects (yuk)
    if (is_numeric($newslettertemp)) {
        debugging('missing full newslettertemp', DEBUG_DEVELOPER);
        if (!$newslettertemp = $DB->get_record('newslettertemp',array('id'=>$newslettertemp))) {
            return false;
        }
    }

    if (is_numeric($discussion)) {
        debugging('missing full discussion', DEBUG_DEVELOPER);
        if (!$discussion = $DB->get_record('newslettertemp_discussions',array('id'=>$discussion))) {
            return false;
        }
    }
    if (is_numeric($post)) {
        debugging('missing full post', DEBUG_DEVELOPER);
        if (!$post = $DB->get_record('newslettertemp_posts',array('id'=>$post))) {
            return false;
        }
    }

    if (!isset($post->id) && isset($post->parent)) {
        $post->id = $post->parent;
    }

    if (!$cm) {
        debugging('missing cm', DEBUG_DEVELOPER);
        if (!$cm = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id, $newslettertemp->course)) {
            print_error('invalidcoursemodule');
        }
    }

    if (empty($user) || empty($user->id)) {
        $user = $USER;
    }

    $canviewdiscussion = !empty($cm->cache->caps['mod/newslettertemp:viewdiscussion']) || has_capability('mod/newslettertemp:viewdiscussion', $modcontext, $user->id);
    if (!$canviewdiscussion && !has_all_capabilities(array('moodle/user:viewdetails', 'moodle/user:readuserposts'), context_user::instance($post->userid))) {
        return false;
    }

    if (isset($cm->uservisible)) {
        if (!$cm->uservisible) {
            return false;
        }
    } else {
        if (!coursemodule_visible_for_user($cm, $user->id)) {
            return false;
        }
    }

    if (!newslettertemp_user_can_see_timed_discussion($discussion, $user, $modcontext)) {
        return false;
    }

    if (!newslettertemp_user_can_see_group_discussion($discussion, $cm, $modcontext)) {
        return false;
    }

    if ($newslettertemp->type == 'qanda') {
        $firstpost = newslettertemp_get_firstpost_from_discussion($discussion->id);
        $userfirstpost = newslettertemp_get_user_posted_time($discussion->id, $user->id);

        return (($userfirstpost !== false && (time() - $userfirstpost >= $CFG->maxeditingtime)) ||
                $firstpost->id == $post->id || $post->userid == $user->id || $firstpost->userid == $user->id ||
                has_capability('mod/newslettertemp:viewqandawithoutposting', $modcontext, $user->id));
    }
    return true;
}


/**
 * Prints the discussion view screen for a newslettertemp.
 *
 * @global object
 * @global object
 * @param object $course The current course object.
 * @param object $newslettertemp Forum to be printed.
 * @param int $maxdiscussions .
 * @param string $displayformat The display format to use (optional).
 * @param string $sort Sort arguments for database query (optional).
 * @param int $groupmode Group mode of the newslettertemp (optional).
 * @param void $unused (originally current group)
 * @param int $page Page mode, page to display (optional).
 * @param int $perpage The maximum number of discussions per page(optional)
 *
 */
function newslettertemp_print_latest_discussions($course, $newslettertemp, $maxdiscussions=-1, $displayformat='plain', $sort='',
                                        $currentgroup=-1, $groupmode=-1, $page=-1, $perpage=100, $cm=NULL) {
    global $CFG, $USER, $OUTPUT;

    if (!$cm) {
        if (!$cm = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id, $newslettertemp->course)) {
            print_error('invalidcoursemodule');
        }
    }
    $context = context_module::instance($cm->id);

    if (empty($sort)) {
        $sort = "d.timemodified DESC";
    }

    $olddiscussionlink = false;

 // Sort out some defaults
    if ($perpage <= 0) {
        $perpage = 0;
        $page    = -1;
    }

    if ($maxdiscussions == 0) {
        // all discussions - backwards compatibility
        $page    = -1;
        $perpage = 0;
        if ($displayformat == 'plain') {
            $displayformat = 'header';  // Abbreviate display by default
        }

    } else if ($maxdiscussions > 0) {
        $page    = -1;
        $perpage = $maxdiscussions;
    }

    $fullpost = false;
    if ($displayformat == 'plain') {
        $fullpost = true;
    }


// Decide if current user is allowed to see ALL the current discussions or not

// First check the group stuff
    if ($currentgroup == -1 or $groupmode == -1) {
        $groupmode    = groups_get_activity_groupmode($cm, $course);
        $currentgroup = groups_get_activity_group($cm);
    }

    $groups = array(); //cache

// If the user can post discussions, then this is a good place to put the
// button for it. We do not show the button if we are showing site news
// and the current user is a guest.

    $canstart = newslettertemp_user_can_post_discussion($newslettertemp, $currentgroup, $groupmode, $cm, $context);
    if (!$canstart and $newslettertemp->type !== 'news') {
        if (isguestuser() or !isloggedin()) {
            $canstart = true;
        }
        if (!is_enrolled($context) and !is_viewing($context)) {
            // allow guests and not-logged-in to see the button - they are prompted to log in after clicking the link
            // normal users with temporary guest access see this button too, they are asked to enrol instead
            // do not show the button to users with suspended enrolments here
            $canstart = enrol_selfenrol_available($course->id);
        }
    }

    if ($canstart) {
        echo '<div class="singlebutton newslettertempaddnew">';
        echo "<form id=\"newdiscussionform\" method=\"get\" action=\"$CFG->wwwroot/mod/newslettertemp/post.php\">";
        echo '<div>';
        echo "<input type=\"hidden\" name=\"newslettertemp\" value=\"$newslettertemp->id\" />";
        switch ($newslettertemp->type) {
            case 'news':
            case 'blog':
                $buttonadd = get_string('addanewtopic', 'newslettertemp');
                break;
            case 'qanda':
                $buttonadd = get_string('addanewquestion', 'newslettertemp');
                break;
            default:
                $buttonadd = get_string('addanewdiscussion', 'newslettertemp');
                break;
        }
        echo '<input type="submit" value="'.$buttonadd.'" />';
        echo '</div>';
        echo '</form>';
        echo "</div>\n";

    } else if (isguestuser() or !isloggedin() or $newslettertemp->type == 'news') {
        // no button and no info

    } else if ($groupmode and has_capability('mod/newslettertemp:startdiscussion', $context)) {
        // inform users why they can not post new discussion
        if ($currentgroup) {
            echo $OUTPUT->notification(get_string('cannotadddiscussion', 'newslettertemp'));
        } else {
            echo $OUTPUT->notification(get_string('cannotadddiscussionall', 'newslettertemp'));
        }
    }

// Get all the recent discussions we're allowed to see

    $getuserlastmodified = ($displayformat == 'header');

    if (! $discussions = newslettertemp_get_discussions($cm, $sort, $fullpost, null, $maxdiscussions, $getuserlastmodified, $page, $perpage) ) {
        echo '<div class="newslettertempnodiscuss">';
        if ($newslettertemp->type == 'news') {
            echo '('.get_string('nonews', 'newslettertemp').')';
        } else if ($newslettertemp->type == 'qanda') {
            echo '('.get_string('noquestions','newslettertemp').')';
        } else {
            echo '('.get_string('nodiscussions', 'newslettertemp').')';
        }
        echo "</div>\n";
        return;
    }

// If we want paging
    if ($page != -1) {
        ///Get the number of discussions found
        $numdiscussions = newslettertemp_get_discussions_count($cm);

        ///Show the paging bar
        echo $OUTPUT->paging_bar($numdiscussions, $page, $perpage, "view.php?f=$newslettertemp->id");
        if ($numdiscussions > 1000) {
            // saves some memory on sites with very large newslettertemps
            $replies = newslettertemp_count_discussion_replies($newslettertemp->id, $sort, $maxdiscussions, $page, $perpage);
        } else {
            $replies = newslettertemp_count_discussion_replies($newslettertemp->id);
        }

    } else {
        $replies = newslettertemp_count_discussion_replies($newslettertemp->id);

        if ($maxdiscussions > 0 and $maxdiscussions <= count($discussions)) {
            $olddiscussionlink = true;
        }
    }

    $canviewparticipants = has_capability('moodle/course:viewparticipants',$context);

    $strdatestring = get_string('strftimerecentfull');

    // Check if the newslettertemp is tracked.
    if ($cantrack = newslettertemp_tp_can_track_newslettertemps($newslettertemp)) {
        $newslettertemptracked = newslettertemp_tp_is_tracked($newslettertemp);
    } else {
        $newslettertemptracked = false;
    }

    if ($newslettertemptracked) {
        $unreads = newslettertemp_get_discussions_unread($cm);
    } else {
        $unreads = array();
    }

    if ($displayformat == 'header') {
        echo '<table cellspacing="0" class="newslettertempheaderlist">';
        echo '<thead>';
        echo '<tr>';
        echo '<th class="header topic" scope="col">'.get_string('discussion', 'newslettertemp').'</th>';
        echo '<th class="header author" colspan="2" scope="col">'.get_string('startedby', 'newslettertemp').'</th>';
        if ($groupmode > 0) {
            echo '<th class="header group" scope="col">'.get_string('group').'</th>';
        }
        if (has_capability('mod/newslettertemp:viewdiscussion', $context)) {
            echo '<th class="header replies" scope="col">'.get_string('replies', 'newslettertemp').'</th>';
            // If the newslettertemp can be tracked, display the unread column.
            if ($cantrack) {
                echo '<th class="header replies" scope="col">'.get_string('unread', 'newslettertemp');
                if ($newslettertemptracked) {
                    echo '<a title="'.get_string('markallread', 'newslettertemp').
                         '" href="'.$CFG->wwwroot.'/mod/newslettertemp/markposts.php?f='.
                         $newslettertemp->id.'&amp;mark=read&amp;returnpage=view.php">'.
                         '<img src="'.$OUTPUT->pix_url('t/markasread') . '" class="iconsmall" alt="'.get_string('markallread', 'newslettertemp').'" /></a>';
                }
                echo '</th>';
            }
        }
        echo '<th class="header lastpost" scope="col">'.get_string('lastpost', 'newslettertemp').'</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
    }

    foreach ($discussions as $discussion) {
        if (!empty($replies[$discussion->discussion])) {
            $discussion->replies = $replies[$discussion->discussion]->replies;
            $discussion->lastpostid = $replies[$discussion->discussion]->lastpostid;
        } else {
            $discussion->replies = 0;
        }

        // SPECIAL CASE: The front page can display a news item post to non-logged in users.
        // All posts are read in this case.
        if (!$newslettertemptracked) {
            $discussion->unread = '-';
        } else if (empty($USER)) {
            $discussion->unread = 0;
        } else {
            if (empty($unreads[$discussion->discussion])) {
                $discussion->unread = 0;
            } else {
                $discussion->unread = $unreads[$discussion->discussion];
            }
        }

        if (isloggedin()) {
            $ownpost = ($discussion->userid == $USER->id);
        } else {
            $ownpost=false;
        }
        // Use discussion name instead of subject of first post
        $discussion->subject = $discussion->name;

        switch ($displayformat) {
            case 'header':
                if ($groupmode > 0) {
                    if (isset($groups[$discussion->groupid])) {
                        $group = $groups[$discussion->groupid];
                    } else {
                        $group = $groups[$discussion->groupid] = groups_get_group($discussion->groupid);
                    }
                } else {
                    $group = -1;
                }
                newslettertemp_print_discussion_header($discussion, $newslettertemp, $group, $strdatestring, $cantrack, $newslettertemptracked,
                    $canviewparticipants, $context);
            break;
            default:
                $link = false;

                if ($discussion->replies) {
                    $link = true;
                } else {
                    $modcontext = context_module::instance($cm->id);
                    $link = newslettertemp_user_can_post($newslettertemp, $discussion, $USER, $cm, $course, $modcontext);
                }

                $discussion->newslettertemp = $newslettertemp->id;

                newslettertemp_print_post($discussion, $discussion, $newslettertemp, $cm, $course, $ownpost, 0, $link, false,
                        '', null, true, $newslettertemptracked);
            break;
        }
    }

    if ($displayformat == "header") {
        echo '</tbody>';
        echo '</table>';
    }

    if ($olddiscussionlink) {
        if ($newslettertemp->type == 'news') {
            $strolder = get_string('oldertopics', 'newslettertemp');
        } else {
            $strolder = get_string('olderdiscussions', 'newslettertemp');
        }
        echo '<div class="newslettertempolddiscuss">';
        echo '<a href="'.$CFG->wwwroot.'/mod/newslettertemp/view.php?f='.$newslettertemp->id.'&amp;showall=1">';
        echo $strolder.'</a> ...</div>';
    }

    if ($page != -1) { ///Show the paging bar
        echo $OUTPUT->paging_bar($numdiscussions, $page, $perpage, "view.php?f=$newslettertemp->id");
    }
}


/**
 * Prints a newslettertemp discussion
 *
 * @uses CONTEXT_MODULE
 * @uses NEWSLETTERTEMP_MODE_FLATNEWEST
 * @uses NEWSLETTERTEMP_MODE_FLATOLDEST
 * @uses NEWSLETTERTEMP_MODE_THREADED
 * @uses NEWSLETTERTEMP_MODE_NESTED
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $newslettertemp
 * @param stdClass $discussion
 * @param stdClass $post
 * @param int $mode
 * @param mixed $canreply
 * @param bool $canrate
 */
function newslettertemp_print_discussion($course, $cm, $newslettertemp, $discussion, $post, $mode, $canreply=NULL, $canrate=false) {
    global $USER, $CFG;

    require_once($CFG->dirroot.'/rating/lib.php');

    $ownpost = (isloggedin() && $USER->id == $post->userid);

    $modcontext = context_module::instance($cm->id);
    if ($canreply === NULL) {
        $reply = newslettertemp_user_can_post($newslettertemp, $discussion, $USER, $cm, $course, $modcontext);
    } else {
        $reply = $canreply;
    }

    // $cm holds general cache for newslettertemp functions
    $cm->cache = new stdClass;
    $cm->cache->groups      = groups_get_all_groups($course->id, 0, $cm->groupingid);
    $cm->cache->usersgroups = array();

    $posters = array();

    // preload all posts - TODO: improve...
    if ($mode == NEWSLETTERTEMP_MODE_FLATNEWEST) {
        $sort = "p.created DESC";
    } else {
        $sort = "p.created ASC";
    }

    $newslettertemptracked = newslettertemp_tp_is_tracked($newslettertemp);
    $posts = newslettertemp_get_all_discussion_posts($discussion->id, $sort, $newslettertemptracked);
    $post = $posts[$post->id];

    foreach ($posts as $pid=>$p) {
        $posters[$p->userid] = $p->userid;
    }

    // preload all groups of ppl that posted in this discussion
    if ($postersgroups = groups_get_all_groups($course->id, $posters, $cm->groupingid, 'gm.id, gm.groupid, gm.userid')) {
        foreach($postersgroups as $pg) {
            if (!isset($cm->cache->usersgroups[$pg->userid])) {
                $cm->cache->usersgroups[$pg->userid] = array();
            }
            $cm->cache->usersgroups[$pg->userid][$pg->groupid] = $pg->groupid;
        }
        unset($postersgroups);
    }

    //load ratings
    if ($newslettertemp->assessed != RATING_AGGREGATE_NONE) {
        $ratingoptions = new stdClass;
        $ratingoptions->context = $modcontext;
        $ratingoptions->component = 'mod_newslettertemp';
        $ratingoptions->ratingarea = 'post';
        $ratingoptions->items = $posts;
        $ratingoptions->aggregate = $newslettertemp->assessed;//the aggregation method
        $ratingoptions->scaleid = $newslettertemp->scale;
        $ratingoptions->userid = $USER->id;
        if ($newslettertemp->type == 'single' or !$discussion->id) {
            $ratingoptions->returnurl = "$CFG->wwwroot/mod/newslettertemp/view.php?id=$cm->id";
        } else {
            $ratingoptions->returnurl = "$CFG->wwwroot/mod/newslettertemp/discuss.php?d=$discussion->id";
        }
        $ratingoptions->assesstimestart = $newslettertemp->assesstimestart;
        $ratingoptions->assesstimefinish = $newslettertemp->assesstimefinish;

        $rm = new rating_manager();
        $posts = $rm->get_ratings($ratingoptions);
    }


    $post->newslettertemp = $newslettertemp->id;   // Add the newslettertemp id to the post object, later used by newslettertemp_print_post
    $post->newslettertemptype = $newslettertemp->type;

    $post->subject = format_string($post->subject);

    $postread = !empty($post->postread);

    newslettertemp_print_post($post, $discussion, $newslettertemp, $cm, $course, $ownpost, $reply, false,
                         '', '', $postread, true, $newslettertemptracked);

    switch ($mode) {
        case NEWSLETTERTEMP_MODE_FLATOLDEST :
        case NEWSLETTERTEMP_MODE_FLATNEWEST :
        default:
            newslettertemp_print_posts_flat($course, $cm, $newslettertemp, $discussion, $post, $mode, $reply, $newslettertemptracked, $posts);
            break;

        case NEWSLETTERTEMP_MODE_THREADED :
            newslettertemp_print_posts_threaded($course, $cm, $newslettertemp, $discussion, $post, 0, $reply, $newslettertemptracked, $posts);
            break;

        case NEWSLETTERTEMP_MODE_NESTED :
            newslettertemp_print_posts_nested($course, $cm, $newslettertemp, $discussion, $post, $reply, $newslettertemptracked, $posts);
            break;
    }
}


/**
 * @global object
 * @global object
 * @uses NEWSLETTERTEMP_MODE_FLATNEWEST
 * @param object $course
 * @param object $cm
 * @param object $newslettertemp
 * @param object $discussion
 * @param object $post
 * @param object $mode
 * @param bool $reply
 * @param bool $newslettertemptracked
 * @param array $posts
 * @return void
 */
function newslettertemp_print_posts_flat($course, &$cm, $newslettertemp, $discussion, $post, $mode, $reply, $newslettertemptracked, $posts) {
    global $USER, $CFG;

    $link  = false;

    if ($mode == NEWSLETTERTEMP_MODE_FLATNEWEST) {
        $sort = "ORDER BY created DESC";
    } else {
        $sort = "ORDER BY created ASC";
    }

    foreach ($posts as $post) {
        if (!$post->parent) {
            continue;
        }
        $post->subject = format_string($post->subject);
        $ownpost = ($USER->id == $post->userid);

        $postread = !empty($post->postread);

        newslettertemp_print_post($post, $discussion, $newslettertemp, $cm, $course, $ownpost, $reply, $link,
                             '', '', $postread, true, $newslettertemptracked);
    }
}

/**
 * @todo Document this function
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @return void
 */
function newslettertemp_print_posts_threaded($course, &$cm, $newslettertemp, $discussion, $parent, $depth, $reply, $newslettertemptracked, $posts) {
    global $USER, $CFG;

    $link  = false;

    if (!empty($posts[$parent->id]->children)) {
        $posts = $posts[$parent->id]->children;

        $modcontext       = context_module::instance($cm->id);
        $canviewfullnames = has_capability('moodle/site:viewfullnames', $modcontext);

        foreach ($posts as $post) {

            echo '<div class="indent">';
            if ($depth > 0) {
                $ownpost = ($USER->id == $post->userid);
                $post->subject = format_string($post->subject);

                $postread = !empty($post->postread);

                newslettertemp_print_post($post, $discussion, $newslettertemp, $cm, $course, $ownpost, $reply, $link,
                                     '', '', $postread, true, $newslettertemptracked);
            } else {
                if (!newslettertemp_user_can_see_post($newslettertemp, $discussion, $post, NULL, $cm)) {
                    echo "</div>\n";
                    continue;
                }
                $by = new stdClass();
                $by->name = fullname($post, $canviewfullnames);
                $by->date = userdate($post->modified);

                if ($newslettertemptracked) {
                    if (!empty($post->postread)) {
                        $style = '<span class="newslettertempthread read">';
                    } else {
                        $style = '<span class="newslettertempthread unread">';
                    }
                } else {
                    $style = '<span class="newslettertempthread">';
                }
                echo $style."<a name=\"$post->id\"></a>".
                     "<a href=\"discuss.php?d=$post->discussion&amp;parent=$post->id\">".format_string($post->subject,true)."</a> ";
                print_string("bynameondate", "newslettertemp", $by);
                echo "</span>";
            }

            newslettertemp_print_posts_threaded($course, $cm, $newslettertemp, $discussion, $post, $depth-1, $reply, $newslettertemptracked, $posts);
            echo "</div>\n";
        }
    }
}

/**
 * @todo Document this function
 * @global object
 * @global object
 * @return void
 */
function newslettertemp_print_posts_nested($course, &$cm, $newslettertemp, $discussion, $parent, $reply, $newslettertemptracked, $posts) {
    global $USER, $CFG;

    $link  = false;

    if (!empty($posts[$parent->id]->children)) {
        $posts = $posts[$parent->id]->children;

        foreach ($posts as $post) {

            echo '<div class="indent">';
            if (!isloggedin()) {
                $ownpost = false;
            } else {
                $ownpost = ($USER->id == $post->userid);
            }

            $post->subject = format_string($post->subject);
            $postread = !empty($post->postread);

            newslettertemp_print_post($post, $discussion, $newslettertemp, $cm, $course, $ownpost, $reply, $link,
                                 '', '', $postread, true, $newslettertemptracked);
            newslettertemp_print_posts_nested($course, $cm, $newslettertemp, $discussion, $post, $reply, $newslettertemptracked, $posts);
            echo "</div>\n";
        }
    }
}

/**
 * Returns all newslettertemp posts since a given time in specified newslettertemp.
 *
 * @todo Document this functions args
 * @global object
 * @global object
 * @global object
 * @global object
 */
function newslettertemp_get_recent_mod_activity(&$activities, &$index, $timestart, $courseid, $cmid, $userid=0, $groupid=0)  {
    global $CFG, $COURSE, $USER, $DB;

    if ($COURSE->id == $courseid) {
        $course = $COURSE;
    } else {
        $course = $DB->get_record('course', array('id' => $courseid));
    }

    $modinfo = get_fast_modinfo($course);

    $cm = $modinfo->cms[$cmid];
    $params = array($timestart, $cm->instance);

    if ($userid) {
        $userselect = "AND u.id = ?";
        $params[] = $userid;
    } else {
        $userselect = "";
    }

    if ($groupid) {
        $groupselect = "AND gm.groupid = ?";
        $groupjoin   = "JOIN {groups_members} gm ON  gm.userid=u.id";
        $params[] = $groupid;
    } else {
        $groupselect = "";
        $groupjoin   = "";
    }

    if (!$posts = $DB->get_records_sql("SELECT p.*, f.type AS newslettertemptype, d.newslettertemp, d.groupid,
                                              d.timestart, d.timeend, d.userid AS duserid,
                                              u.firstname, u.lastname, u.email, u.picture, u.imagealt, u.email
                                         FROM {newslettertemp_posts} p
                                              JOIN {newslettertemp_discussions} d ON d.id = p.discussion
                                              JOIN {newslettertemp} f             ON f.id = d.newslettertemp
                                              JOIN {user} u              ON u.id = p.userid
                                              $groupjoin
                                        WHERE p.created > ? AND f.id = ?
                                              $userselect $groupselect
                                     ORDER BY p.id ASC", $params)) { // order by initial posting date
         return;
    }

    $groupmode       = groups_get_activity_groupmode($cm, $course);
    $cm_context      = context_module::instance($cm->id);
    $viewhiddentimed = has_capability('mod/newslettertemp:viewhiddentimedposts', $cm_context);
    $accessallgroups = has_capability('moodle/site:accessallgroups', $cm_context);

    if (is_null($modinfo->groups)) {
        $modinfo->groups = groups_get_user_groups($course->id); // load all my groups and cache it in modinfo
    }

    $printposts = array();
    foreach ($posts as $post) {

        if (!empty($CFG->newslettertemp_enabletimedposts) and $USER->id != $post->duserid
          and (($post->timestart > 0 and $post->timestart > time()) or ($post->timeend > 0 and $post->timeend < time()))) {
            if (!$viewhiddentimed) {
                continue;
            }
        }

        if ($groupmode) {
            if ($post->groupid == -1 or $groupmode == VISIBLEGROUPS or $accessallgroups) {
                // oki (Open discussions have groupid -1)
            } else {
                // separate mode
                if (isguestuser()) {
                    // shortcut
                    continue;
                }

                if (!array_key_exists($post->groupid, $modinfo->groups[0])) {
                    continue;
                }
            }
        }

        $printposts[] = $post;
    }

    if (!$printposts) {
        return;
    }

    $aname = format_string($cm->name,true);

    foreach ($printposts as $post) {
        $tmpactivity = new stdClass();

        $tmpactivity->type         = 'newslettertemp';
        $tmpactivity->cmid         = $cm->id;
        $tmpactivity->name         = $aname;
        $tmpactivity->sectionnum   = $cm->sectionnum;
        $tmpactivity->timestamp    = $post->modified;

        $tmpactivity->content = new stdClass();
        $tmpactivity->content->id         = $post->id;
        $tmpactivity->content->discussion = $post->discussion;
        $tmpactivity->content->subject    = format_string($post->subject);
        $tmpactivity->content->parent     = $post->parent;

        $tmpactivity->user = new stdClass();
        $tmpactivity->user->id        = $post->userid;
        $tmpactivity->user->firstname = $post->firstname;
        $tmpactivity->user->lastname  = $post->lastname;
        $tmpactivity->user->picture   = $post->picture;
        $tmpactivity->user->imagealt  = $post->imagealt;
        $tmpactivity->user->email     = $post->email;

        $activities[$index++] = $tmpactivity;
    }

    return;
}

/**
 * @todo Document this function
 * @global object
 */
function newslettertemp_print_recent_mod_activity($activity, $courseid, $detail, $modnames, $viewfullnames) {
    global $CFG, $OUTPUT;

    if ($activity->content->parent) {
        $class = 'reply';
    } else {
        $class = 'discussion';
    }

    echo '<table border="0" cellpadding="3" cellspacing="0" class="newslettertemp-recent">';

    echo "<tr><td class=\"userpicture\" valign=\"top\">";
    echo $OUTPUT->user_picture($activity->user, array('courseid'=>$courseid));
    echo "</td><td class=\"$class\">";

    echo '<div class="title">';
    if ($detail) {
        $aname = s($activity->name);
        echo "<img src=\"" . $OUTPUT->pix_url('icon', $activity->type) . "\" ".
             "class=\"icon\" alt=\"{$aname}\" />";
    }
    echo "<a href=\"$CFG->wwwroot/mod/newslettertemp/discuss.php?d={$activity->content->discussion}"
         ."#p{$activity->content->id}\">{$activity->content->subject}</a>";
    echo '</div>';

    echo '<div class="user">';
    $fullname = fullname($activity->user, $viewfullnames);
    echo "<a href=\"$CFG->wwwroot/user/view.php?id={$activity->user->id}&amp;course=$courseid\">"
         ."{$fullname}</a> - ".userdate($activity->timestamp);
    echo '</div>';
      echo "</td></tr></table>";

    return;
}

/**
 * recursively sets the discussion field to $discussionid on $postid and all its children
 * used when pruning a post
 *
 * @global object
 * @param int $postid
 * @param int $discussionid
 * @return bool
 */
function newslettertemp_change_discussionid($postid, $discussionid) {
    global $DB;
    $DB->set_field('newslettertemp_posts', 'discussion', $discussionid, array('id' => $postid));
    if ($posts = $DB->get_records('newslettertemp_posts', array('parent' => $postid))) {
        foreach ($posts as $post) {
            newslettertemp_change_discussionid($post->id, $discussionid);
        }
    }
    return true;
}

/**
 * Prints the editing button on subscribers page
 *
 * @global object
 * @global object
 * @param int $courseid
 * @param int $newslettertempid
 * @return string
 */
function newslettertemp_update_subscriptions_button($courseid, $newslettertempid) {
    global $CFG, $USER;

    if (!empty($USER->subscriptionsediting)) {
        $string = get_string('turneditingoff');
        $edit = "off";
    } else {
        $string = get_string('turneditingon');
        $edit = "on";
    }

    return "<form method=\"get\" action=\"$CFG->wwwroot/mod/newslettertemp/subscribers.php\">".
           "<input type=\"hidden\" name=\"id\" value=\"$newslettertempid\" />".
           "<input type=\"hidden\" name=\"edit\" value=\"$edit\" />".
           "<input type=\"submit\" value=\"$string\" /></form>";
}

/**
 * This function gets run whenever user is enrolled into course
 *
 * @deprecated deprecating this function as we will be using newslettertemp_user_role_assigned
 * @param stdClass $cp
 * @return void
 */
function newslettertemp_user_enrolled($cp) {
    global $DB;

    // NOTE: this has to be as fast as possible - we do not want to slow down enrolments!
    //       Originally there used to be 'mod/newslettertemp:initialsubscriptions' which was
    //       introduced because we did not have enrolment information in earlier versions...

    $sql = "SELECT f.id
              FROM {newslettertemp} f
         LEFT JOIN {newslettertemp_subscriptions} fs ON (fs.newslettertemp = f.id AND fs.userid = :userid)
             WHERE f.course = :courseid AND f.forcesubscribe = :initial AND fs.id IS NULL";
    $params = array('courseid'=>$cp->courseid, 'userid'=>$cp->userid, 'initial'=>NEWSLETTERTEMP_INITIALSUBSCRIBE);

    $newslettertemps = $DB->get_records_sql($sql, $params);
    foreach ($newslettertemps as $newslettertemp) {
        newslettertemp_subscribe($cp->userid, $newslettertemp->id);
    }
}

/**
 * This function gets run whenever user is assigned role in course
 *
 * @param stdClass $cp
 * @return void
 */
function newslettertemp_user_role_assigned($cp) {
    global $DB;

    $context = context::instance_by_id($cp->contextid, MUST_EXIST);

    // If contextlevel is course then only subscribe user. Role assignment
    // at course level means user is enroled in course and can subscribe to newslettertemp.
    if ($context->contextlevel != CONTEXT_COURSE) {
        return;
    }

    $sql = "SELECT f.id, cm.id AS cmid
              FROM {newslettertemp} f
              JOIN {course_modules} cm ON (cm.instance = f.id)
              JOIN {modules} m ON (m.id = cm.module)
         LEFT JOIN {newslettertemp_subscriptions} fs ON (fs.newslettertemp = f.id AND fs.userid = :userid)
             WHERE f.course = :courseid
               AND f.forcesubscribe = :initial
               AND m.name = 'newslettertemp'
               AND fs.id IS NULL";
    $params = array('courseid'=>$context->instanceid, 'userid'=>$cp->userid, 'initial'=>NEWSLETTERTEMP_INITIALSUBSCRIBE);

    $newslettertemps = $DB->get_records_sql($sql, $params);
    foreach ($newslettertemps as $newslettertemp) {
        // If user doesn't have allowforcesubscribe capability then don't subscribe.
        if (has_capability('mod/newslettertemp:allowforcesubscribe', context_module::instance($newslettertemp->cmid), $cp->userid)) {
            newslettertemp_subscribe($cp->userid, $newslettertemp->id);
        }
    }
}

/**
 * This function gets run whenever user is unenrolled from course
 *
 * @param stdClass $cp
 * @return void
 */
function newslettertemp_user_unenrolled($cp) {
    global $DB;

    // NOTE: this has to be as fast as possible!

    if ($cp->lastenrol) {
        $params = array('userid'=>$cp->userid, 'courseid'=>$cp->courseid);
        $newslettertempselect = "IN (SELECT f.id FROM {newslettertemp} f WHERE f.course = :courseid)";

        $DB->delete_records_select('newslettertemp_subscriptions', "userid = :userid AND newslettertemp $newslettertempselect", $params);
        $DB->delete_records_select('newslettertemp_track_prefs',   "userid = :userid AND newslettertempid $newslettertempselect", $params);
        $DB->delete_records_select('newslettertemp_read',          "userid = :userid AND newslettertempid $newslettertempselect", $params);
    }
}

// Functions to do with read tracking.

/**
 * Mark posts as read.
 *
 * @global object
 * @global object
 * @param object $user object
 * @param array $postids array of post ids
 * @return boolean success
 */
function newslettertemp_tp_mark_posts_read($user, $postids) {
    global $CFG, $DB;

    if (!newslettertemp_tp_can_track_newslettertemps(false, $user)) {
        return true;
    }

    $status = true;

    $now = time();
    $cutoffdate = $now - ($CFG->newslettertemp_oldpostdays * 24 * 3600);

    if (empty($postids)) {
        return true;

    } else if (count($postids) > 200) {
        while ($part = array_splice($postids, 0, 200)) {
            $status = newslettertemp_tp_mark_posts_read($user, $part) && $status;
        }
        return $status;
    }

    list($usql, $params) = $DB->get_in_or_equal($postids);
    $params[] = $user->id;

    $sql = "SELECT id
              FROM {newslettertemp_read}
             WHERE postid $usql AND userid = ?";
    if ($existing = $DB->get_records_sql($sql, $params)) {
        $existing = array_keys($existing);
    } else {
        $existing = array();
    }

    $new = array_diff($postids, $existing);

    if ($new) {
        list($usql, $new_params) = $DB->get_in_or_equal($new);
        $params = array($user->id, $now, $now, $user->id);
        $params = array_merge($params, $new_params);
        $params[] = $cutoffdate;

        $sql = "INSERT INTO {newslettertemp_read} (userid, postid, discussionid, newslettertempid, firstread, lastread)

                SELECT ?, p.id, p.discussion, d.newslettertemp, ?, ?
                  FROM {newslettertemp_posts} p
                       JOIN {newslettertemp_discussions} d       ON d.id = p.discussion
                       JOIN {newslettertemp} f                   ON f.id = d.newslettertemp
                       LEFT JOIN {newslettertemp_track_prefs} tf ON (tf.userid = ? AND tf.newslettertempid = f.id)
                 WHERE p.id $usql
                       AND p.modified >= ?
                       AND (f.trackingtype = ".NEWSLETTERTEMP_TRACKING_ON."
                            OR (f.trackingtype = ".NEWSLETTERTEMP_TRACKING_OPTIONAL." AND tf.id IS NULL))";
        $status = $DB->execute($sql, $params) && $status;
    }

    if ($existing) {
        list($usql, $new_params) = $DB->get_in_or_equal($existing);
        $params = array($now, $user->id);
        $params = array_merge($params, $new_params);

        $sql = "UPDATE {newslettertemp_read}
                   SET lastread = ?
                 WHERE userid = ? AND postid $usql";
        $status = $DB->execute($sql, $params) && $status;
    }

    return $status;
}

/**
 * Mark post as read.
 * @global object
 * @global object
 * @param int $userid
 * @param int $postid
 */
function newslettertemp_tp_add_read_record($userid, $postid) {
    global $CFG, $DB;

    $now = time();
    $cutoffdate = $now - ($CFG->newslettertemp_oldpostdays * 24 * 3600);

    if (!$DB->record_exists('newslettertemp_read', array('userid' => $userid, 'postid' => $postid))) {
        $sql = "INSERT INTO {newslettertemp_read} (userid, postid, discussionid, newslettertempid, firstread, lastread)

                SELECT ?, p.id, p.discussion, d.newslettertemp, ?, ?
                  FROM {newslettertemp_posts} p
                       JOIN {newslettertemp_discussions} d ON d.id = p.discussion
                 WHERE p.id = ? AND p.modified >= ?";
        return $DB->execute($sql, array($userid, $now, $now, $postid, $cutoffdate));

    } else {
        $sql = "UPDATE {newslettertemp_read}
                   SET lastread = ?
                 WHERE userid = ? AND postid = ?";
        return $DB->execute($sql, array($now, $userid, $userid));
    }
}

/**
 * Returns all records in the 'newslettertemp_read' table matching the passed keys, indexed
 * by userid.
 *
 * @global object
 * @param int $userid
 * @param int $postid
 * @param int $discussionid
 * @param int $newslettertempid
 * @return array
 */
function newslettertemp_tp_get_read_records($userid=-1, $postid=-1, $discussionid=-1, $newslettertempid=-1) {
    global $DB;
    $select = '';
    $params = array();

    if ($userid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'userid = ?';
        $params[] = $userid;
    }
    if ($postid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'postid = ?';
        $params[] = $postid;
    }
    if ($discussionid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'discussionid = ?';
        $params[] = $discussionid;
    }
    if ($newslettertempid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'newslettertempid = ?';
        $params[] = $newslettertempid;
    }

    return $DB->get_records_select('newslettertemp_read', $select, $params);
}

/**
 * Returns all read records for the provided user and discussion, indexed by postid.
 *
 * @global object
 * @param inti $userid
 * @param int $discussionid
 */
function newslettertemp_tp_get_discussion_read_records($userid, $discussionid) {
    global $DB;
    $select = 'userid = ? AND discussionid = ?';
    $fields = 'postid, firstread, lastread';
    return $DB->get_records_select('newslettertemp_read', $select, array($userid, $discussionid), '', $fields);
}

/**
 * If its an old post, do nothing. If the record exists, the maintenance will clear it up later.
 *
 * @return bool
 */
function newslettertemp_tp_mark_post_read($userid, $post, $newslettertempid) {
    if (!newslettertemp_tp_is_post_old($post)) {
        return newslettertemp_tp_add_read_record($userid, $post->id);
    } else {
        return true;
    }
}

/**
 * Marks a whole newslettertemp as read, for a given user
 *
 * @global object
 * @global object
 * @param object $user
 * @param int $newslettertempid
 * @param int|bool $groupid
 * @return bool
 */
function newslettertemp_tp_mark_newslettertemp_read($user, $newslettertempid, $groupid=false) {
    global $CFG, $DB;

    $cutoffdate = time() - ($CFG->newslettertemp_oldpostdays*24*60*60);

    $groupsel = "";
    $params = array($user->id, $newslettertempid, $cutoffdate);

    if ($groupid !== false) {
        $groupsel = " AND (d.groupid = ? OR d.groupid = -1)";
        $params[] = $groupid;
    }

    $sql = "SELECT p.id
              FROM {newslettertemp_posts} p
                   LEFT JOIN {newslettertemp_discussions} d ON d.id = p.discussion
                   LEFT JOIN {newslettertemp_read} r        ON (r.postid = p.id AND r.userid = ?)
             WHERE d.newslettertemp = ?
                   AND p.modified >= ? AND r.id is NULL
                   $groupsel";

    if ($posts = $DB->get_records_sql($sql, $params)) {
        $postids = array_keys($posts);
        return newslettertemp_tp_mark_posts_read($user, $postids);
    }

    return true;
}

/**
 * Marks a whole discussion as read, for a given user
 *
 * @global object
 * @global object
 * @param object $user
 * @param int $discussionid
 * @return bool
 */
function newslettertemp_tp_mark_discussion_read($user, $discussionid) {
    global $CFG, $DB;

    $cutoffdate = time() - ($CFG->newslettertemp_oldpostdays*24*60*60);

    $sql = "SELECT p.id
              FROM {newslettertemp_posts} p
                   LEFT JOIN {newslettertemp_read} r ON (r.postid = p.id AND r.userid = ?)
             WHERE p.discussion = ?
                   AND p.modified >= ? AND r.id is NULL";

    if ($posts = $DB->get_records_sql($sql, array($user->id, $discussionid, $cutoffdate))) {
        $postids = array_keys($posts);
        return newslettertemp_tp_mark_posts_read($user, $postids);
    }

    return true;
}

/**
 * @global object
 * @param int $userid
 * @param object $post
 */
function newslettertemp_tp_is_post_read($userid, $post) {
    global $DB;
    return (newslettertemp_tp_is_post_old($post) ||
            $DB->record_exists('newslettertemp_read', array('userid' => $userid, 'postid' => $post->id)));
}

/**
 * @global object
 * @param object $post
 * @param int $time Defautls to time()
 */
function newslettertemp_tp_is_post_old($post, $time=null) {
    global $CFG;

    if (is_null($time)) {
        $time = time();
    }
    return ($post->modified < ($time - ($CFG->newslettertemp_oldpostdays * 24 * 3600)));
}

/**
 * Returns the count of records for the provided user and discussion.
 *
 * @global object
 * @global object
 * @param int $userid
 * @param int $discussionid
 * @return bool
 */
function newslettertemp_tp_count_discussion_read_records($userid, $discussionid) {
    global $CFG, $DB;

    $cutoffdate = isset($CFG->newslettertemp_oldpostdays) ? (time() - ($CFG->newslettertemp_oldpostdays*24*60*60)) : 0;

    $sql = 'SELECT COUNT(DISTINCT p.id) '.
           'FROM {newslettertemp_discussions} d '.
           'LEFT JOIN {newslettertemp_read} r ON d.id = r.discussionid AND r.userid = ? '.
           'LEFT JOIN {newslettertemp_posts} p ON p.discussion = d.id '.
                'AND (p.modified < ? OR p.id = r.postid) '.
           'WHERE d.id = ? ';

    return ($DB->count_records_sql($sql, array($userid, $cutoffdate, $discussionid)));
}

/**
 * Returns the count of records for the provided user and discussion.
 *
 * @global object
 * @global object
 * @param int $userid
 * @param int $discussionid
 * @return int
 */
function newslettertemp_tp_count_discussion_unread_posts($userid, $discussionid) {
    global $CFG, $DB;

    $cutoffdate = isset($CFG->newslettertemp_oldpostdays) ? (time() - ($CFG->newslettertemp_oldpostdays*24*60*60)) : 0;

    $sql = 'SELECT COUNT(p.id) '.
           'FROM {newslettertemp_posts} p '.
           'LEFT JOIN {newslettertemp_read} r ON r.postid = p.id AND r.userid = ? '.
           'WHERE p.discussion = ? '.
                'AND p.modified >= ? AND r.id is NULL';

    return $DB->count_records_sql($sql, array($userid, $discussionid, $cutoffdate));
}

/**
 * Returns the count of posts for the provided newslettertemp and [optionally] group.
 * @global object
 * @global object
 * @param int $newslettertempid
 * @param int|bool $groupid
 * @return int
 */
function newslettertemp_tp_count_newslettertemp_posts($newslettertempid, $groupid=false) {
    global $CFG, $DB;
    $params = array($newslettertempid);
    $sql = 'SELECT COUNT(*) '.
           'FROM {newslettertemp_posts} fp,{newslettertemp_discussions} fd '.
           'WHERE fd.newslettertemp = ? AND fp.discussion = fd.id';
    if ($groupid !== false) {
        $sql .= ' AND (fd.groupid = ? OR fd.groupid = -1)';
        $params[] = $groupid;
    }
    $count = $DB->count_records_sql($sql, $params);


    return $count;
}

/**
 * Returns the count of records for the provided user and newslettertemp and [optionally] group.
 * @global object
 * @global object
 * @param int $userid
 * @param int $newslettertempid
 * @param int|bool $groupid
 * @return int
 */
function newslettertemp_tp_count_newslettertemp_read_records($userid, $newslettertempid, $groupid=false) {
    global $CFG, $DB;

    $cutoffdate = time() - ($CFG->newslettertemp_oldpostdays*24*60*60);

    $groupsel = '';
    $params = array($userid, $newslettertempid, $cutoffdate);
    if ($groupid !== false) {
        $groupsel = "AND (d.groupid = ? OR d.groupid = -1)";
        $params[] = $groupid;
    }

    $sql = "SELECT COUNT(p.id)
              FROM  {newslettertemp_posts} p
                    JOIN {newslettertemp_discussions} d ON d.id = p.discussion
                    LEFT JOIN {newslettertemp_read} r   ON (r.postid = p.id AND r.userid= ?)
              WHERE d.newslettertemp = ?
                    AND (p.modified < $cutoffdate OR (p.modified >= ? AND r.id IS NOT NULL))
                    $groupsel";

    return $DB->get_field_sql($sql, $params);
}

/**
 * Returns the count of records for the provided user and course.
 * Please note that group access is ignored!
 *
 * @global object
 * @global object
 * @param int $userid
 * @param int $courseid
 * @return array
 */
function newslettertemp_tp_get_course_unread_posts($userid, $courseid) {
    global $CFG, $DB;

    $now = round(time(), -2); // db cache friendliness
    $cutoffdate = $now - ($CFG->newslettertemp_oldpostdays*24*60*60);
    $params = array($userid, $userid, $courseid, $cutoffdate);

    if (!empty($CFG->newslettertemp_enabletimedposts)) {
        $timedsql = "AND d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    $sql = "SELECT f.id, COUNT(p.id) AS unread
              FROM {newslettertemp_posts} p
                   JOIN {newslettertemp_discussions} d       ON d.id = p.discussion
                   JOIN {newslettertemp} f                   ON f.id = d.newslettertemp
                   JOIN {course} c                  ON c.id = f.course
                   LEFT JOIN {newslettertemp_read} r         ON (r.postid = p.id AND r.userid = ?)
                   LEFT JOIN {newslettertemp_track_prefs} tf ON (tf.userid = ? AND tf.newslettertempid = f.id)
             WHERE f.course = ?
                   AND p.modified >= ? AND r.id is NULL
                   AND (f.trackingtype = ".NEWSLETTERTEMP_TRACKING_ON."
                        OR (f.trackingtype = ".NEWSLETTERTEMP_TRACKING_OPTIONAL." AND tf.id IS NULL))
                   $timedsql
          GROUP BY f.id";

    if ($return = $DB->get_records_sql($sql, $params)) {
        return $return;
    }

    return array();
}

/**
 * Returns the count of records for the provided user and newslettertemp and [optionally] group.
 *
 * @global object
 * @global object
 * @global object
 * @param object $cm
 * @param object $course
 * @return int
 */
function newslettertemp_tp_count_newslettertemp_unread_posts($cm, $course) {
    global $CFG, $USER, $DB;

    static $readcache = array();

    $newslettertempid = $cm->instance;

    if (!isset($readcache[$course->id])) {
        $readcache[$course->id] = array();
        if ($counts = newslettertemp_tp_get_course_unread_posts($USER->id, $course->id)) {
            foreach ($counts as $count) {
                $readcache[$course->id][$count->id] = $count->unread;
            }
        }
    }

    if (empty($readcache[$course->id][$newslettertempid])) {
        // no need to check group mode ;-)
        return 0;
    }

    $groupmode = groups_get_activity_groupmode($cm, $course);

    if ($groupmode != SEPARATEGROUPS) {
        return $readcache[$course->id][$newslettertempid];
    }

    if (has_capability('moodle/site:accessallgroups', context_module::instance($cm->id))) {
        return $readcache[$course->id][$newslettertempid];
    }

    require_once($CFG->dirroot.'/course/lib.php');

    $modinfo = get_fast_modinfo($course);
    if (is_null($modinfo->groups)) {
        $modinfo->groups = groups_get_user_groups($course->id, $USER->id);
    }

    $mygroups = $modinfo->groups[$cm->groupingid];

    // add all groups posts
    if (empty($mygroups)) {
        $mygroups = array(-1=>-1);
    } else {
        $mygroups[-1] = -1;
    }

    list ($groups_sql, $groups_params) = $DB->get_in_or_equal($mygroups);

    $now = round(time(), -2); // db cache friendliness
    $cutoffdate = $now - ($CFG->newslettertemp_oldpostdays*24*60*60);
    $params = array($USER->id, $newslettertempid, $cutoffdate);

    if (!empty($CFG->newslettertemp_enabletimedposts)) {
        $timedsql = "AND d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    $params = array_merge($params, $groups_params);

    $sql = "SELECT COUNT(p.id)
              FROM {newslettertemp_posts} p
                   JOIN {newslettertemp_discussions} d ON p.discussion = d.id
                   LEFT JOIN {newslettertemp_read} r   ON (r.postid = p.id AND r.userid = ?)
             WHERE d.newslettertemp = ?
                   AND p.modified >= ? AND r.id is NULL
                   $timedsql
                   AND d.groupid $groups_sql";

    return $DB->get_field_sql($sql, $params);
}

/**
 * Deletes read records for the specified index. At least one parameter must be specified.
 *
 * @global object
 * @param int $userid
 * @param int $postid
 * @param int $discussionid
 * @param int $newslettertempid
 * @return bool
 */
function newslettertemp_tp_delete_read_records($userid=-1, $postid=-1, $discussionid=-1, $newslettertempid=-1) {
    global $DB;
    $params = array();

    $select = '';
    if ($userid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'userid = ?';
        $params[] = $userid;
    }
    if ($postid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'postid = ?';
        $params[] = $postid;
    }
    if ($discussionid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'discussionid = ?';
        $params[] = $discussionid;
    }
    if ($newslettertempid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'newslettertempid = ?';
        $params[] = $newslettertempid;
    }
    if ($select == '') {
        return false;
    }
    else {
        return $DB->delete_records_select('newslettertemp_read', $select, $params);
    }
}
/**
 * Get a list of newslettertemps not tracked by the user.
 *
 * @global object
 * @global object
 * @param int $userid The id of the user to use.
 * @param int $courseid The id of the course being checked.
 * @return mixed An array indexed by newslettertemp id, or false.
 */
function newslettertemp_tp_get_untracked_newslettertemps($userid, $courseid) {
    global $CFG, $DB;

    $sql = "SELECT f.id
              FROM {newslettertemp} f
                   LEFT JOIN {newslettertemp_track_prefs} ft ON (ft.newslettertempid = f.id AND ft.userid = ?)
             WHERE f.course = ?
                   AND (f.trackingtype = ".NEWSLETTERTEMP_TRACKING_OFF."
                        OR (f.trackingtype = ".NEWSLETTERTEMP_TRACKING_OPTIONAL." AND ft.id IS NOT NULL))";

    if ($newslettertemps = $DB->get_records_sql($sql, array($userid, $courseid))) {
        foreach ($newslettertemps as $newslettertemp) {
            $newslettertemps[$newslettertemp->id] = $newslettertemp;
        }
        return $newslettertemps;

    } else {
        return array();
    }
}

/**
 * Determine if a user can track newslettertemps and optionally a particular newslettertemp.
 * Checks the site settings, the user settings and the newslettertemp settings (if
 * requested).
 *
 * @global object
 * @global object
 * @global object
 * @param mixed $newslettertemp The newslettertemp object to test, or the int id (optional).
 * @param mixed $userid The user object to check for (optional).
 * @return boolean
 */
function newslettertemp_tp_can_track_newslettertemps($newslettertemp=false, $user=false) {
    global $USER, $CFG, $DB;

    // if possible, avoid expensive
    // queries
    if (empty($CFG->newslettertemp_trackreadposts)) {
        return false;
    }

    if ($user === false) {
        $user = $USER;
    }

    if (isguestuser($user) or empty($user->id)) {
        return false;
    }

    if ($newslettertemp === false) {
        // general abitily to track newslettertemps
        return (bool)$user->trackforums;
    }


    // Work toward always passing an object...
    if (is_numeric($newslettertemp)) {
        debugging('Better use proper newslettertemp object.', DEBUG_DEVELOPER);
        $newslettertemp = $DB->get_record('newslettertemp', array('id' => $newslettertemp), '', 'id,trackingtype');
    }

    $newslettertempallows = ($newslettertemp->trackingtype == NEWSLETTERTEMP_TRACKING_OPTIONAL);
    $newslettertempforced = ($newslettertemp->trackingtype == NEWSLETTERTEMP_TRACKING_ON);

    return ($newslettertempforced || $newslettertempallows)  && !empty($user->trackforums);
}

/**
 * Tells whether a specific newslettertemp is tracked by the user. A user can optionally
 * be specified. If not specified, the current user is assumed.
 *
 * @global object
 * @global object
 * @global object
 * @param mixed $newslettertemp If int, the id of the newslettertemp being checked; if object, the newslettertemp object
 * @param int $userid The id of the user being checked (optional).
 * @return boolean
 */
function newslettertemp_tp_is_tracked($newslettertemp, $user=false) {
    global $USER, $CFG, $DB;

    if ($user === false) {
        $user = $USER;
    }

    if (isguestuser($user) or empty($user->id)) {
        return false;
    }

    // Work toward always passing an object...
    if (is_numeric($newslettertemp)) {
        debugging('Better use proper newslettertemp object.', DEBUG_DEVELOPER);
        $newslettertemp = $DB->get_record('newslettertemp', array('id' => $newslettertemp));
    }

    if (!newslettertemp_tp_can_track_newslettertemps($newslettertemp, $user)) {
        return false;
    }

    $newslettertempallows = ($newslettertemp->trackingtype == NEWSLETTERTEMP_TRACKING_OPTIONAL);
    $newslettertempforced = ($newslettertemp->trackingtype == NEWSLETTERTEMP_TRACKING_ON);

    return $newslettertempforced ||
           ($newslettertempallows && $DB->get_record('newslettertemp_track_prefs', array('userid' => $user->id, 'newslettertempid' => $newslettertemp->id)) === false);
}

/**
 * @global object
 * @global object
 * @param int $newslettertempid
 * @param int $userid
 */
function newslettertemp_tp_start_tracking($newslettertempid, $userid=false) {
    global $USER, $DB;

    if ($userid === false) {
        $userid = $USER->id;
    }

    return $DB->delete_records('newslettertemp_track_prefs', array('userid' => $userid, 'newslettertempid' => $newslettertempid));
}

/**
 * @global object
 * @global object
 * @param int $newslettertempid
 * @param int $userid
 */
function newslettertemp_tp_stop_tracking($newslettertempid, $userid=false) {
    global $USER, $DB;

    if ($userid === false) {
        $userid = $USER->id;
    }

    if (!$DB->record_exists('newslettertemp_track_prefs', array('userid' => $userid, 'newslettertempid' => $newslettertempid))) {
        $track_prefs = new stdClass();
        $track_prefs->userid = $userid;
        $track_prefs->newslettertempid = $newslettertempid;
        $DB->insert_record('newslettertemp_track_prefs', $track_prefs);
    }

    return newslettertemp_tp_delete_read_records($userid, -1, -1, $newslettertempid);
}


/**
 * Clean old records from the newslettertemp_read table.
 * @global object
 * @global object
 * @return void
 */
function newslettertemp_tp_clean_read_records() {
    global $CFG, $DB;

    if (!isset($CFG->newslettertemp_oldpostdays)) {
        return;
    }
// Look for records older than the cutoffdate that are still in the newslettertemp_read table.
    $cutoffdate = time() - ($CFG->newslettertemp_oldpostdays*24*60*60);

    //first get the oldest tracking present - we need tis to speedup the next delete query
    $sql = "SELECT MIN(fp.modified) AS first
              FROM {newslettertemp_posts} fp
                   JOIN {newslettertemp_read} fr ON fr.postid=fp.id";
    if (!$first = $DB->get_field_sql($sql)) {
        // nothing to delete;
        return;
    }

    // now delete old tracking info
    $sql = "DELETE
              FROM {newslettertemp_read}
             WHERE postid IN (SELECT fp.id
                                FROM {newslettertemp_posts} fp
                               WHERE fp.modified >= ? AND fp.modified < ?)";
    $DB->execute($sql, array($first, $cutoffdate));
}

/**
 * Sets the last post for a given discussion
 *
 * @global object
 * @global object
 * @param into $discussionid
 * @return bool|int
 **/
function newslettertemp_discussion_update_last_post($discussionid) {
    global $CFG, $DB;

// Check the given discussion exists
    if (!$DB->record_exists('newslettertemp_discussions', array('id' => $discussionid))) {
        return false;
    }

// Use SQL to find the last post for this discussion
    $sql = "SELECT id, userid, modified
              FROM {newslettertemp_posts}
             WHERE discussion=?
             ORDER BY modified DESC";

// Lets go find the last post
    if (($lastposts = $DB->get_records_sql($sql, array($discussionid), 0, 1))) {
        $lastpost = reset($lastposts);
        $discussionobject = new stdClass();
        $discussionobject->id           = $discussionid;
        $discussionobject->usermodified = $lastpost->userid;
        $discussionobject->timemodified = $lastpost->modified;
        $DB->update_record('newslettertemp_discussions', $discussionobject);
        return $lastpost->id;
    }

// To get here either we couldn't find a post for the discussion (weird)
// or we couldn't update the discussion record (weird x2)
    return false;
}


/**
 * @return array
 */
function newslettertemp_get_view_actions() {
    return array('view discussion', 'search', 'newslettertemp', 'newslettertemps', 'subscribers', 'view newslettertemp');
}

/**
 * @return array
 */
function newslettertemp_get_post_actions() {
    return array('add discussion','add post','delete discussion','delete post','move discussion','prune post','update post');
}

/**
 * @global object
 * @global object
 * @global object
 * @param object $newslettertemp
 * @param object $cm
 * @return bool
 */
function newslettertemp_check_throttling($newslettertemp, $cm=null) {
    global $USER, $CFG, $DB, $OUTPUT;

    if (is_numeric($newslettertemp)) {
        $newslettertemp = $DB->get_record('newslettertemp',array('id'=>$newslettertemp));
    }
    if (!is_object($newslettertemp)) {
        return false;  // this is broken.
    }

    if (empty($newslettertemp->blockafter)) {
        return true;
    }

    if (empty($newslettertemp->blockperiod)) {
        return true;
    }

    if (!$cm) {
        if (!$cm = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id, $newslettertemp->course)) {
            print_error('invalidcoursemodule');
        }
    }

    $modcontext = context_module::instance($cm->id);
    if(has_capability('mod/newslettertemp:postwithoutthrottling', $modcontext)) {
        return true;
    }

    // get the number of posts in the last period we care about
    $timenow = time();
    $timeafter = $timenow - $newslettertemp->blockperiod;

    $numposts = $DB->count_records_sql('SELECT COUNT(p.id) FROM {newslettertemp_posts} p'
                                      .' JOIN {newslettertemp_discussions} d'
                                      .' ON p.discussion = d.id WHERE d.newslettertemp = ?'
                                      .' AND p.userid = ? AND p.created > ?', array($newslettertemp->id, $USER->id, $timeafter));

    $a = new stdClass();
    $a->blockafter = $newslettertemp->blockafter;
    $a->numposts = $numposts;
    $a->blockperiod = get_string('secondstotime'.$newslettertemp->blockperiod);

    if ($newslettertemp->blockafter <= $numposts) {
        print_error('newslettertempblockingtoomanyposts', 'error', $CFG->wwwroot.'/mod/newslettertemp/view.php?f='.$newslettertemp->id, $a);
    }
    if ($newslettertemp->warnafter <= $numposts) {
        echo $OUTPUT->notification(get_string('newslettertempblockingalmosttoomanyposts','newslettertemp',$a));
    }


}


/**
 * Removes all grades from gradebook
 *
 * @global object
 * @global object
 * @param int $courseid
 * @param string $type optional
 */
function newslettertemp_reset_gradebook($courseid, $type='') {
    global $CFG, $DB;

    $wheresql = '';
    $params = array($courseid);
    if ($type) {
        $wheresql = "AND f.type=?";
        $params[] = $type;
    }

    $sql = "SELECT f.*, cm.idnumber as cmidnumber, f.course as courseid
              FROM {newslettertemp} f, {course_modules} cm, {modules} m
             WHERE m.name='newslettertemp' AND m.id=cm.module AND cm.instance=f.id AND f.course=? $wheresql";

    if ($newslettertemps = $DB->get_records_sql($sql, $params)) {
        foreach ($newslettertemps as $newslettertemp) {
            newslettertemp_grade_item_update($newslettertemp, 'reset');
        }
    }
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 * This function will remove all posts from the specified newslettertemp
 * and clean up any related data.
 *
 * @global object
 * @global object
 * @param $data the data submitted from the reset course.
 * @return array status array
 */
function newslettertemp_reset_userdata($data) {
    global $CFG, $DB;
    require_once($CFG->dirroot.'/rating/lib.php');

    $componentstr = get_string('modulenameplural', 'newslettertemp');
    $status = array();

    $params = array($data->courseid);

    $removeposts = false;
    $typesql     = "";
    if (!empty($data->reset_newslettertemp_all)) {
        $removeposts = true;
        $typesstr    = get_string('resetnewslettertempsall', 'newslettertemp');
        $types       = array();
    } else if (!empty($data->reset_newslettertemp_types)){
        $removeposts = true;
        $typesql     = "";
        $types       = array();
        $newslettertemp_types_all = newslettertemp_get_newslettertemp_types_all();
        foreach ($data->reset_newslettertemp_types as $type) {
            if (!array_key_exists($type, $newslettertemp_types_all)) {
                continue;
            }
            $typesql .= " AND f.type=?";
            $types[] = $newslettertemp_types_all[$type];
            $params[] = $type;
        }
        $typesstr = get_string('resetnewslettertemps', 'newslettertemp').': '.implode(', ', $types);
    }
    $alldiscussionssql = "SELECT fd.id
                            FROM {newslettertemp_discussions} fd, {newslettertemp} f
                           WHERE f.course=? AND f.id=fd.newslettertemp";

    $allnewslettertempssql      = "SELECT f.id
                            FROM {newslettertemp} f
                           WHERE f.course=?";

    $allpostssql       = "SELECT fp.id
                            FROM {newslettertemp_posts} fp, {newslettertemp_discussions} fd, {newslettertemp} f
                           WHERE f.course=? AND f.id=fd.newslettertemp AND fd.id=fp.discussion";

    $newslettertempssql = $newslettertemps = $rm = null;

    if( $removeposts || !empty($data->reset_newslettertemp_ratings) ) {
        $newslettertempssql      = "$allnewslettertempssql $typesql";
        $newslettertemps = $newslettertemps = $DB->get_records_sql($newslettertempssql, $params);
        $rm = new rating_manager();;
        $ratingdeloptions = new stdClass;
        $ratingdeloptions->component = 'mod_newslettertemp';
        $ratingdeloptions->ratingarea = 'post';
    }

    if ($removeposts) {
        $discussionssql = "$alldiscussionssql $typesql";
        $postssql       = "$allpostssql $typesql";

        // now get rid of all attachments
        $fs = get_file_storage();
        if ($newslettertemps) {
            foreach ($newslettertemps as $newslettertempid=>$unused) {
                if (!$cm = get_coursemodule_from_instance('newslettertemp', $newslettertempid)) {
                    continue;
                }
                $context = context_module::instance($cm->id);
                $fs->delete_area_files($context->id, 'mod_newslettertemp', 'attachment');
                $fs->delete_area_files($context->id, 'mod_newslettertemp', 'post');

                //remove ratings
                $ratingdeloptions->contextid = $context->id;
                $rm->delete_ratings($ratingdeloptions);
            }
        }

        // first delete all read flags
        $DB->delete_records_select('newslettertemp_read', "newslettertempid IN ($newslettertempssql)", $params);

        // remove tracking prefs
        $DB->delete_records_select('newslettertemp_track_prefs', "newslettertempid IN ($newslettertempssql)", $params);

        // remove posts from queue
        $DB->delete_records_select('newslettertemp_queue', "discussionid IN ($discussionssql)", $params);

        // all posts - initial posts must be kept in single simple discussion newslettertemps
        $DB->delete_records_select('newslettertemp_posts', "discussion IN ($discussionssql) AND parent <> 0", $params); // first all children
        $DB->delete_records_select('newslettertemp_posts', "discussion IN ($discussionssql AND f.type <> 'single') AND parent = 0", $params); // now the initial posts for non single simple

        // finally all discussions except single simple newslettertemps
        $DB->delete_records_select('newslettertemp_discussions', "newslettertemp IN ($newslettertempssql AND f.type <> 'single')", $params);

        // remove all grades from gradebook
        if (empty($data->reset_gradebook_grades)) {
            if (empty($types)) {
                newslettertemp_reset_gradebook($data->courseid);
            } else {
                foreach ($types as $type) {
                    newslettertemp_reset_gradebook($data->courseid, $type);
                }
            }
        }

        $status[] = array('component'=>$componentstr, 'item'=>$typesstr, 'error'=>false);
    }

    // remove all ratings in this course's newslettertemps
    if (!empty($data->reset_newslettertemp_ratings)) {
        if ($newslettertemps) {
            foreach ($newslettertemps as $newslettertempid=>$unused) {
                if (!$cm = get_coursemodule_from_instance('newslettertemp', $newslettertempid)) {
                    continue;
                }
                $context = context_module::instance($cm->id);

                //remove ratings
                $ratingdeloptions->contextid = $context->id;
                $rm->delete_ratings($ratingdeloptions);
            }
        }

        // remove all grades from gradebook
        if (empty($data->reset_gradebook_grades)) {
            newslettertemp_reset_gradebook($data->courseid);
        }
    }

    // remove all subscriptions unconditionally - even for users still enrolled in course
    if (!empty($data->reset_newslettertemp_subscriptions)) {
        $DB->delete_records_select('newslettertemp_subscriptions', "newslettertemp IN ($allnewslettertempssql)", $params);
        $status[] = array('component'=>$componentstr, 'item'=>get_string('resetsubscriptions','newslettertemp'), 'error'=>false);
    }

    // remove all tracking prefs unconditionally - even for users still enrolled in course
    if (!empty($data->reset_newslettertemp_track_prefs)) {
        $DB->delete_records_select('newslettertemp_track_prefs', "newslettertempid IN ($allnewslettertempssql)", $params);
        $status[] = array('component'=>$componentstr, 'item'=>get_string('resettrackprefs','newslettertemp'), 'error'=>false);
    }

    /// updating dates - shift may be negative too
    if ($data->timeshift) {
        shift_course_mod_dates('newslettertemp', array('assesstimestart', 'assesstimefinish'), $data->timeshift, $data->courseid);
        $status[] = array('component'=>$componentstr, 'item'=>get_string('datechanged'), 'error'=>false);
    }

    return $status;
}

/**
 * Called by course/reset.php
 *
 * @param $mform form passed by reference
 */
function newslettertemp_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'newslettertempheader', get_string('modulenameplural', 'newslettertemp'));

    $mform->addElement('checkbox', 'reset_newslettertemp_all', get_string('resetnewslettertempsall','newslettertemp'));

    $mform->addElement('select', 'reset_newslettertemp_types', get_string('resetnewslettertemps', 'newslettertemp'), newslettertemp_get_newslettertemp_types_all(), array('multiple' => 'multiple'));
    $mform->setAdvanced('reset_newslettertemp_types');
    $mform->disabledIf('reset_newslettertemp_types', 'reset_newslettertemp_all', 'checked');

    $mform->addElement('checkbox', 'reset_newslettertemp_subscriptions', get_string('resetsubscriptions','newslettertemp'));
    $mform->setAdvanced('reset_newslettertemp_subscriptions');

    $mform->addElement('checkbox', 'reset_newslettertemp_track_prefs', get_string('resettrackprefs','newslettertemp'));
    $mform->setAdvanced('reset_newslettertemp_track_prefs');
    $mform->disabledIf('reset_newslettertemp_track_prefs', 'reset_newslettertemp_all', 'checked');

    $mform->addElement('checkbox', 'reset_newslettertemp_ratings', get_string('deleteallratings'));
    $mform->disabledIf('reset_newslettertemp_ratings', 'reset_newslettertemp_all', 'checked');
}

/**
 * Course reset form defaults.
 * @return array
 */
function newslettertemp_reset_course_form_defaults($course) {
    return array('reset_newslettertemp_all'=>1, 'reset_newslettertemp_subscriptions'=>0, 'reset_newslettertemp_track_prefs'=>0, 'reset_newslettertemp_ratings'=>1);
}

/**
 * Converts a newslettertemp to use the Roles System
 *
 * @global object
 * @global object
 * @param object $newslettertemp        a newslettertemp object with the same attributes as a record
 *                        from the newslettertemp database table
 * @param int $newslettertempmodid   the id of the newslettertemp module, from the modules table
 * @param array $teacherroles array of roles that have archetype teacher
 * @param array $studentroles array of roles that have archetype student
 * @param array $guestroles   array of roles that have archetype guest
 * @param int $cmid         the course_module id for this newslettertemp instance
 * @return boolean      newslettertemp was converted or not
 */
function newslettertemp_convert_to_roles($newslettertemp, $newslettertempmodid, $teacherroles=array(),
                                $studentroles=array(), $guestroles=array(), $cmid=NULL) {

    global $CFG, $DB, $OUTPUT;

    if (!isset($newslettertemp->open) && !isset($newslettertemp->assesspublic)) {
        // We assume that this newslettertemp has already been converted to use the
        // Roles System. Columns newslettertemp.open and newslettertemp.assesspublic get dropped
        // once the newslettertemp module has been upgraded to use Roles.
        return false;
    }

    if ($newslettertemp->type == 'teacher') {

        // Teacher newslettertemps should be converted to normal newslettertemps that
        // use the Roles System to implement the old behavior.
        // Note:
        //   Seems that teacher newslettertemps were never backed up in 1.6 since they
        //   didn't have an entry in the course_modules table.
        require_once($CFG->dirroot.'/course/lib.php');

        if ($DB->count_records('newslettertemp_discussions', array('newslettertemp' => $newslettertemp->id)) == 0) {
            // Delete empty teacher newslettertemps.
            $DB->delete_records('newslettertemp', array('id' => $newslettertemp->id));
        } else {
            // Create a course module for the newslettertemp and assign it to
            // section 0 in the course.
            $mod = new stdClass();
            $mod->course = $newslettertemp->course;
            $mod->module = $newslettertempmodid;
            $mod->instance = $newslettertemp->id;
            $mod->section = 0;
            $mod->visible = 0;     // Hide the newslettertemp
            $mod->visibleold = 0;  // Hide the newslettertemp
            $mod->groupmode = 0;

            if (!$cmid = add_course_module($mod)) {
                print_error('cannotcreateinstanceforteacher', 'newslettertemp');
            } else {
                $sectionid = course_add_cm_to_section($newslettertemp->course, $mod->coursemodule, 0);
            }

            // Change the newslettertemp type to general.
            $newslettertemp->type = 'general';
            $DB->update_record('newslettertemp', $newslettertemp);

            $context = context_module::instance($cmid);

            // Create overrides for default student and guest roles (prevent).
            foreach ($studentroles as $studentrole) {
                assign_capability('mod/newslettertemp:viewdiscussion', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/newslettertemp:viewhiddentimedposts', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/newslettertemp:startdiscussion', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/newslettertemp:replypost', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/newslettertemp:viewrating', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/newslettertemp:viewanyrating', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/newslettertemp:rate', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/newslettertemp:createattachment', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/newslettertemp:deleteownpost', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/newslettertemp:deleteanypost', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/newslettertemp:splitdiscussions', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/newslettertemp:movediscussions', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/newslettertemp:editanypost', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/newslettertemp:viewqandawithoutposting', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/newslettertemp:viewsubscribers', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/newslettertemp:managesubscriptions', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/newslettertemp:postwithoutthrottling', CAP_PREVENT, $studentrole->id, $context->id);
            }
            foreach ($guestroles as $guestrole) {
                assign_capability('mod/newslettertemp:viewdiscussion', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/newslettertemp:viewhiddentimedposts', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/newslettertemp:startdiscussion', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/newslettertemp:replypost', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/newslettertemp:viewrating', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/newslettertemp:viewanyrating', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/newslettertemp:rate', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/newslettertemp:createattachment', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/newslettertemp:deleteownpost', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/newslettertemp:deleteanypost', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/newslettertemp:splitdiscussions', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/newslettertemp:movediscussions', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/newslettertemp:editanypost', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/newslettertemp:viewqandawithoutposting', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/newslettertemp:viewsubscribers', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/newslettertemp:managesubscriptions', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/newslettertemp:postwithoutthrottling', CAP_PREVENT, $guestrole->id, $context->id);
            }
        }
    } else {
        // Non-teacher newslettertemp.

        if (empty($cmid)) {
            // We were not given the course_module id. Try to find it.
            if (!$cm = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id)) {
                echo $OUTPUT->notification('Could not get the course module for the newslettertemp');
                return false;
            } else {
                $cmid = $cm->id;
            }
        }
        $context = context_module::instance($cmid);

        // $newslettertemp->open defines what students can do:
        //   0 = No discussions, no replies
        //   1 = No discussions, but replies are allowed
        //   2 = Discussions and replies are allowed
        switch ($newslettertemp->open) {
            case 0:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/newslettertemp:startdiscussion', CAP_PREVENT, $studentrole->id, $context->id);
                    assign_capability('mod/newslettertemp:replypost', CAP_PREVENT, $studentrole->id, $context->id);
                }
                break;
            case 1:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/newslettertemp:startdiscussion', CAP_PREVENT, $studentrole->id, $context->id);
                    assign_capability('mod/newslettertemp:replypost', CAP_ALLOW, $studentrole->id, $context->id);
                }
                break;
            case 2:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/newslettertemp:startdiscussion', CAP_ALLOW, $studentrole->id, $context->id);
                    assign_capability('mod/newslettertemp:replypost', CAP_ALLOW, $studentrole->id, $context->id);
                }
                break;
        }

        // $newslettertemp->assessed defines whether newslettertemp rating is turned
        // on (1 or 2) and who can rate posts:
        //   1 = Everyone can rate posts
        //   2 = Only teachers can rate posts
        switch ($newslettertemp->assessed) {
            case 1:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/newslettertemp:rate', CAP_ALLOW, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('mod/newslettertemp:rate', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
            case 2:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/newslettertemp:rate', CAP_PREVENT, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('mod/newslettertemp:rate', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
        }

        // $newslettertemp->assesspublic defines whether students can see
        // everybody's ratings:
        //   0 = Students can only see their own ratings
        //   1 = Students can see everyone's ratings
        switch ($newslettertemp->assesspublic) {
            case 0:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/newslettertemp:viewanyrating', CAP_PREVENT, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('mod/newslettertemp:viewanyrating', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
            case 1:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/newslettertemp:viewanyrating', CAP_ALLOW, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('mod/newslettertemp:viewanyrating', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
        }

        if (empty($cm)) {
            $cm = $DB->get_record('course_modules', array('id' => $cmid));
        }

        // $cm->groupmode:
        // 0 - No groups
        // 1 - Separate groups
        // 2 - Visible groups
        switch ($cm->groupmode) {
            case 0:
                break;
            case 1:
                foreach ($studentroles as $studentrole) {
                    assign_capability('moodle/site:accessallgroups', CAP_PREVENT, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('moodle/site:accessallgroups', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
            case 2:
                foreach ($studentroles as $studentrole) {
                    assign_capability('moodle/site:accessallgroups', CAP_ALLOW, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('moodle/site:accessallgroups', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
        }
    }
    return true;
}

/**
 * Returns array of newslettertemp layout modes
 *
 * @return array
 */
function newslettertemp_get_layout_modes() {
    return array (NEWSLETTERTEMP_MODE_FLATOLDEST => get_string('modeflatoldestfirst', 'newslettertemp'),
                  NEWSLETTERTEMP_MODE_FLATNEWEST => get_string('modeflatnewestfirst', 'newslettertemp'),
                  NEWSLETTERTEMP_MODE_THREADED   => get_string('modethreaded', 'newslettertemp'),
                  NEWSLETTERTEMP_MODE_NESTED     => get_string('modenested', 'newslettertemp'));
}

/**
 * Returns array of newslettertemp types chooseable on the newslettertemp editing form
 *
 * @return array
 */
function newslettertemp_get_newslettertemp_types() {
    return array ('general'  => get_string('generalnewslettertemp', 'newslettertemp'),
                  'eachuser' => get_string('eachusernewslettertemp', 'newslettertemp'),
                  'single'   => get_string('singlenewslettertemp', 'newslettertemp'),
                  'qanda'    => get_string('qandanewslettertemp', 'newslettertemp'),
                  'blog'     => get_string('blognewslettertemp', 'newslettertemp'));
}

/**
 * Returns array of all newslettertemp layout modes
 *
 * @return array
 */
function newslettertemp_get_newslettertemp_types_all() {
    return array ('news'     => get_string('namenews','newslettertemp'),
                  'social'   => get_string('namesocial','newslettertemp'),
                  'general'  => get_string('generalnewslettertemp', 'newslettertemp'),
                  'eachuser' => get_string('eachusernewslettertemp', 'newslettertemp'),
                  'single'   => get_string('singlenewslettertemp', 'newslettertemp'),
                  'qanda'    => get_string('qandanewslettertemp', 'newslettertemp'),
                  'blog'     => get_string('blognewslettertemp', 'newslettertemp'));
}

/**
 * Returns array of newslettertemp open modes
 *
 * @return array
 */
function newslettertemp_get_open_modes() {
    return array ('2' => get_string('openmode2', 'newslettertemp'),
                  '1' => get_string('openmode1', 'newslettertemp'),
                  '0' => get_string('openmode0', 'newslettertemp') );
}

/**
 * Returns all other caps used in module
 *
 * @return array
 */
function newslettertemp_get_extra_capabilities() {
    return array('moodle/site:accessallgroups', 'moodle/site:viewfullnames', 'moodle/site:trustcontent', 'moodle/rating:view', 'moodle/rating:viewany', 'moodle/rating:viewall', 'moodle/rating:rate');
}


/**
 * This function is used to extend the global navigation by add newslettertemp nodes if there
 * is relevant content.
 *
 * @param navigation_node $navref
 * @param stdClass $course
 * @param stdClass $module
 * @param stdClass $cm
 */
/*************************************************
function newslettertemp_extend_navigation($navref, $course, $module, $cm) {
    global $CFG, $OUTPUT, $USER;

    $limit = 5;

    $discussions = newslettertemp_get_discussions($cm,"d.timemodified DESC", false, -1, $limit);
    $discussioncount = newslettertemp_get_discussions_count($cm);
    if (!is_array($discussions) || count($discussions)==0) {
        return;
    }
    $discussionnode = $navref->add(get_string('discussions', 'newslettertemp').' ('.$discussioncount.')');
    $discussionnode->mainnavonly = true;
    $discussionnode->display = false; // Do not display on navigation (only on navbar)

    foreach ($discussions as $discussion) {
        $icon = new pix_icon('i/feedback', '');
        $url = new moodle_url('/mod/newslettertemp/discuss.php', array('d'=>$discussion->discussion));
        $discussionnode->add($discussion->subject, $url, navigation_node::TYPE_SETTING, null, null, $icon);
    }

    if ($discussioncount > count($discussions)) {
        if (!empty($navref->action)) {
            $url = $navref->action;
        } else {
            $url = new moodle_url('/mod/newslettertemp/view.php', array('id'=>$cm->id));
        }
        $discussionnode->add(get_string('viewalldiscussions', 'newslettertemp'), $url, navigation_node::TYPE_SETTING, null, null, $icon);
    }

    $index = 0;
    $recentposts = array();
    $lastlogin = time() - COURSE_MAX_RECENT_PERIOD;
    if (!isguestuser() and !empty($USER->lastcourseaccess[$course->id])) {
        if ($USER->lastcourseaccess[$course->id] > $lastlogin) {
            $lastlogin = $USER->lastcourseaccess[$course->id];
        }
    }
    newslettertemp_get_recent_mod_activity($recentposts, $index, $lastlogin, $course->id, $cm->id);

    if (is_array($recentposts) && count($recentposts)>0) {
        $recentnode = $navref->add(get_string('recentactivity').' ('.count($recentposts).')');
        $recentnode->mainnavonly = true;
        $recentnode->display = false;
        foreach ($recentposts as $post) {
            $icon = new pix_icon('i/feedback', '');
            $url = new moodle_url('/mod/newslettertemp/discuss.php', array('d'=>$post->content->discussion));
            $title = $post->content->subject."\n".userdate($post->timestamp, get_string('strftimerecent', 'langconfig'))."\n".$post->user->firstname.' '.$post->user->lastname;
            $recentnode->add($title, $url, navigation_node::TYPE_SETTING, null, null, $icon);
        }
    }
}
*************************/

/**
 * Adds module specific settings to the settings block
 *
 * @param settings_navigation $settings The settings navigation object
 * @param navigation_node $newslettertempnode The node to add module settings to
 */
function newslettertemp_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $newslettertempnode) {
    global $USER, $PAGE, $CFG, $DB, $OUTPUT;

    $newslettertempobject = $DB->get_record("newslettertemp", array("id" => $PAGE->cm->instance));
    if (empty($PAGE->cm->context)) {
        $PAGE->cm->context = context_module::instance($PAGE->cm->instance);
    }

    // for some actions you need to be enrolled, beiing admin is not enough sometimes here
    $enrolled = is_enrolled($PAGE->cm->context, $USER, '', false);
    $activeenrolled = is_enrolled($PAGE->cm->context, $USER, '', true);

    $canmanage  = has_capability('mod/newslettertemp:managesubscriptions', $PAGE->cm->context);
    $subscriptionmode = newslettertemp_get_forcesubscribed($newslettertempobject);
    $cansubscribe = ($activeenrolled && $subscriptionmode != NEWSLETTERTEMP_FORCESUBSCRIBE && ($subscriptionmode != NEWSLETTERTEMP_DISALLOWSUBSCRIBE || $canmanage));

    if ($canmanage) {
        $mode = $newslettertempnode->add(get_string('subscriptionmode', 'newslettertemp'), null, navigation_node::TYPE_CONTAINER);

        $allowchoice = $mode->add(get_string('subscriptionoptional', 'newslettertemp'), new moodle_url('/mod/newslettertemp/subscribe.php', array('id'=>$newslettertempobject->id, 'mode'=>NEWSLETTERTEMP_CHOOSESUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);
        $forceforever = $mode->add(get_string("subscriptionforced", "newslettertemp"), new moodle_url('/mod/newslettertemp/subscribe.php', array('id'=>$newslettertempobject->id, 'mode'=>NEWSLETTERTEMP_FORCESUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);
        $forceinitially = $mode->add(get_string("subscriptionauto", "newslettertemp"), new moodle_url('/mod/newslettertemp/subscribe.php', array('id'=>$newslettertempobject->id, 'mode'=>NEWSLETTERTEMP_INITIALSUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);
        $disallowchoice = $mode->add(get_string('subscriptiondisabled', 'newslettertemp'), new moodle_url('/mod/newslettertemp/subscribe.php', array('id'=>$newslettertempobject->id, 'mode'=>NEWSLETTERTEMP_DISALLOWSUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);

        switch ($subscriptionmode) {
            case NEWSLETTERTEMP_CHOOSESUBSCRIBE : // 0
                $allowchoice->action = null;
                $allowchoice->add_class('activesetting');
                break;
            case NEWSLETTERTEMP_FORCESUBSCRIBE : // 1
                $forceforever->action = null;
                $forceforever->add_class('activesetting');
                break;
            case NEWSLETTERTEMP_INITIALSUBSCRIBE : // 2
                $forceinitially->action = null;
                $forceinitially->add_class('activesetting');
                break;
            case NEWSLETTERTEMP_DISALLOWSUBSCRIBE : // 3
                $disallowchoice->action = null;
                $disallowchoice->add_class('activesetting');
                break;
        }

    } else if ($activeenrolled) {

        switch ($subscriptionmode) {
            case NEWSLETTERTEMP_CHOOSESUBSCRIBE : // 0
                $notenode = $newslettertempnode->add(get_string('subscriptionoptional', 'newslettertemp'));
                break;
            case NEWSLETTERTEMP_FORCESUBSCRIBE : // 1
                $notenode = $newslettertempnode->add(get_string('subscriptionforced', 'newslettertemp'));
                break;
            case NEWSLETTERTEMP_INITIALSUBSCRIBE : // 2
                $notenode = $newslettertempnode->add(get_string('subscriptionauto', 'newslettertemp'));
                break;
            case NEWSLETTERTEMP_DISALLOWSUBSCRIBE : // 3
                $notenode = $newslettertempnode->add(get_string('subscriptiondisabled', 'newslettertemp'));
                break;
        }
    }

    if ($cansubscribe) {
        if (newslettertemp_is_subscribed($USER->id, $newslettertempobject)) {
            $linktext = get_string('unsubscribe', 'newslettertemp');
        } else {
            $linktext = get_string('subscribe', 'newslettertemp');
        }
        $url = new moodle_url('/mod/newslettertemp/subscribe.php', array('id'=>$newslettertempobject->id, 'sesskey'=>sesskey()));
        $newslettertempnode->add($linktext, $url, navigation_node::TYPE_SETTING);
    }

    if (has_capability('mod/newslettertemp:viewsubscribers', $PAGE->cm->context)){
        $url = new moodle_url('/mod/newslettertemp/subscribers.php', array('id'=>$newslettertempobject->id));
        $newslettertempnode->add(get_string('showsubscribers', 'newslettertemp'), $url, navigation_node::TYPE_SETTING);
    }

    if ($enrolled && newslettertemp_tp_can_track_newslettertemps($newslettertempobject)) { // keep tracking info for users with suspended enrolments
        if ($newslettertempobject->trackingtype != NEWSLETTERTEMP_TRACKING_OPTIONAL) {
            //tracking forced on or off in newslettertemp settings so dont provide a link here to change it
            //could add unclickable text like for forced subscription but not sure this justifies adding another menu item
        } else {
            if (newslettertemp_tp_is_tracked($newslettertempobject)) {
                $linktext = get_string('notracknewslettertemp', 'newslettertemp');
            } else {
                $linktext = get_string('tracknewslettertemp', 'newslettertemp');
            }
            $url = new moodle_url('/mod/newslettertemp/settracking.php', array('id'=>$newslettertempobject->id));
            $newslettertempnode->add($linktext, $url, navigation_node::TYPE_SETTING);
        }
    }

    if (!isloggedin() && $PAGE->course->id == SITEID) {
        $userid = guest_user()->id;
    } else {
        $userid = $USER->id;
    }

    $hascourseaccess = ($PAGE->course->id == SITEID) || can_access_course($PAGE->course, $userid);
    $enablerssfeeds = !empty($CFG->enablerssfeeds) && !empty($CFG->newslettertemp_enablerssfeeds);

    if ($enablerssfeeds && $newslettertempobject->rsstype && $newslettertempobject->rssarticles && $hascourseaccess) {

        if (!function_exists('rss_get_url')) {
            require_once("$CFG->libdir/rsslib.php");
        }

        if ($newslettertempobject->rsstype == 1) {
            $string = get_string('rsssubscriberssdiscussions','newslettertemp');
        } else {
            $string = get_string('rsssubscriberssposts','newslettertemp');
        }

        $url = new moodle_url(rss_get_url($PAGE->cm->context->id, $userid, "mod_newslettertemp", $newslettertempobject->id));
        $newslettertempnode->add($string, $url, settings_navigation::TYPE_SETTING, null, null, new pix_icon('i/rss', ''));
    }
}

/**
 * Abstract class used by newslettertemp subscriber selection controls
 * @package mod-newslettertemp
 * @copyright 2009 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class newslettertemp_subscriber_selector_base extends user_selector_base {

    /**
     * The id of the newslettertemp this selector is being used for
     * @var int
     */
    protected $newslettertempid = null;
    /**
     * The context of the newslettertemp this selector is being used for
     * @var object
     */
    protected $context = null;
    /**
     * The id of the current group
     * @var int
     */
    protected $currentgroup = null;

    /**
     * Constructor method
     * @param string $name
     * @param array $options
     */
    public function __construct($name, $options) {
        $options['accesscontext'] = $options['context'];
        parent::__construct($name, $options);
        if (isset($options['context'])) {
            $this->context = $options['context'];
        }
        if (isset($options['currentgroup'])) {
            $this->currentgroup = $options['currentgroup'];
        }
        if (isset($options['newslettertempid'])) {
            $this->newslettertempid = $options['newslettertempid'];
        }
    }

    /**
     * Returns an array of options to seralise and store for searches
     *
     * @return array
     */
    protected function get_options() {
        global $CFG;
        $options = parent::get_options();
        $options['file'] =  substr(__FILE__, strlen($CFG->dirroot.'/'));
        $options['context'] = $this->context;
        $options['currentgroup'] = $this->currentgroup;
        $options['newslettertempid'] = $this->newslettertempid;
        return $options;
    }

}

/**
 * A user selector control for potential subscribers to the selected newslettertemp
 * @package mod-newslettertemp
 * @copyright 2009 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class newslettertemp_potential_subscriber_selector extends newslettertemp_subscriber_selector_base {
    const MAX_USERS_PER_PAGE = 100;

    /**
     * If set to true EVERYONE in this course is force subscribed to this newslettertemp
     * @var bool
     */
    protected $forcesubscribed = false;
    /**
     * Can be used to store existing subscribers so that they can be removed from
     * the potential subscribers list
     */
    protected $existingsubscribers = array();

    /**
     * Constructor method
     * @param string $name
     * @param array $options
     */
    public function __construct($name, $options) {
        parent::__construct($name, $options);
        if (isset($options['forcesubscribed'])) {
            $this->forcesubscribed=true;
        }
    }

    /**
     * Returns an arary of options for this control
     * @return array
     */
    protected function get_options() {
        $options = parent::get_options();
        if ($this->forcesubscribed===true) {
            $options['forcesubscribed']=1;
        }
        return $options;
    }

    /**
     * Finds all potential users
     *
     * Potential subscribers are all enroled users who are not already subscribed.
     *
     * @param string $search
     * @return array
     */
    public function find_users($search) {
        global $DB;

        $whereconditions = array();
        list($wherecondition, $params) = $this->search_sql($search, 'u');
        if ($wherecondition) {
            $whereconditions[] = $wherecondition;
        }

        if (!$this->forcesubscribed) {
            $existingids = array();
            foreach ($this->existingsubscribers as $group) {
                foreach ($group as $user) {
                    $existingids[$user->id] = 1;
                }
            }
            if ($existingids) {
                list($usertest, $userparams) = $DB->get_in_or_equal(
                        array_keys($existingids), SQL_PARAMS_NAMED, 'existing', false);
                $whereconditions[] = 'u.id ' . $usertest;
                $params = array_merge($params, $userparams);
            }
        }

        if ($whereconditions) {
            $wherecondition = 'WHERE ' . implode(' AND ', $whereconditions);
        }

        list($esql, $eparams) = get_enrolled_sql($this->context, '', $this->currentgroup, true);
        $params = array_merge($params, $eparams);

        $fields      = 'SELECT ' . $this->required_fields_sql('u');
        $countfields = 'SELECT COUNT(u.id)';

        $sql = " FROM {user} u
                 JOIN ($esql) je ON je.id = u.id
                      $wherecondition";

        list($sort, $sortparams) = users_order_by_sql('u', $search, $this->accesscontext);
        $order = ' ORDER BY ' . $sort;

        // Check to see if there are too many to show sensibly.
        if (!$this->is_validating()) {
            $potentialmemberscount = $DB->count_records_sql($countfields . $sql, $params);
            if ($potentialmemberscount > self::MAX_USERS_PER_PAGE) {
                return $this->too_many_results($search, $potentialmemberscount);
            }
        }

        // If not, show them.
        $availableusers = $DB->get_records_sql($fields . $sql . $order, array_merge($params, $sortparams));

        if (empty($availableusers)) {
            return array();
        }

        if ($this->forcesubscribed) {
            return array(get_string("existingsubscribers", 'newslettertemp') => $availableusers);
        } else {
            return array(get_string("potentialsubscribers", 'newslettertemp') => $availableusers);
        }
    }

    /**
     * Sets the existing subscribers
     * @param array $users
     */
    public function set_existing_subscribers(array $users) {
        $this->existingsubscribers = $users;
    }

    /**
     * Sets this newslettertemp as force subscribed or not
     */
    public function set_force_subscribed($setting=true) {
        $this->forcesubscribed = true;
    }
}

/**
 * User selector control for removing subscribed users
 * @package mod-newslettertemp
 * @copyright 2009 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class newslettertemp_existing_subscriber_selector extends newslettertemp_subscriber_selector_base {

    /**
     * Finds all subscribed users
     *
     * @param string $search
     * @return array
     */
    public function find_users($search) {
        global $DB;
        list($wherecondition, $params) = $this->search_sql($search, 'u');
        $params['newslettertempid'] = $this->newslettertempid;

        // only active enrolled or everybody on the frontpage
        list($esql, $eparams) = get_enrolled_sql($this->context, '', $this->currentgroup, true);
        $fields = $this->required_fields_sql('u');
        list($sort, $sortparams) = users_order_by_sql('u', $search, $this->accesscontext);
        $params = array_merge($params, $eparams, $sortparams);

        $subscribers = $DB->get_records_sql("SELECT $fields
                                               FROM {user} u
                                               JOIN ($esql) je ON je.id = u.id
                                               JOIN {newslettertemp_subscriptions} s ON s.userid = u.id
                                              WHERE $wherecondition AND s.newslettertemp = :newslettertempid
                                           ORDER BY $sort", $params);

        return array(get_string("existingsubscribers", 'newslettertemp') => $subscribers);
    }

}

/**
 * Adds information about unread messages, that is only required for the course view page (and
 * similar), to the course-module object.
 * @param cm_info $cm Course-module object
 */
function newslettertemp_cm_info_view(cm_info $cm) {
    global $CFG;

    // Get tracking status (once per request)
    static $initialised;
    static $usetracking, $strunreadpostsone;
    if (!isset($initialised)) {
        if ($usetracking = newslettertemp_tp_can_track_newslettertemps()) {
            $strunreadpostsone = get_string('unreadpostsone', 'newslettertemp');
        }
        $initialised = true;
    }

    if ($usetracking) {
        if ($unread = newslettertemp_tp_count_newslettertemp_unread_posts($cm, $cm->get_course())) {
            $out = '<span class="unread"> <a href="' . $cm->get_url() . '">';
            if ($unread == 1) {
                $out .= $strunreadpostsone;
            } else {
                $out .= get_string('unreadpostsnumber', 'newslettertemp', $unread);
            }
            $out .= '</a></span>';
            $cm->set_after_link($out);
        }
    }
}

/**
 * Return a list of page types
 * @param string $pagetype current page type
 * @param stdClass $parentcontext Block's parent context
 * @param stdClass $currentcontext Current context of block
 */
function newslettertemp_page_type_list($pagetype, $parentcontext, $currentcontext) {
    $newslettertemp_pagetype = array(
        'mod-newslettertemp-*'=>get_string('page-mod-newslettertemp-x', 'newslettertemp'),
        'mod-newslettertemp-view'=>get_string('page-mod-newslettertemp-view', 'newslettertemp'),
        'mod-newslettertemp-discuss'=>get_string('page-mod-newslettertemp-discuss', 'newslettertemp')
    );
    return $newslettertemp_pagetype;
}

/**
 * Gets all of the courses where the provided user has posted in a newslettertemp.
 *
 * @global moodle_database $DB The database connection
 * @param stdClass $user The user who's posts we are looking for
 * @param bool $discussionsonly If true only look for discussions started by the user
 * @param bool $includecontexts If set to trye contexts for the courses will be preloaded
 * @param int $limitfrom The offset of records to return
 * @param int $limitnum The number of records to return
 * @return array An array of courses
 */
function newslettertemp_get_courses_user_posted_in($user, $discussionsonly = false, $includecontexts = true, $limitfrom = null, $limitnum = null) {
    global $DB;

    // If we are only after discussions we need only look at the newslettertemp_discussions
    // table and join to the userid there. If we are looking for posts then we need
    // to join to the newslettertemp_posts table.
    if (!$discussionsonly) {
        $joinsql = 'JOIN {newslettertemp_discussions} fd ON fd.course = c.id
                    JOIN {newslettertemp_posts} fp ON fp.discussion = fd.id';
        $wheresql = 'fp.userid = :userid';
        $params = array('userid' => $user->id);
    } else {
        $joinsql = 'JOIN {newslettertemp_discussions} fd ON fd.course = c.id';
        $wheresql = 'fd.userid = :userid';
        $params = array('userid' => $user->id);
    }

    // Join to the context table so that we can preload contexts if required.
    if ($includecontexts) {
        list($ctxselect, $ctxjoin) = context_instance_preload_sql('c.id', CONTEXT_COURSE, 'ctx');
    } else {
        $ctxselect = '';
        $ctxjoin = '';
    }

    // Now we need to get all of the courses to search.
    // All courses where the user has posted within a newslettertemp will be returned.
    $sql = "SELECT DISTINCT c.* $ctxselect
            FROM {course} c
            $joinsql
            $ctxjoin
            WHERE $wheresql";
    $courses = $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
    if ($includecontexts) {
        array_map('context_instance_preload', $courses);
    }
    return $courses;
}

/**
 * Gets all of the newslettertemps a user has posted in for one or more courses.
 *
 * @global moodle_database $DB
 * @param stdClass $user
 * @param array $courseids An array of courseids to search or if not provided
 *                       all courses the user has posted within
 * @param bool $discussionsonly If true then only newslettertemps where the user has started
 *                       a discussion will be returned.
 * @param int $limitfrom The offset of records to return
 * @param int $limitnum The number of records to return
 * @return array An array of newslettertemps the user has posted within in the provided courses
 */
function newslettertemp_get_newslettertemps_user_posted_in($user, array $courseids = null, $discussionsonly = false, $limitfrom = null, $limitnum = null) {
    global $DB;

    if (!is_null($courseids)) {
        list($coursewhere, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'courseid');
        $coursewhere = ' AND f.course '.$coursewhere;
    } else {
        $coursewhere = '';
        $params = array();
    }
    $params['userid'] = $user->id;
    $params['newslettertemp'] = 'newslettertemp';

    if ($discussionsonly) {
        $join = 'JOIN {newslettertemp_discussions} ff ON ff.newslettertemp = f.id';
    } else {
        $join = 'JOIN {newslettertemp_discussions} fd ON fd.newslettertemp = f.id
                 JOIN {newslettertemp_posts} ff ON ff.discussion = fd.id';
    }

    $sql = "SELECT f.*, cm.id AS cmid
              FROM {newslettertemp} f
              JOIN {course_modules} cm ON cm.instance = f.id
              JOIN {modules} m ON m.id = cm.module
              JOIN (
                  SELECT f.id
                    FROM {newslettertemp} f
                    {$join}
                   WHERE ff.userid = :userid
                GROUP BY f.id
                   ) j ON j.id = f.id
             WHERE m.name = :newslettertemp
                 {$coursewhere}";

    $coursenewslettertemps = $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
    return $coursenewslettertemps;
}

/**
 * Returns posts made by the selected user in the requested courses.
 *
 * This method can be used to return all of the posts made by the requested user
 * within the given courses.
 * For each course the access of the current user and requested user is checked
 * and then for each post access to the post and newslettertemp is checked as well.
 *
 * This function is safe to use with usercapabilities.
 *
 * @global moodle_database $DB
 * @param stdClass $user The user whose posts we want to get
 * @param array $courses The courses to search
 * @param bool $musthaveaccess If set to true errors will be thrown if the user
 *                             cannot access one or more of the courses to search
 * @param bool $discussionsonly If set to true only discussion starting posts
 *                              will be returned.
 * @param int $limitfrom The offset of records to return
 * @param int $limitnum The number of records to return
 * @return stdClass An object the following properties
 *               ->totalcount: the total number of posts made by the requested user
 *                             that the current user can see.
 *               ->courses: An array of courses the current user can see that the
 *                          requested user has posted in.
 *               ->newslettertemps: An array of newslettertemps relating to the posts returned in the
 *                         property below.
 *               ->posts: An array containing the posts to show for this request.
 */
function newslettertemp_get_posts_by_user($user, array $courses, $musthaveaccess = false, $discussionsonly = false, $limitfrom = 0, $limitnum = 50) {
    global $DB, $USER, $CFG;

    $return = new stdClass;
    $return->totalcount = 0;    // The total number of posts that the current user is able to view
    $return->courses = array(); // The courses the current user can access
    $return->newslettertemps = array();  // The newslettertemps that the current user can access that contain posts
    $return->posts = array();   // The posts to display

    // First up a small sanity check. If there are no courses to check we can
    // return immediately, there is obviously nothing to search.
    if (empty($courses)) {
        return $return;
    }

    // A couple of quick setups
    $isloggedin = isloggedin();
    $isguestuser = $isloggedin && isguestuser();
    $iscurrentuser = $isloggedin && $USER->id == $user->id;

    // Checkout whether or not the current user has capabilities over the requested
    // user and if so they have the capabilities required to view the requested
    // users content.
    $usercontext = context_user::instance($user->id, MUST_EXIST);
    $hascapsonuser = !$iscurrentuser && $DB->record_exists('role_assignments', array('userid' => $USER->id, 'contextid' => $usercontext->id));
    $hascapsonuser = $hascapsonuser && has_all_capabilities(array('moodle/user:viewdetails', 'moodle/user:readuserposts'), $usercontext);

    // Before we actually search each course we need to check the user's access to the
    // course. If the user doesn't have the appropraite access then we either throw an
    // error if a particular course was requested or we just skip over the course.
    foreach ($courses as $course) {
        $coursecontext = context_course::instance($course->id, MUST_EXIST);
        if ($iscurrentuser || $hascapsonuser) {
            // If it is the current user, or the current user has capabilities to the
            // requested user then all we need to do is check the requested users
            // current access to the course.
            // Note: There is no need to check group access or anything of the like
            // as either the current user is the requested user, or has granted
            // capabilities on the requested user. Either way they can see what the
            // requested user posted, although its VERY unlikely in the `parent` situation
            // that the current user will be able to view the posts in context.
            if (!is_viewing($coursecontext, $user) && !is_enrolled($coursecontext, $user)) {
                // Need to have full access to a course to see the rest of own info
                if ($musthaveaccess) {
                    print_error('errorenrolmentrequired', 'newslettertemp');
                }
                continue;
            }
        } else {
            // Check whether the current user is enrolled or has access to view the course
            // if they don't we immediately have a problem.
            if (!can_access_course($course)) {
                if ($musthaveaccess) {
                    print_error('errorenrolmentrequired', 'newslettertemp');
                }
                continue;
            }

            // Check whether the requested user is enrolled or has access to view the course
            // if they don't we immediately have a problem.
            if (!can_access_course($course, $user)) {
                if ($musthaveaccess) {
                    print_error('notenrolled', 'newslettertemp');
                }
                continue;
            }

            // If groups are in use and enforced throughout the course then make sure
            // we can meet in at least one course level group.
            // Note that we check if either the current user or the requested user have
            // the capability to access all groups. This is because with that capability
            // a user in group A could post in the group B newslettertemp. Grrrr.
            if (groups_get_course_groupmode($course) == SEPARATEGROUPS && $course->groupmodeforce
              && !has_capability('moodle/site:accessallgroups', $coursecontext) && !has_capability('moodle/site:accessallgroups', $coursecontext, $user->id)) {
                // If its the guest user to bad... the guest user cannot access groups
                if (!$isloggedin or $isguestuser) {
                    // do not use require_login() here because we might have already used require_login($course)
                    if ($musthaveaccess) {
                        redirect(get_login_url());
                    }
                    continue;
                }
                // Get the groups of the current user
                $mygroups = array_keys(groups_get_all_groups($course->id, $USER->id, $course->defaultgroupingid, 'g.id, g.name'));
                // Get the groups the requested user is a member of
                $usergroups = array_keys(groups_get_all_groups($course->id, $user->id, $course->defaultgroupingid, 'g.id, g.name'));
                // Check whether they are members of the same group. If they are great.
                $intersect = array_intersect($mygroups, $usergroups);
                if (empty($intersect)) {
                    // But they're not... if it was a specific course throw an error otherwise
                    // just skip this course so that it is not searched.
                    if ($musthaveaccess) {
                        print_error("groupnotamember", '', $CFG->wwwroot."/course/view.php?id=$course->id");
                    }
                    continue;
                }
            }
        }
        // Woo hoo we got this far which means the current user can search this
        // this course for the requested user. Although this is only the course accessibility
        // handling that is complete, the newslettertemp accessibility tests are yet to come.
        $return->courses[$course->id] = $course;
    }
    // No longer beed $courses array - lose it not it may be big
    unset($courses);

    // Make sure that we have some courses to search
    if (empty($return->courses)) {
        // If we don't have any courses to search then the reality is that the current
        // user doesn't have access to any courses is which the requested user has posted.
        // Although we do know at this point that the requested user has posts.
        if ($musthaveaccess) {
            print_error('permissiondenied');
        } else {
            return $return;
        }
    }

    // Next step: Collect all of the newslettertemps that we will want to search.
    // It is important to note that this step isn't actually about searching, it is
    // about determining which newslettertemps we can search by testing accessibility.
    $newslettertemps = newslettertemp_get_newslettertemps_user_posted_in($user, array_keys($return->courses), $discussionsonly);

    // Will be used to build the where conditions for the search
    $newslettertempsearchwhere = array();
    // Will be used to store the where condition params for the search
    $newslettertempsearchparams = array();
    // Will record newslettertemps where the user can freely access everything
    $newslettertempsearchfullaccess = array();
    // DB caching friendly
    $now = round(time(), -2);
    // For each course to search we want to find the newslettertemps the user has posted in
    // and providing the current user can access the newslettertemp create a search condition
    // for the newslettertemp to get the requested users posts.
    foreach ($return->courses as $course) {
        // Now we need to get the newslettertemps
        $modinfo = get_fast_modinfo($course);
        if (empty($modinfo->instances['newslettertemp'])) {
            // hmmm, no newslettertemps? well at least its easy... skip!
            continue;
        }
        // Iterate
        foreach ($modinfo->get_instances_of('newslettertemp') as $newslettertempid => $cm) {
            if (!$cm->uservisible or !isset($newslettertemps[$newslettertempid])) {
                continue;
            }
            // Get the newslettertemp in question
            $newslettertemp = $newslettertemps[$newslettertempid];
            // This is needed for functionality later on in the newslettertemp code....
            $newslettertemp->cm = $cm;

            // Check that either the current user can view the newslettertemp, or that the
            // current user has capabilities over the requested user and the requested
            // user can view the discussion
            if (!has_capability('mod/newslettertemp:viewdiscussion', $cm->context) && !($hascapsonuser && has_capability('mod/newslettertemp:viewdiscussion', $cm->context, $user->id))) {
                continue;
            }

            // This will contain newslettertemp specific where clauses
            $newslettertempsearchselect = array();
            if (!$iscurrentuser && !$hascapsonuser) {
                // Make sure we check group access
                if (groups_get_activity_groupmode($cm, $course) == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', $cm->context)) {
                    $groups = $modinfo->get_groups($cm->groupingid);
                    $groups[] = -1;
                    list($groupid_sql, $groupid_params) = $DB->get_in_or_equal($groups, SQL_PARAMS_NAMED, 'grps'.$newslettertempid.'_');
                    $newslettertempsearchparams = array_merge($newslettertempsearchparams, $groupid_params);
                    $newslettertempsearchselect[] = "d.groupid $groupid_sql";
                }

                // hidden timed discussions
                if (!empty($CFG->newslettertemp_enabletimedposts) && !has_capability('mod/newslettertemp:viewhiddentimedposts', $cm->context)) {
                    $newslettertempsearchselect[] = "(d.userid = :userid{$newslettertempid} OR (d.timestart < :timestart{$newslettertempid} AND (d.timeend = 0 OR d.timeend > :timeend{$newslettertempid})))";
                    $newslettertempsearchparams['userid'.$newslettertempid] = $user->id;
                    $newslettertempsearchparams['timestart'.$newslettertempid] = $now;
                    $newslettertempsearchparams['timeend'.$newslettertempid] = $now;
                }

                // qanda access
                if ($newslettertemp->type == 'qanda' && !has_capability('mod/newslettertemp:viewqandawithoutposting', $cm->context)) {
                    // We need to check whether the user has posted in the qanda newslettertemp.
                    $discussionspostedin = newslettertemp_discussions_user_has_posted_in($newslettertemp->id, $user->id);
                    if (!empty($discussionspostedin)) {
                        $newslettertemponlydiscussions = array();  // Holds discussion ids for the discussions the user is allowed to see in this newslettertemp.
                        foreach ($discussionspostedin as $d) {
                            $newslettertemponlydiscussions[] = $d->id;
                        }
                        list($discussionid_sql, $discussionid_params) = $DB->get_in_or_equal($newslettertemponlydiscussions, SQL_PARAMS_NAMED, 'qanda'.$newslettertempid.'_');
                        $newslettertempsearchparams = array_merge($newslettertempsearchparams, $discussionid_params);
                        $newslettertempsearchselect[] = "(d.id $discussionid_sql OR p.parent = 0)";
                    } else {
                        $newslettertempsearchselect[] = "p.parent = 0";
                    }

                }

                if (count($newslettertempsearchselect) > 0) {
                    $newslettertempsearchwhere[] = "(d.newslettertemp = :newslettertemp{$newslettertempid} AND ".implode(" AND ", $newslettertempsearchselect).")";
                    $newslettertempsearchparams['newslettertemp'.$newslettertempid] = $newslettertempid;
                } else {
                    $newslettertempsearchfullaccess[] = $newslettertempid;
                }
            } else {
                // The current user/parent can see all of their own posts
                $newslettertempsearchfullaccess[] = $newslettertempid;
            }
        }
    }

    // If we dont have any search conditions, and we don't have any newslettertemps where
    // the user has full access then we just return the default.
    if (empty($newslettertempsearchwhere) && empty($newslettertempsearchfullaccess)) {
        return $return;
    }

    // Prepare a where condition for the full access newslettertemps.
    if (count($newslettertempsearchfullaccess) > 0) {
        list($fullidsql, $fullidparams) = $DB->get_in_or_equal($newslettertempsearchfullaccess, SQL_PARAMS_NAMED, 'fula');
        $newslettertempsearchparams = array_merge($newslettertempsearchparams, $fullidparams);
        $newslettertempsearchwhere[] = "(d.newslettertemp $fullidsql)";
    }

    // Prepare SQL to both count and search.
    // We alias user.id to useridx because we newslettertemp_posts already has a userid field and not aliasing this would break
    // oracle and mssql.
    $userfields = user_picture::fields('u', null, 'useridx');
    $countsql = 'SELECT COUNT(*) ';
    $selectsql = 'SELECT p.*, d.newslettertemp, d.name AS discussionname, '.$userfields.' ';
    $wheresql = implode(" OR ", $newslettertempsearchwhere);

    if ($discussionsonly) {
        if ($wheresql == '') {
            $wheresql = 'p.parent = 0';
        } else {
            $wheresql = 'p.parent = 0 AND ('.$wheresql.')';
        }
    }

    $sql = "FROM {newslettertemp_posts} p
            JOIN {newslettertemp_discussions} d ON d.id = p.discussion
            JOIN {user} u ON u.id = p.userid
           WHERE ($wheresql)
             AND p.userid = :userid ";
    $orderby = "ORDER BY p.modified DESC";
    $newslettertempsearchparams['userid'] = $user->id;

    // Set the total number posts made by the requested user that the current user can see
    $return->totalcount = $DB->count_records_sql($countsql.$sql, $newslettertempsearchparams);
    // Set the collection of posts that has been requested
    $return->posts = $DB->get_records_sql($selectsql.$sql.$orderby, $newslettertempsearchparams, $limitfrom, $limitnum);

    // We need to build an array of newslettertemps for which posts will be displayed.
    // We do this here to save the caller needing to retrieve them themselves before
    // printing these newslettertemps posts. Given we have the newslettertemps already there is
    // practically no overhead here.
    foreach ($return->posts as $post) {
        if (!array_key_exists($post->newslettertemp, $return->newslettertemps)) {
            $return->newslettertemps[$post->newslettertemp] = $newslettertemps[$post->newslettertemp];
        }
    }

    return $return;
}
