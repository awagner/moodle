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
 * Javascript controller for inline editing in forum.
 *
 * @module     mod_forum
 * @copyright  2018 Andreas Wagner.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/notification', 'core/str'], function($, ajax, notification, corestr) {

    var $inlineformdiv = null;
    var $inlineform = null;
    var $discussiondiv = null;
    var $selectedhref = null;
    var $clickedhref = null;

    var discussionid = 0;
    var parentpostid = 0;
    var draftid = 0;
    var submitaction = '';

    /**
     * Submit the form, when user is replying or quoting.
     */
    function onSubmitReply() {

        var valpostid = $('#forum-inlineform input[name="reply"]').val();
        var valsubject = $('#forum-inlineform #id_subject').val();
        var valmessage = $('#forum-inlineform #id_messageeditable').html();

        // Also fill texarea of editor to avoid form_error caused by validate js script.
        $('#forum-inlineform #id_message').html(valmessage);

        ajax.call([
            {
                methodname: 'mod_forum_add_discussion_post',
                args: {
                    postid: valpostid,
                    subject: valsubject,
                    message: valmessage,
                    options: [
                        {
                            name: 'inlineattachmentsid',
                            value: draftid
                        }
                    ],
                },
                done: onClickSubmitDone,
                fail: notification.exception
            }
        ]);
    }

    /**
     * Submit the form, when user is updating a post.
     */
    function onSubmitUpdate() {

        var valpostid = $('#forum-inlineform input[name="edit"]').val();
        var valsubject = $('#forum-inlineform #id_subject').val();
        var valmessage = $('#forum-inlineform #id_messageeditable').html();

        var valoptions = [{name: 'inlineattachmentsid', value: draftid}];
        var valpinned = $('#forum-inlineform #id_pinned').val();
        if (valpinned) {
            valoptions.push({name: 'pinned', value: valpinned});
        }

        // Fill texarea to avoid form_error.
        $('#forum-inlineform #id_message').html(valmessage);

        ajax.call([
            {
                methodname: 'mod_forum_update_discussion_post',
                args: {
                    postid: valpostid,
                    subject: valsubject,
                    message: valmessage,
                    options: valoptions
                },
                done: onClickSubmitDone,
                fail: notification.exception
            }
        ]);
    }

    /**
     * Submit the form via AJAX.
     */
    function onSubmit() {

        if (submitaction === 'reply') {
            onSubmitReply();
        }
        if (submitaction === 'edit') {
            onSubmitUpdate();
        }
    }

    /**
     * Handle AJAX response of a new post.
     *
     * @param {object} response
     */
    function onClickSubmitDone(response) {

        var postid = response.postid;

        // Get post with this postid.
        ajax.call([
            {
                methodname: 'mod_forum_render_forum_discussion',
                args: {
                    postid: response.postid,
                },
                done: function(response) {
                    if (response.status == true) {
                        hideForm();
                        $discussiondiv.html(response.html);
                        // Scroll to current post.
                        var post = $('#p' + postid);
                        var win = $(window);
                        if (post.offset().top > win.scrollTop() + win.height()) {
                                $('html, body').animate({
                                scrollTop: post.offset().top - post.outerHeight()
                                }, 1000);
                            }

                        }
                },
                fail: notification.exception
            }
        ]);
    }

    /**
     * Set focus and cursor to the end of text.
     */
    function setFocusAndCursor() {

        var test = $('#forum-inlineform #id_messageeditable');
        test.focus();

        var range = document.createRange();
        range.selectNodeContents(test[0]);
        range.collapse(false);

        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }

    /**
     * Handle click on reply link.
     *
     * @param {Node} $href
     * @param {String} quote
     */
    function appendReplyForm($href, quote) {

        var parentpostid = Number($href.attr('id').split('-')[2]);

        // Store reply id in form.
        $('#forum-inlineform input[name="reply"]').val(parentpostid);

        // Get this users picture.
        var picnodehtml = $('#forum-inlineform-authorpicture').html();
        $inlineform.find('.picture').html(picnodehtml);

        // Get user autor.
        var authorhtml = $('#forum-inlineform-authorname').html();
        $inlineform.find('#forum-inlineform #id_author').html(authorhtml);

        // Add indent.
        $inlineform.addClass('indent');

        // Mark link.
        if ($selectedhref) {
            $selectedhref.removeClass('forum-link-disable');
        }

        $selectedhref = $href;
        $selectedhref.addClass('forum-link-disable');
        submitaction = 'reply';

        var href = M.cfg.wwwroot + '/mod/forum/post.php?reply=' + parentpostid;
        if (quote) {
            href += '&quote=1';
        }
        corestr.get_string('morereplyingoptions', 'forum').done(function(s) {
            $('#id_morereplyingoptions').html(s);
        });
        $('#id_morereplyingoptions').attr('href', href);

        // Move form under parent post and prepare values.
        $('#forum-inlineform-container-' + parentpostid).append($inlineform);

        // Hide with js because group container has no id.
        if ($('#forum-inlineform-container-' + parentpostid).hasClass('forum-post-hasparent')) {
            $('#forum-inlineform #id_pinned').parentsUntil(".form-group").hide();
        } else {
            $('#forum-inlineform #id_pinned').parentsUntil(".form-group").show();
        }
        setFocusAndCursor();
    }

    /**
     * Prepare form for replying.
     *
     * @param {Node} $href
     */
    function onClickReply($href) {

        parentpostid = Number($href.attr('id').split('-')[2]);

        var subject = $('#p' + parentpostid + ' div.forumpost:first div.subject').html();
        corestr.get_string('re', 'forum').done(function(s) {
            $('#id_subject').val(s + ' ' + subject);
        });
        $('#forum-inlineform #id_messageeditable').html('');

        appendReplyForm($href);
    }

    /**
     * Handle click on reply link.
     *
     * @param {Node} $href
     * @returns {undefined}
     */
    function showUpdateForm($href) {

        // Store reply id in form.
        $('#forum-inlineform input[name="edit"]').val(parentpostid);

        // Get user picture.
        var picnodehtml = $('#p' + parentpostid + ' div.forumpost:first .picture').html();
        $inlineform.find('.picture').html(picnodehtml);

        // Get user autor.
        var authorhtml = $('#p' + parentpostid + ' div.forumpost:first .author').html();
        $inlineform.find('#forum-inlineform #id_author').html(authorhtml);

        // Don't indent.
        $inlineform.removeClass('indent');

        // Mark link.
        if ($selectedhref) {
            $selectedhref.removeClass('forum-link-disable');
        }

        $selectedhref = $href;
        $selectedhref.addClass('forum-link-disable');
        submitaction = 'edit';

        // More options link.
        var href = M.cfg.wwwroot + '/mod/forum/post.php?edit=' + parentpostid;
        corestr.get_string('moreeditingoptions', 'forum').done(function(s) {
            $('#id_morereplyingoptions').html(s);
        });
        $('#id_morereplyingoptions').attr('href', href);

        // Move form under parent post and prepare values.
        $('#forum-inlineform-container-' + parentpostid).append($inlineform);

        // Hide with js because group container has no id.
        if ($('#forum-inlineform-container-' + parentpostid).hasClass('forum-post-hasparent')) {
            $('#forum-inlineform #id_pinned').parentsUntil(".form-group").hide();
        } else {
            $('#forum-inlineform #id_pinned').parentsUntil(".form-group").show();
        }

        // Hide current post.
        $('#p' + parentpostid + ' div.forumpost:first').hide();
    }

    /**
     * Get content and settings for post via AJAX.
     *
     * @param {int} postid
     * @param {function} fnresult
     */
    function getPostInlineEditor(postid, fnresult) {

        // Currently not use ajax, waiting for customers reply.
        ajax.call([
            {
                methodname: 'mod_forum_get_post_inline_editor',
                args: {
                    postid: postid,
                    discussionid: discussionid,
                    draftideditor: draftid
                },
                done: fnresult,
                fail: notification.exception
            }
        ]);
    }

    /**
     * Prepare form for replying with quote.
     *
     * @param {Object} response
     */
    function onClickQuoteWithGetPostEditor(response) {

        $('#p' + parentpostid + ' div.forumpost:first div.subject').html();
        corestr.get_string('re', 'forum').done(function(s) {
            $('#id_subject').val(s + ' ' + response.post.subject);
        });

        $('#p' + parentpostid + ' div.forumpost:first div.content').html();
        $('#id_messageeditable').html('<blockquote>' + response.currenttext + '</blockquote><br/>');
        $('#forum-inlineform #id_message').html('<blockquote>' + response.currenttext + '</blockquote><br/>');

        appendReplyForm($clickedhref, 'quote');
    }

    /**
     * Prepare form for updating a post.
     *
     * @param {Object} response
     */
    function onClickEditWithGetPostEditor(response) {

        $('#id_subject').val(response.post.subject);

        $('#p' + parentpostid + ' div.forumpost:first div.content').html();
        $('#id_messageeditable').html(response.currenttext);
        $('#forum-inlineform #id_message').html(response.currenttext);

        showUpdateForm($clickedhref);
    }

    /**
     * Reset an hide the form.
     */
    function hideForm() {

        if (parentpostid > 0) {
            $('#p' + parentpostid + ' div.forumpost:first').show();
        }
        // Move into hidden container.
        $inlineformdiv.append($inlineform);

        $('#forum-inlineform #id_subject').val('');
        $('#forum-inlineform #id_messageeditable').html('');

        $inlineform.removeClass('indent');
        $selectedhref.removeClass('forum-link-disable');

        $selectedhref = null;
        submitaction = '';
        parentpostid = 0;
    }

    return {
        init: function(initparams) {

            discussionid = initparams.discussionid;
            var sesskey = initparams.sesskey;

            // Get draft id of the editor.
            draftid = $('input[name="message[itemid]"').val();

            // Hidden container to host the inlineform.
            $inlineformdiv = $('#forum-inlineform-container');

            // Wrapper containing the form, picuture and authorname.
            $inlineform = $('#forum-inlineform-wrapper');
            $discussiondiv = $('#forum-discussion-container');

            // Eventhandler.
            var $regionmain = $('#page-mod-forum-discuss #region-main');
            $regionmain.on('click', 'a[id^="forum-reply-"]', function(e) {
                e.preventDefault();
                $clickedhref = $(this);
                if ($clickedhref.hasClass('forum-link-disable')) {
                    return;
                }
                onClickReply($(this));
            });

            $regionmain.on('click', 'a[id^="forum-quote-"]', function(e) {
                e.preventDefault();
                $clickedhref = $(this);
                if ($clickedhref.hasClass('forum-link-disable')) {
                    return;
                }
                parentpostid = Number($clickedhref.attr('id').split('-')[2]);
                getPostInlineEditor(parentpostid, onClickQuoteWithGetPostEditor);
            });

            $regionmain.on('click', 'a[id^="forum-edit-"]', function(e) {
                e.preventDefault();
                $clickedhref = $(this);
                if ($clickedhref.hasClass('forum-link-disable')) {
                    return;
                }
                parentpostid = Number($clickedhref.attr('id').split('-')[2]);
                getPostInlineEditor(parentpostid, onClickEditWithGetPostEditor);
            });

            $inlineform.on('submit', function(e) {
                e.preventDefault();
                return onSubmit($(this));
            });

            $('#forum-inlineform #id_cancel').on('click', function(e) {
                e.preventDefault();
                hideForm();
            });

            // Attach current values to redirect to full edit form.
            $('#id_morereplyingoptions').on('click', function() {

                var href = $(this).attr('href');
                var currenttext = $('#forum-inlineform #id_messageeditable').html();
                var currentsubject = $('#forum-inlineform #id_subject').val();

                href += "&message[itemid]=" + draftid + '&sesskey=' + sesskey
                        + '&inlinemessage=' + currenttext + '&inlinesubject=' + currentsubject;

                $(this).attr('href', encodeURI(href));
            });
        }
    };
});