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
 * Strings for component 'newslettertemp', language 'en', branch 'MOODLE_20_STABLE'
 *
 * @package   newslettertemp
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['activityoverview'] = 'There are new newsletter posts';
$string['addanewdiscussion'] = 'Add a new discussion topic';
$string['addanewquestion'] = 'Add a new question';
$string['addanewtopic'] = 'Add a new topic';
$string['advancedsearch'] = 'Advanced search';
$string['allnewslettertemps'] = 'All newsletters';
$string['allowdiscussions'] = 'Can a {$a} post to this newsletter?';
$string['allowsallsubscribe'] = 'This newsletter allows everyone to choose whether to subscribe or not';
$string['allowsdiscussions'] = 'This newsletter allows each person to start one discussion topic.';
$string['allsubscribe'] = 'Subscribe to all newsletters';
$string['allunsubscribe'] = 'Unsubscribe from all newsletters';
$string['alreadyfirstpost'] = 'This is already the first post in the discussion';
$string['anyfile'] = 'Any file';
$string['areaattachment'] = 'Attachments';
$string['areapost'] = 'Messages';
$string['attachment'] = 'Attachment';
$string['attachment_help'] = 'You can optionally attach one or more files to a newsletter post. If you attach an image, it will be displayed after the message.';
$string['attachmentnopost'] = 'You cannot export attachments without a post id';
$string['attachments'] = 'Attachments';
$string['blockafter'] = 'Post threshold for blocking';
$string['blockafter_help'] = 'This setting specifies the maximum number of posts which a user can post in the given time period. Users with the capability mod/newslettertemp:postwithoutthrottling are exempt from post limits.';
$string['blockperiod'] = 'Time period for blocking';
$string['blockperiod_help'] = 'Students can be blocked from posting more than a given number of posts in a given time period. Users with the capability mod/newslettertemp:postwithoutthrottling are exempt from post limits.';
$string['blockperioddisabled'] = 'Don\'t block';
$string['blognewslettertemp'] = 'Standard newsletter displayed in a blog-like format';
$string['bynameondate'] = 'by {$a->name} - {$a->date}';
$string['cannotadd'] = 'Could not add the discussion for this newsletter';
$string['cannotadddiscussion'] = 'Adding discussions to this newsletter requires group membership.';
$string['cannotadddiscussionall'] = 'You do not have permission to add a new discussion topic for all participants.';
$string['cannotaddsubscriber'] = 'Could not add subscriber with id {$a} to this newsletter!';
$string['cannotaddteachernewslettertempo'] = 'Could not add converted teacher newsletter instance to section 0 in the course';
$string['cannotcreatediscussion'] = 'Could not create new discussion';
$string['cannotcreateinstanceforteacher'] = 'Could not create new course module instance for the teacher newsletter';
$string['cannotdeletenewslettertempe'] = 'You can not delete the newsletter module.';
$string['cannotdeletepost'] = 'You can\'t delete this post!';
$string['cannoteditposts'] = 'You can\'t edit other people\'s posts!';
$string['cannotfinddiscussion'] = 'Could not find the discussion in this newsletter';
$string['cannotfindfirstpost'] = 'Could not find the first post in this newsletter';
$string['cannotfindorcreatenewslettertemp'] = 'Could not find or create a main news newsletter for the site';
$string['cannotfindparentpost'] = 'Could not find top parent of post {$a}';
$string['cannotmovefromsinglenewslettertemp'] = 'Cannot move discussion from a simple single discussion newsletter';
$string['cannotmovenotvisible'] = 'Newsletter not visible';
$string['cannotmovetonotexist'] = 'You can\'t move to that newsletter - it doesn\'t exist!';
$string['cannotmovetonotfound'] = 'Target newsletter not found in this course.';
$string['cannotmovetosinglenewslettertemp'] = 'Cannot move discussion to a simple single discussion newsletter';
$string['cannotpurgecachedrss'] = 'Could not purge the cached RSS feeds for the source and/or destination newsletter(s) - check your file permissios';
$string['cannotremovesubscriber'] = 'Could not remove subscriber with id {$a} from this newsletter!';
$string['cannotreply'] = 'You cannot reply to this post';
$string['cannotsplit'] = 'Discussions from this newsletter cannot be split';
$string['cannotsubscribe'] = 'Sorry, but you must be a group member to subscribe.';
$string['cannottrack'] = 'Could not stop tracking that newsletter';
$string['cannotunsubscribe'] = 'Could not unsubscribe you from that newsletter';
$string['cannotupdatepost'] = 'You can not update this post';
$string['cannotviewpostyet'] = 'You cannot read other students questions in this discussion yet because you haven\'t posted';
$string['cannotviewusersposts'] = 'There are no posts made by this user that you are able to view.';
$string['cleanreadtime'] = 'Mark old posts as read hour';
$string['completiondiscussions'] = 'Student must create discussions:';
$string['completiondiscussionsgroup'] = 'Require discussions';
$string['completiondiscussionshelp'] = 'requiring discussions to complete';
$string['completionposts'] = 'Student must post discussions or replies:';
$string['completionpostsgroup'] = 'Require posts';
$string['completionpostshelp'] = 'requiring discussions or replies to complete';
$string['completionreplies'] = 'Student must post replies:';
$string['completionrepliesgroup'] = 'Require replies';
$string['completionreplieshelp'] = 'requiring replies to complete';
$string['configcleanreadtime'] = 'The hour of the day to clean old posts from the \'read\' table.';
$string['configdigestmailtime'] = 'People who choose to have emails sent to them in digest form will be emailed the digest daily. This setting controls which time of day the daily mail will be sent (the next cron that runs after this hour will send it).';
$string['configdisplaymode'] = 'The default display mode for discussions if one isn\'t set.';
$string['configenablerssfeeds'] = 'This switch will enable the possibility of RSS feeds for all newsletters.  You will still need to turn feeds on manually in the settings for each newsletter.';
$string['configenabletimedposts'] = 'Set to \'yes\' if you want to allow setting of display periods when posting a new newsletter discussion (Experimental as not yet fully tested)';
$string['configlongpost'] = 'Any post over this length (in characters not including HTML) is considered long. Posts displayed on the site front page, social format course pages, or user profiles are shortened to a natural break somewhere between the newsletter_shortpost and newsletter_longpost values.';
$string['configmanydiscussions'] = 'Maximum number of discussions shown in a newsletter per page';
$string['configmaxattachments'] = 'Default maximum number of attachments allowed per post.';
$string['configmaxbytes'] = 'Default maximum size for all newsletter attachments on the site (subject to course limits and other local settings)';
$string['configoldpostdays'] = 'Number of days old any post is considered read.';
$string['configreplytouser'] = 'When a newsletter post is mailed out, should it contain the user\'s email address so that recipients can reply personally rather than via the newsletter? Even if set to \'Yes\' users can choose in their profile to keep their email address secret.';
$string['configshortpost'] = 'Any post under this length (in characters not including HTML) is considered short (see below).';
$string['configtrackreadposts'] = 'Set to \'yes\' if you want to track read/unread for each user.';
$string['configusermarksread'] = 'If \'yes\', the user must manually mark a post as read. If \'no\', when the post is viewed it is marked as read.';
$string['confirmsubscribe'] = 'Do you really want to subscribe to newsletter \'{$a}\'?';
$string['confirmunsubscribe'] = 'Do you really want to unsubscribe from newsletter \'{$a}\'?';
$string['couldnotadd'] = 'Could not add your post due to an unknown error';
$string['couldnotdeletereplies'] = 'Sorry, that cannot be deleted as people have already responded to it';
$string['couldnotupdate'] = 'Could not update your post due to an unknown error';
$string['delete'] = 'Delete';
$string['deleteddiscussion'] = 'The discussion topic has been deleted';
$string['deletedpost'] = 'The post has been deleted';
$string['deletedposts'] = 'Those posts have been deleted';
$string['deletesure'] = 'Are you sure you want to delete this post?';
$string['deletesureplural'] = 'Are you sure you want to delete this post and all replies? ({$a} posts)';
$string['digestmailheader'] = 'This is your daily digest of new posts from the {$a->sitename} newsletters. To change your newsletter email preferences, go to {$a->userprefs}.';
$string['digestmailprefs'] = 'your user profile';
$string['digestmailsubject'] = '{$a}: newsletter digest';
$string['digestmailtime'] = 'Hour to send digest emails';
$string['digestsentusers'] = 'Email digests successfully sent to {$a} users.';
$string['disallowsubscribe'] = 'Subscriptions not allowed';
$string['disallowsubscribeteacher'] = 'Subscriptions not allowed (except for teachers)';
$string['discussion'] = 'Discussion';
$string['discussionmoved'] = 'This discussion has been moved to \'{$a}\'.';
$string['discussionmovedpost'] = 'This discussion has been moved to <a href="{$a->discusshref}">here</a> in the newsletter <a href="{$a->newslettertemphref}">{$a->newslettertempname}</a>';
$string['discussionname'] = 'Discussion name';
$string['discussions'] = 'Discussions';
$string['discussionsstartedby'] = 'Discussions started by {$a}';
$string['discussionsstartedbyrecent'] = 'Discussions recently started by {$a}';
$string['discussionsstartedbyuserincourse'] = 'Discussions started by {$a->fullname} in {$a->coursename}';
$string['discussthistopic'] = 'Discuss this topic';
$string['displayend'] = 'Display end';
$string['displayend_help'] = 'This setting specifies whether a newsletter post should be hidden after a certain date. Note that administrators can always view newsletter posts.';
$string['displaymode'] = 'Display mode';
$string['displayperiod'] = 'Display period';
$string['displaystart'] = 'Display start';
$string['displaystart_help'] = 'This setting specifies whether a newsletter post should be displayed from a certain date. Note that administrators can always view newsletter posts.';
$string['eachusernewslettertemp'] = 'Each person posts one discussion';
$string['edit'] = 'Edit';
$string['editedby'] = 'Edited by {$a->name} - original submission {$a->date}';
$string['editedpostupdated'] = '{$a}\'s post was updated';
$string['editing'] = 'Editing';
$string['emptymessage'] = 'Something was wrong with your post. Perhaps you left it blank, or the attachment was too big. Your changes have NOT been saved.';
$string['erroremptymessage'] = 'Post message cannot be empty';
$string['erroremptysubject'] = 'Post subject cannot be empty.';
$string['errorenrolmentrequired'] = 'You must be enrolled in this course to access this content';
$string['errorwhiledelete'] = 'An error occurred while deleting record.';
$string['everyonecanchoose'] = 'Everyone can choose to be subscribed';
$string['everyonecannowchoose'] = 'Everyone can now choose to be subscribed';
$string['everyoneisnowsubscribed'] = 'Everyone is now subscribed to this newsletter';
$string['everyoneissubscribed'] = 'Everyone is subscribed to this newsletter';
$string['existingsubscribers'] = 'Existing subscribers';
$string['exportdiscussion'] = 'Export whole discussion';
$string['forcessubscribe'] = 'This newsletter forces everyone to be subscribed';
$string['newslettertemp'] = 'Newsletter';
$string['newslettertemp:addinstance'] = 'Add a new newsletter';
$string['newslettertemp:addnews'] = 'Add news';
$string['newslettertemp:addquestion'] = 'Add question';
$string['newslettertemp:allowforcesubscribe'] = 'Allow force subscribe';
$string['newslettertempauthorhidden'] = 'Author (hidden)';
$string['newslettertempblockingalmosttoomanyposts'] = 'You are approaching the posting threshold. You have posted {$a->numposts} times in the last {$a->blockperiod} and the limit is {$a->blockafter} posts.';
$string['newslettertempbodyhidden'] = 'This post cannot be viewed by you, probably because you have not posted in the discussion, the maximum editing time hasn\'t passed yet, the discussion has not started or the discussion has expired.';
$string['newslettertemp:createattachment'] = 'Create attachments';
$string['newslettertemp:deleteanypost'] = 'Delete any posts (anytime)';
$string['newslettertemp:deleteownpost'] = 'Delete own posts (within deadline)';
$string['newslettertemp:editanypost'] = 'Edit any post';
$string['newslettertemp:exportdiscussion'] = 'Export whole discussion';
$string['newslettertemp:exportownpost'] = 'Export own post';
$string['newslettertemp:exportpost'] = 'Export post';
$string['newslettertempintro'] = 'Newsletter introduction';
$string['newslettertemp:managesubscriptions'] = 'Manage subscriptions';
$string['newslettertemp:movediscussions'] = 'Move discussions';
$string['newslettertemp:postwithoutthrottling'] = 'Exempt from post threshold';
$string['newslettertempname'] = 'Newsletter name';
$string['newslettertempposts'] = 'Newsletter posts';
$string['newslettertemp:rate'] = 'Rate posts';
$string['newslettertemp:replynews'] = 'Reply to news';
$string['newslettertemp:replypost'] = 'Reply to posts';
$string['newslettertemps'] = 'Newsletters';
$string['newslettertemp:splitdiscussions'] = 'Split discussions';
$string['newslettertemp:startdiscussion'] = 'Start new discussions';
$string['newslettertempsubjecthidden'] = 'Subject (hidden)';
$string['newslettertemptracked'] = 'Unread posts are being tracked';
$string['newslettertemptrackednot'] = 'Unread posts are not being tracked';
$string['newslettertemptype'] = 'Newsletter type';
$string['newslettertemptype_help'] = 'There are 5 newsletter types:

* A single simple discussion - A single discussion topic which everyone can reply to
* Each person posts one discussion - Each student can post exactly one new discussion topic, which everyone can then reply to
* Q and A newsletter - Students must first post their perspectives before viewing other students\' posts
* Standard newsletter displayed in a blog-like format - An open newsletter where anyone can start a new discussion at any time, and in which discussion topics are displayed on one page with "Discuss this topic" links
* Standard newsletter for general use - An open newsletter where anyone can start a new discussion at any time';
$string['newslettertemps'] = 'View all raw ratings given by individuals';
$string['newslettertempg'] = 'View total ratings that anyone received';
$string['newslettertempn'] = 'View discussions';
$string['newslettertemps'] = 'View hidden timed posts';
$string['newslettertempg'] = 'Always see Q and A posts';
$string['newslettertempg'] = 'View the total rating you received';
$string['newslettertemps'] = 'View subscribers';
$string['generalnewslettertemp'] = 'Standard newsletter for general use';
$string['generalnewslettertemps'] = 'General newsletters';
$string['innewslettertemp'] = 'in {$a}';
$string['introblog'] = 'The posts in this newsletter were copied here automatically from blogs of users in this course because those blog entries are no longer available';
$string['intronews'] = 'General news and announcements';
$string['introsocial'] = 'An open newsletter for chatting about anything you want to';
$string['introteacher'] = 'A newsletter for teacher-only notes and discussion';
$string['invalidaccess'] = 'This page was not accessed correctly';
$string['invaliddiscussionid'] = 'Discussion ID was incorrect or no longer exists';
$string['invalidforcesubscribe'] = 'Invalid force subscription mode';
$string['invalidnewslettertempd'] = 'Newsletter ID was incorrect';
$string['invalidparentpostid'] = 'Parent post ID was incorrect';
$string['invalidpostid'] = 'Invalid post ID - {$a}';
$string['lastpost'] = 'Last post';
$string['learningnewslettertemps'] = 'Learning newsletters';
$string['longpost'] = 'Long post';
$string['mailnow'] = 'Mail now';
$string['manydiscussions'] = 'Discussions per page';
$string['markalldread'] = 'Mark all posts in this discussion read.';
$string['markallread'] = 'Mark all posts in this newsletter read.';
$string['markread'] = 'Mark read';
$string['markreadbutton'] = 'Mark<br />read';
$string['markunread'] = 'Mark unread';
$string['markunreadbutton'] = 'Mark<br />unread';
$string['maxattachments'] = 'Maximum number of attachments';
$string['maxattachments_help'] = 'This setting specifies the maximum number of files that can be attached to a newsletter post.';
$string['maxattachmentsize'] = 'Maximum attachment size';
$string['maxattachmentsize_help'] = 'This setting specifies the largest size of file that can be attached to a newsletter post.';
$string['maxtimehaspassed'] = 'Sorry, but the maximum time for editing this post ({$a}) has passed!';
$string['message'] = 'Message';
$string['messageprovider:digests'] = 'Subscribed newsletter digests';
$string['messageprovider:posts'] = 'Subscribed newsletter posts';
$string['missingsearchterms'] = 'The following search terms occur only in the HTML markup of this message:';
$string['modeflatnewestfirst'] = 'Display replies flat, with newest first';
$string['modeflatoldestfirst'] = 'Display replies flat, with oldest first';
$string['modenested'] = 'Display replies in nested form';
$string['modethreaded'] = 'Display replies in threaded form';
$string['modulename'] = 'Newsletter';
$string['modulename_help'] = 'The newsletter activity module enables participants to have asynchronous discussions i.e. discussions that take place over an extended period of time.

There are several newsletter types to choose from, such as a standard newsletter where anyone can start a new discussion at any time; a newsletter where each student can post exactly one discussion; or a question and answer newsletter where students must first post before being able to view other students\' posts. A teacher can allow files to be attached to newsletter posts. Attached images are displayed in the newsletter post.

Participants can subscribe to a newsletter to receive notifications of new newsletter posts. A teacher can set the subscription mode to optional, forced or auto, or prevent subscription completely. If required, students can be blocked from posting more than a given number of posts in a given time period; this can prevent individuals from dominating discussions.

Forum posts can be rated by teachers or students (peer evaluation). Ratings can be aggregated to form a final grade which is recorded in the gradebook.

Forums have many uses, such as

* A social space for students to get to know each other
* For course announcements (using a news newsletter with forced subscription)
* For discussing course content or reading materials
* For continuing online an issue raised previously in a face-to-face session
* For teacher-only discussions (using a hidden forum)
* A help centre where tutors and students can give advice
* A one-on-one support area for private student-teacher communications (using a newsletter with separate groups and with one student per group)
* For extension activities, for example ‘brain teasers’ for students to ponder and suggest solutions to';
$string['modulename_link'] = 'mod/newslettertemp/view';
$string['modulenameplural'] = 'Newsletters';
$string['more'] = 'more';
$string['movedmarker'] = '(Moved)';
$string['movethisdiscussionto'] = 'Move this discussion to ...';
$string['mustprovidediscussionorpost'] = 'You must provide either a discussion id or post id to export';
$string['namenews'] = 'News newsletter';
$string['namenews_help'] = 'The news newsletter is a special newsletter for announcements that is automatically created when a course is created. A course can have only one news newsletter. Only teachers and administrators can post in the news newsletter. The "Latest news" block will display recent discussions from the news newsletter.';
$string['namesocial'] = 'Social newsletter';
$string['nameteacher'] = 'Teacher newsletter';
$string['newnewslettertemps'] = 'New newsletter posts';
$string['noattachments'] = 'There are no attachments to this post';
$string['nodiscussions'] = 'There are no discussion topics yet in this newsletter';
$string['nodiscussionsstartedby'] = '{$a} has not started any discussions';
$string['nodiscussionsstartedbyyou'] = 'You haven\'t started any discussions yet';
$string['noguestpost'] = 'Sorry, guests are not allowed to post.';
$string['noguesttracking'] = 'Sorry, guests are not allowed to set tracking options.';
$string['nomorepostscontaining'] = 'No more posts containing \'{$a}\' were found';
$string['nonews'] = 'No news has been posted yet';
$string['noonecansubscribenow'] = 'Subscriptions are now disallowed';
$string['nopermissiontosubscribe'] = 'You do not have the permission to view newsletter subscribers';
$string['nopermissiontoview'] = 'You do not have permissions to view this post';
$string['nopostnewslettertemp'] = 'Sorry, you are not allowed to post to this newsletter';
$string['noposts'] = 'No posts';
$string['nopostsmadebyuser'] = '{$a} has made no posts';
$string['nopostsmadebyyou'] = 'You haven\'t made any posts';
$string['nopostscontaining'] = 'No posts containing \'{$a}\' were found';
$string['noquestions'] = 'There are no questions yet in this newsletter';
$string['nosubscribers'] = 'There are no subscribers yet for this newsletter';
$string['notexists'] = 'Discussion no longer exists';
$string['nothingnew'] = 'Nothing new for {$a}';
$string['notingroup'] = 'Sorry, but you need to be part of a group to see this newsletter.';
$string['notinstalled'] = 'The newsletter module is not installed';
$string['notpartofdiscussion'] = 'This post is not part of a discussion!';
$string['notracknewslettertemp'] = 'Don\'t track unread posts';
$string['noviewdiscussionspermission'] = 'You do not have the permission to view discussions in this newsletter';
$string['nowallsubscribed'] = 'All newsletters in {$a} are subscribed.';
$string['nowallunsubscribed'] = 'All newsletters in {$a} are not subscribed.';
$string['nownotsubscribed'] = '{$a->name} will NOT be notified of new posts in \'{$a->newslettertemp}\'';
$string['nownottracking'] = '{$a->name} is no longer tracking \'{$a->newslettertemp}\'.';
$string['nowsubscribed'] = '{$a->name} will be notified of new posts in \'{$a->newslettertemp}\'';
$string['nowtracking'] = '{$a->name} is now tracking \'{$a->newslettertemp}\'.';
$string['numposts'] = '{$a} posts';
$string['olderdiscussions'] = 'Older discussions';
$string['oldertopics'] = 'Older topics';
$string['oldpostdays'] = 'Read after days';
$string['openmode0'] = 'No discussions, no replies';
$string['openmode1'] = 'No discussions, but replies are allowed';
$string['openmode2'] = 'Discussions and replies are allowed';
$string['overviewnumpostssince'] = '{$a} posts since last login';
$string['overviewnumunread'] = '{$a} total unread';
$string['page-mod-newslettertempx'] = 'Any newsletter module page';
$string['page-mod-newslettertempw'] = 'Newsletter module main page';
$string['page-mod-newslettertemps'] = 'Newsletter module discussion thread page';
$string['parent'] = 'Show parent';
$string['parentofthispost'] = 'Parent of this post';
$string['pluginadministration'] = 'Newsletter administration';
$string['pluginname'] = 'Newsletter';
$string['postadded'] = '<p>Your post was successfully added.</p> <p>You have {$a} to edit it if you want to make any changes.</p>';
$string['postaddedsuccess'] = 'Your post was successfully added.';
$string['postaddedtimeleft'] = 'You have {$a} to edit it if you want to make any changes.';
$string['postincontext'] = 'See this post in context';
$string['postmailinfo'] = 'This is a copy of a message posted on the {$a} website.

To reply click on this link:';
$string['postmailnow'] = '<p>This post will be mailed out immediately to all newsletter subscribers.</p>';
$string['postrating1'] = 'Mostly separate knowing';
$string['postrating2'] = 'Separate and connected';
$string['postrating3'] = 'Mostly connected knowing';
$string['posts'] = 'Posts';
$string['postsmadebyuser'] = 'Posts made by {$a}';
$string['postsmadebyuserincourse'] = 'Posts made by {$a->fullname} in {$a->coursename}';
$string['posttonewslettertemp'] = 'Post to newsletter';
$string['postupdated'] = 'Your post was updated';
$string['potentialsubscribers'] = 'Potential subscribers';
$string['processingdigest'] = 'Processing email digest for user {$a}';
$string['processingpost'] = 'Processing post {$a}';
$string['prune'] = 'Split';
$string['prunedpost'] = 'A new discussion has been created from that post';
$string['pruneheading'] = 'Split the discussion and move this post to a new discussion';
$string['qandanewslettertemp'] = 'Q and A newsletter';
$string['qandanotify'] = 'This is a question and answer newsletter. In order to see other responses to these questions, you must first post your answer';
$string['re'] = 'Re:';
$string['readtherest'] = 'Read the rest of this topic';
$string['replies'] = 'Replies';
$string['repliesmany'] = '{$a} replies so far';
$string['repliesone'] = '{$a} reply so far';
$string['reply'] = 'Reply';
$string['replynewslettertemp'] = 'Reply to newsletter';
$string['replytouser'] = 'Use email address in reply';
$string['resetnewslettertemps'] = 'Delete posts from';
$string['resetnewslettertempl'] = 'Delete all posts';
$string['resetsubscriptions'] = 'Delete all newsletter subscriptions';
$string['resettrackprefs'] = 'Delete all newsletter tracking preferences';
$string['rsssubscriberssdiscussions'] = 'RSS feed of discussions';
$string['rsssubscriberssposts'] = 'RSS feed of posts';
$string['rssarticles'] = 'Number of RSS recent articles';
$string['rssarticles_help'] = 'This setting specifies the number of articles (either discussions or posts) to include in the RSS feed. Between 5 and 20 generally acceptable.';
$string['rsstype'] = 'RSS feed for this activity';
$string['rsstype_help'] = 'To enable the RSS feed for this activity, select either discussions or posts to be included in the feed.';
$string['search'] = 'Search';
$string['searchdatefrom'] = 'Posts must be newer than this';
$string['searchdateto'] = 'Posts must be older than this';
$string['searchnewslettertempo'] = 'Please enter search terms into one or more of the following fields:';
$string['searchnewslettertemps'] = 'Search newsletters';
$string['searchfullwords'] = 'These words should appear as whole words';
$string['searchnotwords'] = 'These words should NOT be included';
$string['searcholderposts'] = 'Search older posts...';
$string['searchphrase'] = 'This exact phrase must appear in the post';
$string['searchresults'] = 'Search results';
$string['searchsubject'] = 'These words should be in the subject';
$string['searchuser'] = 'This name should match the author';
$string['searchuserid'] = 'The Moodle ID of the author';
$string['searchwhichnewslettertemps'] = 'Choose which newsletters to search';
$string['searchwords'] = 'These words can appear anywhere in the post';
$string['seeallposts'] = 'See all posts made by this user';
$string['shortpost'] = 'Short post';
$string['showsubscribers'] = 'Show/edit current subscribers';
$string['singlenewslettertemp'] = 'A single simple discussion';
$string['smallmessage'] = '{$a->user} posted in {$a->newslettertempname}';
$string['startedby'] = 'Started by';
$string['subject'] = 'Subject';
$string['subscribe'] = 'Subscribe to this newsletter';
$string['subscribeall'] = 'Subscribe everyone to this newsletter';
$string['subscribeenrolledonly'] = 'Sorry, only enrolled users are allowed to subscribe to newsletter post notifications.';
$string['subscribed'] = 'Subscribed';
$string['subscribenone'] = 'Unsubscribe everyone from this newsletter';
$string['subscribers'] = 'Subscribers';
$string['subscribersto'] = 'Subscribers to \'{$a}\'';
$string['subscribestart'] = 'Send me email copies of posts to this newsletter';
$string['subscribestop'] = 'I don\'t want email copies of posts to this newsletter';
$string['subscription'] = 'Subscription';
$string['subscription_help'] = 'If you are subscribed to a newsletter it means you will receive email copies of newsletter posts. Usually you can choose whether you wish to be subscribed, though sometimes subscription is forced so that everyone receives email copies of newsletter posts.';
$string['subscriptionmode'] = 'Subscription mode';
$string['subscriptionmode_help'] = 'When a participant is subscribed to a newsletter it means they will receive email copies of newsletter posts.

There are 4 subscription mode options:

* Optional subscription - Participants can choose whether to be subscribed
* Forced subscription - Everyone is subscribed and cannot unsubscribe
* Auto subscription - Everyone is subscribed initially but can choose to unsubscribe at any time
* Subscription disabled - Subscriptions are not allowed';
$string['subscriptionoptional'] = 'Optional subscription';
$string['subscriptionforced'] = 'Forced subscription';
$string['subscriptionauto'] = 'Auto subscription';
$string['subscriptiondisabled'] = 'Subscription disabled';
$string['subscriptions'] = 'Subscriptions';
$string['thisnewslettertempd'] = 'This newsletter has a limit to the number of newsletter postings you can make in a given time period - this is currently set at {$a->blockafter} posting(s) in {$a->blockperiod}';
$string['timedposts'] = 'Timed posts';
$string['timestartenderror'] = 'Display end date cannot be earlier than the start date';
$string['tracknewslettertemp'] = 'Track unread posts';
$string['tracking'] = 'Track';
$string['trackingoff'] = 'Off';
$string['trackingon'] = 'On';
$string['trackingoptional'] = 'Optional';
$string['trackingtype'] = 'Read tracking for this newsletter?';
$string['trackingtype_help'] = 'If enabled, participants can track read and unread messages in the newsletter and in discussions.

There are three options:

* Optional - Participants can choose whether to turn tracking on or off
* On - Tracking is always on
* Off - Tracking is always off';
$string['unread'] = 'Unread';
$string['unreadposts'] = 'Unread posts';
$string['unreadpostsnumber'] = '{$a} unread posts';
$string['unreadpostsone'] = '1 unread post';
$string['unsubscribe'] = 'Unsubscribe from this newsletter';
$string['unsubscribeall'] = 'Unsubscribe from all newsletters';
$string['unsubscribeallconfirm'] = 'You are subscribed to {$a} newsletters now. Do you really want to unsubscribe from all newsletters and disable newsletter auto-subscribe?';
$string['unsubscribealldone'] = 'All optional newsletter subscriptions were removed. You will still receive notifications from newsletters with forced subscription. To manage newsletter notifications go to Messaging in My Profile Settings.';
$string['unsubscribeallempty'] = 'You are not subscribed to any newsletters. To disable all notifications from this server go to Messaging in My Profile Settings.';
$string['unsubscribed'] = 'Unsubscribed';
$string['unsubscribeshort'] = 'Unsubscribe';
$string['usermarksread'] = 'Manual message read marking';
$string['viewalldiscussions'] = 'View all discussions';
$string['warnafter'] = 'Post threshold for warning';
$string['warnafter_help'] = 'Students can be warned as they approach the maximum number of posts allowed in a given period. This setting specifies after how many posts they are warned. Users with the capability mod/newslettertemp:postwithoutthrottling are exempt from post limits.';
$string['warnformorepost'] = 'Warning! There is more than one discussion in this newsletter - using the most recent';
$string['yournewquestion'] = 'Your new question';
$string['yournewtopic'] = 'Your new discussion topic';
$string['yourreply'] = 'Your reply';
