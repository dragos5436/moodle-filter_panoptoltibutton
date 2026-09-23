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
 * Saves Panopto iframes in Tiny content as marker links, which Moodle does not remove when cleaning HTML.
 *
 * @module     filter_panoptoltibutton/editor
 * @copyright  2026 Panopto
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Config from 'core/config';

const markerClass = 'panopto-embed';
const toolOption = 'tiny_panoptoltibutton/plugin:tool';
const dimensions = {width: 'displaywidth', height: 'displayheight'};
const dimensionPattern = /^[1-9][0-9]{0,3}$/;

let launchUrl;
let defaultTitle;

/**
 * Parse a URL, returning it only if it points to the Panopto launch page of this site.
 *
 * @param {string} value
 * @returns {URL|null}
 */
const getLaunchUrl = (value) => {
    try {
        const url = new URL(value, launchUrl);
        return url.origin === launchUrl.origin && url.pathname === launchUrl.pathname ? url : null;
    } catch (error) {
        return null;
    }
};

/**
 * Get the ID of the course Panopto tool that tiny_panoptoltibutton passes to the editor.
 *
 * @param {TinyMCE} editor
 * @returns {string|null}
 */
const getToolId = (editor) => {
    const tool = editor.options.isRegistered(toolOption) ? editor.options.get(toolOption) : null;
    return tool?.id ? String(tool.id) : null;
};

/**
 * Serialise Panopto iframes as marker links.
 *
 * @param {TinyMCE} editor
 */
const addSerializerFilter = (editor) => {
    const {Node} = editor.editorManager.html;

    editor.serializer.addNodeFilter('iframe', (iframes) => iframes.forEach((iframe) => {
        const url = getLaunchUrl(iframe.attr('src'));
        if (!url) {
            return;
        }

        Object.entries(dimensions).forEach(([attribute, parameter]) => {
            if (dimensionPattern.test(iframe.attr(attribute))) {
                url.searchParams.set(parameter, iframe.attr(attribute));
            }
        });

        const marker = Node.create('a', {'class': markerClass, href: url.toString()});
        const text = Node.create('#text');
        text.value = iframe.attr('title') || defaultTitle;
        marker.append(text);
        iframe.replace(marker);
    }));
};

/**
 * Show marker links as iframes while editing, if they launch the course Panopto tool.
 *
 * @param {TinyMCE} editor
 */
const addParserFilter = (editor) => {
    const {Node} = editor.editorManager.html;

    editor.parser.addNodeFilter('a', (links) => {
        const toolId = getToolId(editor);
        links.forEach((link) => {
            const isMarker = (link.attr('class') || '').split(/\s+/).includes(markerClass);
            const url = toolId && isMarker ? getLaunchUrl(link.attr('href')) : null;
            if (!url || url.searchParams.get('ltitypeid') !== toolId) {
                return;
            }

            const title = link.firstChild?.type === 3 ? link.firstChild.value.trim() : '';
            const attributes = {title: title || defaultTitle, allowfullscreen: 'true'};
            Object.entries(dimensions).forEach(([attribute, parameter]) => {
                if (dimensionPattern.test(url.searchParams.get(parameter))) {
                    attributes[attribute] = url.searchParams.get(parameter);
                }
                url.searchParams.delete(parameter);
            });

            link.replace(Node.create('iframe', {src: url.toString(), ...attributes}));
        });
    });
};

/**
 * Add the marker handling to a Tiny editor.
 *
 * @param {TinyMCE} editor
 */
const setupEditor = (editor) => {
    const addFilters = () => {
        addParserFilter(editor);
        addSerializerFilter(editor);
    };

    if (!editor.parser) {
        editor.on('PreInit', addFilters);
        return;
    }

    addFilters();
    if (editor.initialized) {
        // The content was parsed before the filters existed.
        editor.setContent(editor.getContent());
    }
};

/**
 * Add the marker handling to all current and future Tiny editors on the page.
 *
 * @param {string} title Text used for embeds without a title.
 */
export const init = (title) => {
    if (launchUrl) {
        return;
    }
    launchUrl = new URL(`${Config.wwwroot}/lib/editor/tiny/plugins/panoptoltibutton/view.php`);
    defaultTitle = title;

    const setupTiny = () => {
        window.tinymce.on('AddEditor', ({editor}) => setupEditor(editor));
        window.tinymce.get().forEach(setupEditor);
    };

    if (window.tinymce) {
        setupTiny();
        return;
    }

    // Moodle only loads TinyMCE when an editor is created, possibly after this module.
    const onLoad = () => {
        if (window.tinymce) {
            document.removeEventListener('load', onLoad, true);
            setupTiny();
        }
    };
    document.addEventListener('load', onLoad, true);
};
