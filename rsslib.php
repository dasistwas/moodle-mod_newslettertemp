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
 * This file adds support to rss feeds generation
 *
 * @package mod_newslettertemp
 * @category rss
 * @copyright 2001 Eloy Lafuente (stronk7) http://contiento.com
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Returns the path to the cached rss feed contents. Creates/updates the cache if necessary.
 * @param stdClass $context the context
 * @param array    $args    the arguments received in the url
 * @return string the full path to the cached RSS feed directory. Null if there is a problem.
 */
function newslettertemp_rss_get_feed($context, $args) {
    global $CFG, $DB, $USER;

    $status = true;

    //are RSS feeds enabled?
    if (empty($CFG->newslettertemp_enablerssfeeds)) {
        debugging('DISABLED (module configuration)');
        return null;
    }

    $newslettertempid  = clean_param($args[3], PARAM_INT);
    $cm = get_coursemodule_from_instance('newslettertemp', $newslettertempid, 0, false, MUST_EXIST);
    $modcontext = context_module::instance($cm->id);

    //context id from db should match the submitted one
    if ($context->id != $modcontext->id || !has_capability('mod/newslettertemp:viewdiscussion', $modcontext)) {
        return null;
    }

    $newslettertemp = $DB->get_record('newslettertemp', array('id' => $newslettertempid), '*', MUST_EXIST);
    if (!rss_enabled_for_mod('newslettertemp', $newslettertemp)) {
        return null;
    }

    //the sql that will retreive the data for the feed and be hashed to get the cache filename
    $sql = newslettertemp_rss_get_sql($newslettertemp, $cm);

    // Hash the sql to get the cache file name.
    // If the newslettertemp is Q and A then we need to cache the files per user. This can
    // have a large impact on performance, so we want to only do it on this type of newslettertemp.
    if ($newslettertemp->type == 'qanda') {
        $filename = rss_get_file_name($newslettertemp, $sql . $USER->id);
    } else {
        $filename = rss_get_file_name($newslettertemp, $sql);
    }
    $cachedfilepath = rss_get_file_full_name('mod_newslettertemp', $filename);

    //Is the cache out of date?
    $cachedfilelastmodified = 0;
    if (file_exists($cachedfilepath)) {
        $cachedfilelastmodified = filemtime($cachedfilepath);
    }
    //if the cache is more than 60 seconds old and there's new stuff
    $dontrecheckcutoff = time()-60;
    if ( $dontrecheckcutoff > $cachedfilelastmodified && newslettertemp_rss_newstuff($newslettertemp, $cm, $cachedfilelastmodified)) {
        //need to regenerate the cached version
        $result = newslettertemp_rss_feed_contents($newslettertemp, $sql, $modcontext);
        if (!empty($result)) {
            $status = rss_save_file('mod_newslettertemp',$filename,$result);
        }
    }

    //return the path to the cached version
    return $cachedfilepath;
}

/**
 * Given a newslettertemp object, deletes all cached RSS files associated with it.
 *
 * @param stdClass $newslettertemp
 */
function newslettertemp_rss_delete_file($newslettertemp) {
    rss_delete_file('mod_newslettertemp', $newslettertemp);
}

///////////////////////////////////////////////////////
//Utility functions

/**
 * If there is new stuff in the newslettertemp since $time this returns true
 * Otherwise it returns false.
 *
 * @param stdClass $newslettertemp the newslettertemp object
 * @param stdClass $cm    Course Module object
 * @param int      $time  check for items since this epoch timestamp
 * @return bool True for new items
 */
function newslettertemp_rss_newstuff($newslettertemp, $cm, $time) {
    global $DB;

    $sql = newslettertemp_rss_get_sql($newslettertemp, $cm, $time);

    $recs = $DB->get_records_sql($sql, null, 0, 1);//limit of 1. If we get even 1 back we have new stuff
    return ($recs && !empty($recs));
}

/**
 * Determines which type of SQL query is required, one for posts or one for discussions, and returns the appropriate query
 *
 * @param stdClass $newslettertemp the newslettertemp object
 * @param stdClass $cm    Course Module object
 * @param int      $time  check for items since this epoch timestamp
 * @return string the SQL query to be used to get the Discussion/Post details from the newslettertemp table of the database
 */
function newslettertemp_rss_get_sql($newslettertemp, $cm, $time=0) {
    $sql = null;

    if (!empty($newslettertemp->rsstype)) {
        if ($newslettertemp->rsstype == 1) {    //Discussion RSS
            $sql = newslettertemp_rss_feed_discussions_sql($newslettertemp, $cm, $time);
        } else {                //Post RSS
            $sql = newslettertemp_rss_feed_posts_sql($newslettertemp, $cm, $time);
        }
    }

    return $sql;
}

/**
 * Generates the SQL query used to get the Discussion details from the newslettertemp table of the database
 *
 * @param stdClass $newslettertemp     the newslettertemp object
 * @param stdClass $cm        Course Module object
 * @param int      $newsince  check for items since this epoch timestamp
 * @return string the SQL query to be used to get the Discussion details from the newslettertemp table of the database
 */
function newslettertemp_rss_feed_discussions_sql($newslettertemp, $cm, $newsince=0) {
    global $CFG, $DB, $USER;

    $timelimit = '';

    $modcontext = null;

    $now = round(time(), -2);
    $params = array($cm->instance);

    $modcontext = context_module::instance($cm->id);

    if (!empty($CFG->newslettertemp_enabletimedposts)) { /// Users must fulfill timed posts
        if (!has_capability('mod/newslettertemp:viewhiddentimedposts', $modcontext)) {
            $timelimit = " AND ((d.timestart <= :now1 AND (d.timeend = 0 OR d.timeend > :now2))";
            $params['now1'] = $now;
            $params['now2'] = $now;
            if (isloggedin()) {
                $timelimit .= " OR d.userid = :userid";
                $params['userid'] = $USER->id;
            }
            $timelimit .= ")";
        }
    }

    //do we only want new posts?
    if ($newsince) {
        $newsince = " AND p.modified > '$newsince'";
    } else {
        $newsince = '';
    }

    //get group enforcing SQL
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);
    $groupselect = newslettertemp_rss_get_group_sql($cm, $groupmode, $currentgroup, $modcontext);

    if ($groupmode && $currentgroup) {
        $params['groupid'] = $currentgroup;
    }

    $newslettertempsort = "d.timemodified DESC";
    $postdata = "p.id AS postid, p.subject, p.created as postcreated, p.modified, p.discussion, p.userid, p.message as postmessage, p.messageformat AS postformat, p.messagetrust AS posttrust";

    $sql = "SELECT $postdata, d.id as discussionid, d.name as discussionname, d.timemodified, d.usermodified, d.groupid, d.timestart, d.timeend,
                   u.firstname as userfirstname, u.lastname as userlastname, u.email, u.picture, u.imagealt
              FROM {newslettertemp_discussions} d
                   JOIN {newslettertemp_posts} p ON p.discussion = d.id
                   JOIN {user} u ON p.userid = u.id
             WHERE d.newslettertemp = {$newslettertemp->id} AND p.parent = 0
                   $timelimit $groupselect $newsince
          ORDER BY $newslettertempsort";
    return $sql;
}

/**
 * Generates the SQL query used to get the Post details from the newslettertemp table of the database
 *
 * @param stdClass $newslettertemp     the newslettertemp object
 * @param stdClass $cm        Course Module object
 * @param int      $newsince  check for items since this epoch timestamp
 * @return string the SQL query to be used to get the Post details from the newslettertemp table of the database
 */
function newslettertemp_rss_feed_posts_sql($newslettertemp, $cm, $newsince=0) {
    $modcontext = context_module::instance($cm->id);

    //get group enforcement SQL
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    $groupselect = newslettertemp_rss_get_group_sql($cm, $groupmode, $currentgroup, $modcontext);

    if ($groupmode && $currentgroup) {
        $params['groupid'] = $currentgroup;
    }

    //do we only want new posts?
    if ($newsince) {
        $newsince = " AND p.modified > '$newsince'";
    } else {
        $newsince = '';
    }

    $sql = "SELECT p.id AS postid,
                 d.id AS discussionid,
                 d.name AS discussionname,
                 u.id AS userid,
                 u.firstname AS userfirstname,
                 u.lastname AS userlastname,
                 p.subject AS postsubject,
                 p.message AS postmessage,
                 p.created AS postcreated,
                 p.messageformat AS postformat,
                 p.messagetrust AS posttrust
            FROM {newslettertemp_discussions} d,
               {newslettertemp_posts} p,
               {user} u
            WHERE d.newslettertemp = {$newslettertemp->id} AND
                p.discussion = d.id AND
                u.id = p.userid $newsince
                $groupselect
            ORDER BY p.created desc";

    return $sql;
}

/**
 * Retrieve the correct SQL snippet for group-only newslettertemps
 *
 * @param stdClass $cm           Course Module object
 * @param int      $groupmode    the mode in which the newslettertemp's groups are operating
 * @param bool     $currentgroup true if the user is from the a group enabled on the newslettertemp
 * @param stdClass $modcontext   The context instance of the newslettertemp module
 * @return string SQL Query for group details of the newslettertemp
 */
function newslettertemp_rss_get_group_sql($cm, $groupmode, $currentgroup, $modcontext=null) {
    $groupselect = '';

    if ($groupmode) {
        if ($groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = :groupid OR d.groupid = -1)";
                $params['groupid'] = $currentgroup;
            }
        } else {
            //seprate groups without access all
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = :groupid OR d.groupid = -1)";
                $params['groupid'] = $currentgroup;
            } else {
                $groupselect = "AND d.groupid = -1";
            }
        }
    }

    return $groupselect;
}

/**
 * This function return the XML rss contents about the newslettertemp
 * It returns false if something is wrong
 *
 * @param stdClass $newslettertemp the newslettertemp object
 * @param string   $sql   The SQL used to retrieve the contents from the database
 * @param object $context the context this newslettertemp relates to
 * @return bool|string false if the contents is empty, otherwise the contents of the feed is returned
 *
 * @Todo MDL-31129 implement post attachment handling
 */

function newslettertemp_rss_feed_contents($newslettertemp, $sql) {
    global $CFG, $DB, $USER;


    $status = true;

    $params = array();
    //$params['newslettertempid'] = $newslettertemp->id;
    $recs = $DB->get_recordset_sql($sql, $params, 0, $newslettertemp->rssarticles);

    //set a flag. Are we displaying discussions or posts?
    $isdiscussion = true;
    if (!empty($newslettertemp->rsstype) && $newslettertemp->rsstype!=1) {
        $isdiscussion = false;
    }

    if (!$cm = get_coursemodule_from_instance('newslettertemp', $newslettertemp->id, $newslettertemp->course)) {
        print_error('invalidcoursemodule');
    }
    $context = context_module::instance($cm->id);

    $formatoptions = new stdClass();
    $items = array();
    foreach ($recs as $rec) {
            $item = new stdClass();
            $user = new stdClass();

            if ($isdiscussion && !newslettertemp_user_can_see_discussion($newslettertemp, $rec->discussionid, $context)) {
                // This is a discussion which the user has no permission to view
                $item->title = get_string('newslettertempsubjecthidden', 'newslettertemp');
                $message = get_string('newslettertempbodyhidden', 'newslettertemp');
                $item->author = get_string('newslettertempauthorhidden', 'newslettertemp');
            } else if (!$isdiscussion && !newslettertemp_user_can_see_post($newslettertemp, $rec->discussionid, $rec->postid, $USER, $cm)) {
                // This is a post which the user has no permission to view
                $item->title = get_string('newslettertempsubjecthidden', 'newslettertemp');
                $message = get_string('newslettertempbodyhidden', 'newslettertemp');
                $item->author = get_string('newslettertempauthorhidden', 'newslettertemp');
            } else {
                // The user must have permission to view
                if ($isdiscussion && !empty($rec->discussionname)) {
                    $item->title = format_string($rec->discussionname);
                } else if (!empty($rec->postsubject)) {
                    $item->title = format_string($rec->postsubject);
                } else {
                    //we should have an item title by now but if we dont somehow then substitute something somewhat meaningful
                    $item->title = format_string($newslettertemp->name.' '.userdate($rec->postcreated,get_string('strftimedatetimeshort', 'langconfig')));
                }
                $user->firstname = $rec->userfirstname;
                $user->lastname = $rec->userlastname;
                $item->author = fullname($user);
                $message = file_rewrite_pluginfile_urls($rec->postmessage, 'pluginfile.php', $context->id,
                        'mod_newslettertemp', 'post', $rec->postid);
                $formatoptions->trusted = $rec->posttrust;
            }

            if ($isdiscussion) {
                $item->link = $CFG->wwwroot."/mod/newslettertemp/discuss.php?d=".$rec->discussionid;
            } else {
                $item->link = $CFG->wwwroot."/mod/newslettertemp/discuss.php?d=".$rec->discussionid."&parent=".$rec->postid;
            }

            $formatoptions->trusted = $rec->posttrust;
            $item->description = format_text($message, $rec->postformat, $formatoptions, $newslettertemp->course);

            //TODO: MDL-31129 implement post attachment handling
            /*if (!$isdiscussion) {
                $post_file_area_name = str_replace('//', '/', "$newslettertemp->course/$CFG->moddata/newslettertemp/$newslettertemp->id/$rec->postid");
                $post_files = get_directory_list("$CFG->dataroot/$post_file_area_name");

                if (!empty($post_files)) {
                    $item->attachments = array();
                }
            }*/
            $item->pubdate = $rec->postcreated;

            $items[] = $item;
        }
    $recs->close();


    if (!empty($items)) {
        //First the RSS header
        $header = rss_standard_header(strip_tags(format_string($newslettertemp->name,true)),
                                      $CFG->wwwroot."/mod/newslettertemp/view.php?f=".$newslettertemp->id,
                                      format_string($newslettertemp->intro,true)); // TODO: fix format
        //Now all the rss items
        if (!empty($header)) {
            $articles = rss_add_items($items);
        }
        //Now the RSS footer
        if (!empty($header) && !empty($articles)) {
            $footer = rss_standard_footer();
        }
        //Now, if everything is ok, concatenate it
        if (!empty($header) && !empty($articles) && !empty($footer)) {
            $status = $header.$articles.$footer;
        } else {
            $status = false;
        }
    } else {
        $status = false;
    }

    return $status;
}
