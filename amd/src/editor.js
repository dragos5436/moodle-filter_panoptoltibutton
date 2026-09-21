// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Preserve Panopto Tiny embeds as safe links in stored HTML.
 *
 * @module     filter_panoptoltibutton/editor
 * @copyright  2026 Panopto
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const markerClass = 'panopto-embed';
const editorFlag = 'panoptoMarkerFilterAttached';
const dimensionPattern = /^[1-9][0-9]{0,3}$/;
let siteUrl;
let launchPath;
let tinyListenerAttached = false;
let initialised = false;

/**
 * Return a validated local Panopto launch URL.
 *
 * @param {string} value URL to validate.
 * @returns {URL|null}
 */
const getLaunchUrl = value => {
    let url;
    try {
        url = new URL(value, siteUrl);
    } catch (error) {
        return null;
    }

    if (url.origin !== siteUrl.origin || url.pathname !== launchPath || url.username || url.password) {
        return null;
    }

    const required = {
        course: /^[1-9][0-9]*$/,
        ltitypeid: /^[1-9][0-9]*$/,
        resourcelinkid: /^[a-zA-Z0-9_-]+$/,
    };

    for (const [parameter, pattern] of Object.entries(required)) {
        const values = url.searchParams.getAll(parameter);
        if (values.length !== 1 || !pattern.test(values[0])) {
            return null;
        }
    }

    const customValues = url.searchParams.getAll('custom');
    if (customValues.length > 1) {
        return null;
    }

    if (customValues.length === 1 && customValues[0] !== '') {
        try {
            const custom = JSON.parse(customValues[0]);
            if (custom === null || typeof custom !== 'object') {
                return null;
            }
        } catch (error) {
            return null;
        }
    }

    return url;
};

/**
 * Copy a numeric iframe dimension to the marker URL.
 *
 * @param {HTMLIFrameElement} iframe Source iframe.
 * @param {URL} url Marker URL.
 * @param {string} attribute Iframe attribute.
 * @param {string} parameter URL parameter.
 */
const copyDimension = (iframe, url, attribute, parameter) => {
    const value = iframe.getAttribute(attribute);
    if (value && dimensionPattern.test(value)) {
        url.searchParams.set(parameter, value);
    } else {
        url.searchParams.delete(parameter);
    }
};

/**
 * Convert matching Panopto iframes in serialized editor content to markers.
 *
 * @param {string} content Editor HTML.
 * @returns {string}
 */
const storeMarkers = content => {
    const template = document.createElement('template');
    template.innerHTML = content;

    template.content.querySelectorAll('iframe[src]').forEach(iframe => {
        const url = getLaunchUrl(iframe.getAttribute('src'));
        if (!url) {
            return;
        }

        copyDimension(iframe, url, 'width', 'displaywidth');
        copyDimension(iframe, url, 'height', 'displayheight');

        const marker = document.createElement('a');
        marker.className = markerClass;
        marker.href = url.toString();
        marker.textContent = iframe.getAttribute('title') || 'Panopto video';
        iframe.replaceWith(marker);
    });

    return template.innerHTML;
};

/**
 * Render stored markers as iframes inside an editor.
 *
 * @param {HTMLElement|null} body Tiny editor body.
 */
const renderMarkers = body => {
    if (!body) {
        return;
    }

    body.querySelectorAll(`a.${markerClass}[href]`).forEach(marker => {
        const url = getLaunchUrl(marker.getAttribute('href'));
        if (!url) {
            return;
        }

        const iframe = document.createElement('iframe');
        iframe.src = url.toString();
        iframe.title = marker.textContent.trim() || 'Panopto video';
        iframe.allowFullscreen = true;

        const width = url.searchParams.get('displaywidth');
        const height = url.searchParams.get('displayheight');
        url.searchParams.delete('displaywidth');
        url.searchParams.delete('displayheight');
        iframe.src = url.toString();

        if (width && dimensionPattern.test(width)) {
            iframe.width = width;
        }
        if (height && dimensionPattern.test(height)) {
            iframe.height = height;
        }

        marker.replaceWith(iframe);
    });
};

/**
 * Attach marker handling to one Tiny editor.
 *
 * @param {object} editor Tiny editor instance.
 */
const attachEditor = editor => {
    if (!editor || editor[editorFlag]) {
        return;
    }

    editor[editorFlag] = true;
    editor.on('GetContent', event => {
        if (typeof event.content === 'string') {
            event.content = storeMarkers(event.content);
        }
    });
    editor.on('SetContent', () => renderMarkers(editor.getBody()));
    editor.on('init', () => renderMarkers(editor.getBody()));

    if (editor.initialized) {
        renderMarkers(editor.getBody());
    }
};

/**
 * Attach to the Tiny global and all current editors.
 *
 * @returns {boolean} Whether Tiny was found.
 */
const discoverTiny = () => {
    const tiny = window.tinymce || window.tinyMCE;
    if (!tiny) {
        return false;
    }

    if (!tinyListenerAttached && typeof tiny.on === 'function') {
        tiny.on('AddEditor', event => attachEditor(event.editor));
        tinyListenerAttached = true;
    }

    (tiny.editors || []).forEach(attachEditor);
    return true;
};

/**
 * Initialise the editor integration.
 *
 * @param {object} config Filter configuration.
 */
export const init = config => {
    if (initialised) {
        return;
    }

    try {
        siteUrl = new URL(config.wwwroot);
    } catch (error) {
        window.console.error('Panopto embed filter received an invalid Moodle URL.', error);
        return;
    }

    launchPath = `${siteUrl.pathname.replace(/\/$/, '')}/lib/editor/tiny/plugins/panoptoltibutton/view.php`;
    initialised = true;

    let attempts = 0;
    const findTiny = () => {
        if (discoverTiny() || attempts >= 100) {
            return;
        }
        attempts++;
        window.setTimeout(findTiny, 100);
    };
    findTiny();
};
