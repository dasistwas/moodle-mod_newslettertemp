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
 * @copyright  2009 Petr Skoda (http://skodak.org)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {
    require_once($CFG->dirroot.'/mod/newslettertemp/lib.php');

    $settings->add(new admin_setting_configselect('newslettertemp_displaymode', get_string('displaymode', 'newslettertemp'),
                       get_string('configdisplaymode', 'newslettertemp'), NEWSLETTERTEMP_MODE_NESTED, newslettertemp_get_layout_modes()));

    $settings->add(new admin_setting_configcheckbox('newslettertemp_replytouser', get_string('replytouser', 'newslettertemp'),
                       get_string('configreplytouser', 'newslettertemp'), 1));

    // Less non-HTML characters than this is short
    $settings->add(new admin_setting_configtext('newslettertemp_shortpost', get_string('shortpost', 'newslettertemp'),
                       get_string('configshortpost', 'newslettertemp'), 300, PARAM_INT));

    // More non-HTML characters than this is long
    $settings->add(new admin_setting_configtext('newslettertemp_longpost', get_string('longpost', 'newslettertemp'),
                       get_string('configlongpost', 'newslettertemp'), 600, PARAM_INT));

    // Number of discussions on a page
    $settings->add(new admin_setting_configtext('newslettertemp_manydiscussions', get_string('manydiscussions', 'newslettertemp'),
                       get_string('configmanydiscussions', 'newslettertemp'), 100, PARAM_INT));

    if (isset($CFG->maxbytes)) {
        $settings->add(new admin_setting_configselect('newslettertemp_maxbytes', get_string('maxattachmentsize', 'newslettertemp'),
                           get_string('configmaxbytes', 'newslettertemp'), 512000, get_max_upload_sizes($CFG->maxbytes)));
    }

    // Default number of attachments allowed per post in all newslettertemps
    $settings->add(new admin_setting_configtext('newslettertemp_maxattachments', get_string('maxattachments', 'newslettertemp'),
                       get_string('configmaxattachments', 'newslettertemp'), 9, PARAM_INT));

    // Default whether user needs to mark a post as read
    $settings->add(new admin_setting_configcheckbox('newslettertemp_trackreadposts', get_string('tracknewslettertemp', 'newslettertemp'),
                       get_string('configtrackreadposts', 'newslettertemp'), 1));

    // Default number of days that a post is considered old
    $settings->add(new admin_setting_configtext('newslettertemp_oldpostdays', get_string('oldpostdays', 'newslettertemp'),
                       get_string('configoldpostdays', 'newslettertemp'), 14, PARAM_INT));

    // Default whether user needs to mark a post as read
    $settings->add(new admin_setting_configcheckbox('newslettertemp_usermarksread', get_string('usermarksread', 'newslettertemp'),
                       get_string('configusermarksread', 'newslettertemp'), 0));

    $options = array();
    for ($i = 0; $i < 24; $i++) {
        $options[$i] = sprintf("%02d",$i);
    }
    // Default time (hour) to execute 'clean_read_records' cron
    $settings->add(new admin_setting_configselect('newslettertemp_cleanreadtime', get_string('cleanreadtime', 'newslettertemp'),
                       get_string('configcleanreadtime', 'newslettertemp'), 2, $options));

    // Default time (hour) to send digest email
    $settings->add(new admin_setting_configselect('digestmailtime', get_string('digestmailtime', 'newslettertemp'),
                       get_string('configdigestmailtime', 'newslettertemp'), 17, $options));

    if (empty($CFG->enablerssfeeds)) {
        $options = array(0 => get_string('rssglobaldisabled', 'admin'));
        $str = get_string('configenablerssfeeds', 'newslettertemp').'<br />'.get_string('configenablerssfeedsdisabled2', 'admin');

    } else {
        $options = array(0=>get_string('no'), 1=>get_string('yes'));
        $str = get_string('configenablerssfeeds', 'newslettertemp');
    }
    $settings->add(new admin_setting_configselect('newslettertemp_enablerssfeeds', get_string('enablerssfeeds', 'admin'),
                       $str, 0, $options));

    $settings->add(new admin_setting_configcheckbox('newslettertemp_enabletimedposts', get_string('timedposts', 'newslettertemp'),
                       get_string('configenabletimedposts', 'newslettertemp'), 0));
}

