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

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/newslettertemp/lib.php');
require_once($CFG->libdir . '/rsslib.php');

$id = optional_param('id', 0, PARAM_INT);                   // Course id
$subscribe = optional_param('subscribe', null, PARAM_INT);  // Subscribe/Unsubscribe all newslettertemps

$url = new moodle_url('/mod/newslettertemp/index.php', array('id'=>$id));
if ($subscribe !== null) {
    require_sesskey();
    $url->param('subscribe', $subscribe);
}
$PAGE->set_url($url);

if ($id) {
    if (! $course = $DB->get_record('course', array('id' => $id))) {
        print_error('invalidcourseid');
    }
} else {
    $course = get_site();
}

require_course_login($course);
$PAGE->set_pagelayout('incourse');
$coursecontext = context_course::instance($course->id);


unset($SESSION->fromdiscussion);

add_to_log($course->id, 'newslettertemp', 'view newslettertemps', "index.php?id=$course->id");

$strnewslettertemps       = get_string('newslettertemps', 'newslettertemp');
$strnewslettertemp        = get_string('newslettertemp', 'newslettertemp');
$strdescription  = get_string('description');
$strdiscussions  = get_string('discussions', 'newslettertemp');
$strsubscribed   = get_string('subscribed', 'newslettertemp');
$strunreadposts  = get_string('unreadposts', 'newslettertemp');
$strtracking     = get_string('tracking', 'newslettertemp');
$strmarkallread  = get_string('markallread', 'newslettertemp');
$strtracknewslettertemp   = get_string('tracknewslettertemp', 'newslettertemp');
$strnotracknewslettertemp = get_string('notracknewslettertemp', 'newslettertemp');
$strsubscribe    = get_string('subscribe', 'newslettertemp');
$strunsubscribe  = get_string('unsubscribe', 'newslettertemp');
$stryes          = get_string('yes');
$strno           = get_string('no');
$strrss          = get_string('rss');

$searchform = newslettertemp_search_form($course);


// Start of the table for General Forums

$generaltable = new html_table();
$generaltable->head  = array ($strnewslettertemp, $strdescription, $strdiscussions);
$generaltable->align = array ('left', 'left', 'center');

if ($usetracking = newslettertemp_tp_can_track_newslettertemps()) {
    $untracked = newslettertemp_tp_get_untracked_newslettertemps($USER->id, $course->id);

    $generaltable->head[] = $strunreadposts;
    $generaltable->align[] = 'center';

    $generaltable->head[] = $strtracking;
    $generaltable->align[] = 'center';
}

$subscribed_newslettertemps = newslettertemp_get_subscribed_newslettertemps($course);

$can_subscribe = is_enrolled($coursecontext);
if ($can_subscribe) {
    $generaltable->head[] = $strsubscribed;
    $generaltable->align[] = 'center';
}

if ($show_rss = (($can_subscribe || $course->id == SITEID) &&
                 isset($CFG->enablerssfeeds) && isset($CFG->newslettertemp_enablerssfeeds) &&
                 $CFG->enablerssfeeds && $CFG->newslettertemp_enablerssfeeds)) {
    $generaltable->head[] = $strrss;
    $generaltable->align[] = 'center';
}

$usesections = course_format_uses_sections($course->format);

$table = new html_table();

// Parse and organise all the newslettertemps.  Most newslettertemps are course modules but
// some special ones are not.  These get placed in the general newslettertemps
// category with the newslettertemps in section 0.

$newslettertemps = $DB->get_records('newslettertemp', array('course' => $course->id));

$generalnewslettertemps  = array();
$learningnewslettertemps = array();
$modinfo = get_fast_modinfo($course);

if (!isset($modinfo->instances['newslettertemp'])) {
    $modinfo->instances['newslettertemp'] = array();
}

foreach ($modinfo->instances['newslettertemp'] as $newslettertempid=>$cm) {
    if (!$cm->uservisible or !isset($newslettertemps[$newslettertempid])) {
        continue;
    }

    $newslettertemp = $newslettertemps[$newslettertempid];

    if (!$context = context_module::instance($cm->id, IGNORE_MISSING)) {
        continue;   // Shouldn't happen
    }

    if (!has_capability('mod/newslettertemp:viewdiscussion', $context)) {
        continue;
    }

    // fill two type array - order in modinfo is the same as in course
    if ($newslettertemp->type == 'news' or $newslettertemp->type == 'social') {
        $generalnewslettertemps[$newslettertemp->id] = $newslettertemp;

    } else if ($course->id == SITEID or empty($cm->sectionnum)) {
        $generalnewslettertemps[$newslettertemp->id] = $newslettertemp;

    } else {
        $learningnewslettertemps[$newslettertemp->id] = $newslettertemp;
    }
}

// Do course wide subscribe/unsubscribe if requested
if (!is_null($subscribe)) {
    if (isguestuser() or !$can_subscribe) {
        // there should not be any links leading to this place, just redirect
        redirect(new moodle_url('/mod/newslettertemp/index.php', array('id' => $id)), get_string('subscribeenrolledonly', 'newslettertemp'));
    }
    // Can proceed now, the user is not guest and is enrolled
    foreach ($modinfo->instances['newslettertemp'] as $newslettertempid=>$cm) {
        $newslettertemp = $newslettertemps[$newslettertempid];
        $modcontext = context_module::instance($cm->id);
        $cansub = false;

        if (has_capability('mod/newslettertemp:viewdiscussion', $modcontext)) {
            $cansub = true;
        }
        if ($cansub && $cm->visible == 0 &&
            !has_capability('mod/newslettertemp:managesubscriptions', $modcontext))
        {
            $cansub = false;
        }
        if (!newslettertemp_is_forcesubscribed($newslettertemp)) {
            $subscribed = newslettertemp_is_subscribed($USER->id, $newslettertemp);
            if ((has_capability('moodle/course:manageactivities', $coursecontext, $USER->id) || $newslettertemp->forcesubscribe != NEWSLETTERTEMP_DISALLOWSUBSCRIBE) && $subscribe && !$subscribed && $cansub) {
                newslettertemp_subscribe($USER->id, $newslettertempid);
            } else if (!$subscribe && $subscribed) {
                newslettertemp_unsubscribe($USER->id, $newslettertempid);
            }
        }
    }
    $returnto = newslettertemp_go_back_to("index.php?id=$course->id");
    $shortname = format_string($course->shortname, true, array('context' => context_course::instance($course->id)));
    if ($subscribe) {
        add_to_log($course->id, 'newslettertemp', 'subscribeall', "index.php?id=$course->id", $course->id);
        redirect($returnto, get_string('nowallsubscribed', 'newslettertemp', $shortname), 1);
    } else {
        add_to_log($course->id, 'newslettertemp', 'unsubscribeall', "index.php?id=$course->id", $course->id);
        redirect($returnto, get_string('nowallunsubscribed', 'newslettertemp', $shortname), 1);
    }
}

/// First, let's process the general newslettertemps and build up a display

if ($generalnewslettertemps) {
    foreach ($generalnewslettertemps as $newslettertemp) {
        $cm      = $modinfo->instances['newslettertemp'][$newslettertemp->id];
        $context = context_module::instance($cm->id);

        $count = newslettertemp_count_discussions($newslettertemp, $cm, $course);

        if ($usetracking) {
            if ($newslettertemp->trackingtype == NEWSLETTERTEMP_TRACKING_OFF) {
                $unreadlink  = '-';
                $trackedlink = '-';

            } else {
                if (isset($untracked[$newslettertemp->id])) {
                        $unreadlink  = '-';
                } else if ($unread = newslettertemp_tp_count_newslettertemp_unread_posts($cm, $course)) {
                        $unreadlink = '<span class="unread"><a href="view.php?f='.$newslettertemp->id.'">'.$unread.'</a>';
                    $unreadlink .= '<a title="'.$strmarkallread.'" href="markposts.php?f='.
                                   $newslettertemp->id.'&amp;mark=read"><img src="'.$OUTPUT->pix_url('t/markasread') . '" alt="'.$strmarkallread.'" class="iconsmall" /></a></span>';
                } else {
                    $unreadlink = '<span class="read">0</span>';
                }

                if ($newslettertemp->trackingtype == NEWSLETTERTEMP_TRACKING_ON) {
                    $trackedlink = $stryes;

                } else {
                    $aurl = new moodle_url('/mod/newslettertemp/settracking.php', array('id'=>$newslettertemp->id));
                    if (!isset($untracked[$newslettertemp->id])) {
                        $trackedlink = $OUTPUT->single_button($aurl, $stryes, 'post', array('title'=>$strnotracknewslettertemp));
                    } else {
                        $trackedlink = $OUTPUT->single_button($aurl, $strno, 'post', array('title'=>$strtracknewslettertemp));
                    }
                }
            }
        }

        $newslettertemp->intro = shorten_text(format_module_intro('newslettertemp', $newslettertemp, $cm->id), $CFG->newslettertemp_shortpost);
        $newslettertempname = format_string($newslettertemp->name, true);;

        if ($cm->visible) {
            $style = '';
        } else {
            $style = 'class="dimmed"';
        }
        $newslettertemplink = "<a href=\"view.php?f=$newslettertemp->id\" $style>".format_string($newslettertemp->name,true)."</a>";
        $discussionlink = "<a href=\"view.php?f=$newslettertemp->id\" $style>".$count."</a>";

        $row = array ($newslettertemplink, $newslettertemp->intro, $discussionlink);
        if ($usetracking) {
            $row[] = $unreadlink;
            $row[] = $trackedlink;    // Tracking.
        }

        if ($can_subscribe) {
            if ($newslettertemp->forcesubscribe != NEWSLETTERTEMP_DISALLOWSUBSCRIBE) {
                $row[] = newslettertemp_get_subscribe_link($newslettertemp, $context, array('subscribed' => $stryes,
                        'unsubscribed' => $strno, 'forcesubscribed' => $stryes,
                        'cantsubscribe' => '-'), false, false, true, $subscribed_newslettertemps);
            } else {
                $row[] = '-';
            }
        }

        //If this newslettertemp has RSS activated, calculate it
        if ($show_rss) {
            if ($newslettertemp->rsstype and $newslettertemp->rssarticles) {
                //Calculate the tooltip text
                if ($newslettertemp->rsstype == 1) {
                    $tooltiptext = get_string('rsssubscriberssdiscussions', 'newslettertemp');
                } else {
                    $tooltiptext = get_string('rsssubscriberssposts', 'newslettertemp');
                }

                if (!isloggedin() && $course->id == SITEID) {
                    $userid = guest_user()->id;
                } else {
                    $userid = $USER->id;
                }
                //Get html code for RSS link
                $row[] = rss_get_link($context->id, $userid, 'mod_newslettertemp', $newslettertemp->id, $tooltiptext);
            } else {
                $row[] = '&nbsp;';
            }
        }

        $generaltable->data[] = $row;
    }
}


// Start of the table for Learning Forums
$learningtable = new html_table();
$learningtable->head  = array ($strnewslettertemp, $strdescription, $strdiscussions);
$learningtable->align = array ('left', 'left', 'center');

if ($usetracking) {
    $learningtable->head[] = $strunreadposts;
    $learningtable->align[] = 'center';

    $learningtable->head[] = $strtracking;
    $learningtable->align[] = 'center';
}

if ($can_subscribe) {
    $learningtable->head[] = $strsubscribed;
    $learningtable->align[] = 'center';
}

if ($show_rss = (($can_subscribe || $course->id == SITEID) &&
                 isset($CFG->enablerssfeeds) && isset($CFG->newslettertemp_enablerssfeeds) &&
                 $CFG->enablerssfeeds && $CFG->newslettertemp_enablerssfeeds)) {
    $learningtable->head[] = $strrss;
    $learningtable->align[] = 'center';
}

/// Now let's process the learning newslettertemps

if ($course->id != SITEID) {    // Only real courses have learning newslettertemps
    // 'format_.'$course->format only applicable when not SITEID (format_site is not a format)
    $strsectionname  = get_string('sectionname', 'format_'.$course->format);
    // Add extra field for section number, at the front
    array_unshift($learningtable->head, $strsectionname);
    array_unshift($learningtable->align, 'center');


    if ($learningnewslettertemps) {
        $currentsection = '';
            foreach ($learningnewslettertemps as $newslettertemp) {
            $cm      = $modinfo->instances['newslettertemp'][$newslettertemp->id];
            $context = context_module::instance($cm->id);

            $count = newslettertemp_count_discussions($newslettertemp, $cm, $course);

            if ($usetracking) {
                if ($newslettertemp->trackingtype == NEWSLETTERTEMP_TRACKING_OFF) {
                    $unreadlink  = '-';
                    $trackedlink = '-';

                } else {
                    if (isset($untracked[$newslettertemp->id])) {
                        $unreadlink  = '-';
                    } else if ($unread = newslettertemp_tp_count_newslettertemp_unread_posts($cm, $course)) {
                        $unreadlink = '<span class="unread"><a href="view.php?f='.$newslettertemp->id.'">'.$unread.'</a>';
                        $unreadlink .= '<a title="'.$strmarkallread.'" href="markposts.php?f='.
                                       $newslettertemp->id.'&amp;mark=read"><img src="'.$OUTPUT->pix_url('t/markasread') . '" alt="'.$strmarkallread.'" class="iconsmall" /></a></span>';
                    } else {
                        $unreadlink = '<span class="read">0</span>';
                    }

                    if ($newslettertemp->trackingtype == NEWSLETTERTEMP_TRACKING_ON) {
                        $trackedlink = $stryes;

                    } else {
                        $aurl = new moodle_url('/mod/newslettertemp/settracking.php', array('id'=>$newslettertemp->id));
                        if (!isset($untracked[$newslettertemp->id])) {
                            $trackedlink = $OUTPUT->single_button($aurl, $stryes, 'post', array('title'=>$strnotracknewslettertemp));
                        } else {
                            $trackedlink = $OUTPUT->single_button($aurl, $strno, 'post', array('title'=>$strtracknewslettertemp));
                        }
                    }
                }
            }

            $newslettertemp->intro = shorten_text(format_module_intro('newslettertemp', $newslettertemp, $cm->id), $CFG->newslettertemp_shortpost);

            if ($cm->sectionnum != $currentsection) {
                $printsection = get_section_name($course, $cm->sectionnum);
                if ($currentsection) {
                    $learningtable->data[] = 'hr';
                }
                $currentsection = $cm->sectionnum;
            } else {
                $printsection = '';
            }

            $newslettertempname = format_string($newslettertemp->name,true);;

            if ($cm->visible) {
                $style = '';
            } else {
                $style = 'class="dimmed"';
            }
            $newslettertemplink = "<a href=\"view.php?f=$newslettertemp->id\" $style>".format_string($newslettertemp->name,true)."</a>";
            $discussionlink = "<a href=\"view.php?f=$newslettertemp->id\" $style>".$count."</a>";

            $row = array ($printsection, $newslettertemplink, $newslettertemp->intro, $discussionlink);
            if ($usetracking) {
                $row[] = $unreadlink;
                $row[] = $trackedlink;    // Tracking.
            }

            if ($can_subscribe) {
                if ($newslettertemp->forcesubscribe != NEWSLETTERTEMP_DISALLOWSUBSCRIBE) {
                    $row[] = newslettertemp_get_subscribe_link($newslettertemp, $context, array('subscribed' => $stryes,
                        'unsubscribed' => $strno, 'forcesubscribed' => $stryes,
                        'cantsubscribe' => '-'), false, false, true, $subscribed_newslettertemps);
                } else {
                    $row[] = '-';
                }
            }

            //If this newslettertemp has RSS activated, calculate it
            if ($show_rss) {
                if ($newslettertemp->rsstype and $newslettertemp->rssarticles) {
                    //Calculate the tolltip text
                    if ($newslettertemp->rsstype == 1) {
                        $tooltiptext = get_string('rsssubscriberssdiscussions', 'newslettertemp');
                    } else {
                        $tooltiptext = get_string('rsssubscriberssposts', 'newslettertemp');
                    }
                    //Get html code for RSS link
                    $row[] = rss_get_link($context->id, $USER->id, 'mod_newslettertemp', $newslettertemp->id, $tooltiptext);
                } else {
                    $row[] = '&nbsp;';
                }
            }

            $learningtable->data[] = $row;
        }
    }
}


/// Output the page
$PAGE->navbar->add($strnewslettertemps);
$PAGE->set_title("$course->shortname: $strnewslettertemps");
$PAGE->set_heading($course->fullname);
$PAGE->set_button($searchform);
echo $OUTPUT->header();

// Show the subscribe all options only to non-guest, enrolled users
if (!isguestuser() && isloggedin() && $can_subscribe) {
    echo $OUTPUT->box_start('subscription');
    echo html_writer::tag('div',
        html_writer::link(new moodle_url('/mod/newslettertemp/index.php', array('id'=>$course->id, 'subscribe'=>1, 'sesskey'=>sesskey())),
            get_string('allsubscribe', 'newslettertemp')),
        array('class'=>'helplink'));
    echo html_writer::tag('div',
        html_writer::link(new moodle_url('/mod/newslettertemp/index.php', array('id'=>$course->id, 'subscribe'=>0, 'sesskey'=>sesskey())),
            get_string('allunsubscribe', 'newslettertemp')),
        array('class'=>'helplink'));
    echo $OUTPUT->box_end();
    echo $OUTPUT->box('&nbsp;', 'clearer');
}

if ($generalnewslettertemps) {
    echo $OUTPUT->heading(get_string('generalnewslettertemps', 'newslettertemp'));
    echo html_writer::table($generaltable);
}

if ($learningnewslettertemps) {
    echo $OUTPUT->heading(get_string('learningnewslettertemps', 'newslettertemp'));
    echo html_writer::table($learningtable);
}

echo $OUTPUT->footer();

