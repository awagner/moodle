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

/*
 * @package    atto_blockquote
 * @copyright  2018 Andreas Wagner, SYNERGY LEARNING
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * @module moodle-atto_blockquote-button
 */

/**
 * Atto text editor blockquote plugin.
 *
 * @namespace M.atto_blockquote
 * @class button
 * @extends M.editor_atto.EditorPlugin
 */

Y.namespace('M.atto_blockquote').Button = Y.Base.create('button', Y.M.editor_atto.EditorPlugin, [], {
    /**
     * A reference to the current selection
     *
     * @property _currentSelection
     * @type Range
     * @private
     */
    _currentSelection: null,
    initializer: function() {
        this.addButton({
            icon: 'e/cite',
            title: 'quote',
            callback: this._addBlockQuote
        });

        this._setupKeyListener();
    },
    /**
     * Surround the selection with blockquotes.
     *
     * @method _addBlockQuote
     * @private
     */
    _addBlockQuote: function() {

        var host = this.get('host');

        // Store the current selection.
        this._currentSelection = host.getSelection();
        if (this._currentSelection === false) {
            return;
        }

        // Keep that values to restore cursor position later.
        var orgstartnode = this._currentSelection[0].startContainer;
        var orgstartoffset = this._currentSelection[0].startOffset;

        var startcontainer = Y.one(this._currentSelection[0].startContainer);
        var endcontainer = Y.one(this._currentSelection[0].endContainer);

        // Check, if there is a surrounding p tag within the editor, if yes include it in selection.
        var blockparent = startcontainer.ancestor('p');
        if (blockparent) {
            // Test wether we are still within the editor.
            if (host.editor.contains(blockparent)) {
                startcontainer = blockparent;
            }
        }

        // If the startcontainer is the host. The set the startcontainer to the most common
        // child.
        if (host.editor == startcontainer) {
            startcontainer = host.editor.one(':first-child');
            if (!startcontainer) {
                return;
            }
        }
        if (host.editor == endcontainer) {
            endcontainer = host.editor.one(':last-child');
            if (!endcontainer) {
                return;
            }
        }

        if (!host.editor.contains(startcontainer)) {
            return;
        }

        if (!host.editor.contains(endcontainer)) {
            return;
        }

        this._currentSelection[0].setStartBefore(startcontainer.getDOMNode());
        this._currentSelection[0].setEndAfter(endcontainer.getDOMNode());

        // Wrap the content into a blockquote tag.
        var df = this._currentSelection[0].extractContents();
        var blocknode = Y.Node.create('<blockquote></blockquote>');
        var selectednode = host.insertContentAtFocusPoint(blocknode.get('outerHTML'));
        selectednode.getDOMNode().appendChild(df);

        // Restore the cursor position.
        window.rangy.getSelection().collapse(orgstartnode, orgstartoffset);

        // Clean empty blockquotes, necessary for some browsers,
        // when selection contains other blockquotes.
        host.editor.all('blockquote').each(function(bqchild) {
            if (!this._containsText(bqchild)) {
                bqchild.remove();
            }
        }, this);

    },
    /**
     * Listen to the key event, to control the insert of paragraphs within a
     * blockquote content.
     *
     * @method _setupKeyListener
     * @private
     */
    _setupKeyListener: function() {

        this.editor.on('key', function(e) {

            // Return, when user is only inserting a new line.
            if (e.shiftKey) {
                return;
            }

            var host = this.get('host');
            this._currentSelection = host.getSelection();

            var parentnode = this.get('host').getSelectionParentNode();
            if (this.editor == parentnode) {
                return;
            }

            // Check blockquote.
            var blockquote = Y.one(this._currentSelection[0].startContainer);

            // Search the next blockquote ancestor within the editor.
            if (blockquote.get('nodeName') != 'BLOCKQUOTE') {
                blockquote = blockquote.ancestor('blockquote');
            }

            if (!blockquote) {
                return;
            }

            e.preventDefault();

            // Insert a span to mark split point.
            var marker = Y.Node.create('<span></span>');
            marker.addClass('splitmarker');
            host.insertContentAtFocusPoint(marker.get('outerHTML'));

            // Clone blockquote.
            var blockquote2 = blockquote.cloneNode(true);

            // Get all parent nodes of marker within blockquote
            // and delete previous childs.
            var currentancestor = blockquote2.one('span.splitmarker');
            while (currentancestor != blockquote2) {

                var previous = currentancestor.getDOMNode().previousSibling;
                while (previous) {
                    previous.parentNode.removeChild(previous);
                    previous = currentancestor.getDOMNode().previousSibling;
                }

                currentancestor = currentancestor.ancestor();
            }

            // Get all parent nodes of marker within blockquote
            // and delete next childs.
            currentancestor = blockquote.one('span.splitmarker');
            while (currentancestor != blockquote) {

                var next = currentancestor.getDOMNode().nextSibling;
                while (next) {
                    next.parentNode.removeChild(next);
                    next = currentancestor.getDOMNode().nextSibling;
                }

                currentancestor = currentancestor.ancestor();
            }

            blockquote.one('span.splitmarker').remove();
            blockquote2.one('span.splitmarker').remove();

            var p = Y.Node.create('<p>&nbsp;</p>');
            blockquote.insert(p, 'after');

            p.insert(blockquote2, 'after');
            var sel = host.getSelectionFromNode(p);
            sel[0].collapse(true);
            host.setSelection(sel);
            p.focus();

            // Remove empty blockquotes.
            if (!this._containsText(blockquote)) {
                blockquote.remove();
            }

            if (!this._containsText(blockquote2)) {
                blockquote2.remove();
            }

        }, 'enter', this);
    },
    /**
     * Check whether the node cotains at least one non empty textnode.
     *
     * @method _containsText
     * @private
     *
     * @param {Node} node
     * @returns {boolean}
     */
    _containsText: function(node) {

        if (!node.hasChildNodes()) {
            if (node.get('nodeName') != '#text') {
                return false;
            }
            var text = node.get('nodeValue');
            if (!text) {
                return false;
            }
            text = text.trim();
            return (text.length > 0);
        }

        var contains = false;
        node.get('childNodes').each(function(child) {
            if (this._containsText(child)) {
                contains = true;
            }
        }, this);
        return contains;
    }

});
