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

namespace filter_panoptoltibutton;

use core\output\html_writer;
use core\url;

/**
 * Turns Panopto embed markers back into LTI launch iframes after Moodle has cleaned the HTML.
 *
 * @package    filter_panoptoltibutton
 * @copyright  2026 Panopto
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_filter extends \core_filters\text_filter {
    /** @var string Launch page of tiny_panoptoltibutton, relative to wwwroot. */
    private const LAUNCH_PATH = '/lib/editor/tiny/plugins/panoptoltibutton/view.php';

    /** @var string Matches marker links written by amd/src/editor.js, capturing the href and the link text. */
    private const MARKER_PATTERN = '~<a\b(?=[^>]*\sclass="[^"]*\bpanopto-embed\b)[^>]*\shref="([^"]+)"[^>]*>(.*?)</a>~is';

    #[\Override]
    public function setup($page, $context) {
        if ($page->requires->should_create_one_time_item_now('filter_panoptoltibutton-editor')) {
            $page->requires->js_call_amd('filter_panoptoltibutton/editor', 'init', [
                get_string('defaulttitle', 'filter_panoptoltibutton'),
            ]);
        }
    }

    #[\Override]
    public function filter($text, array $options = []) {
        if (stripos($text, 'panopto-embed') === false || $this->show_links()) {
            return $text;
        }

        return preg_replace_callback(self::MARKER_PATTERN, [$this, 'replace_marker'], $text) ?? $text;
    }

    #[\Override]
    public function filter_stage_string(string $text, array $options): string {
        return $text;
    }

    /**
     * Whether to show markers as links, which avoids one LTI launch per submission on the assignment Submissions page.
     *
     * @return bool
     */
    private function show_links(): bool {
        global $PAGE;

        return $PAGE->pagetype === 'mod-assign-grading' && !get_config('filter_panoptoltibutton', 'embedsubmissions');
    }

    /**
     * Replace a marker with a launch iframe, or keep it if it is not a valid Panopto embed.
     *
     * @param array $match Match of {@see self::MARKER_PATTERN}.
     * @return string
     */
    private function replace_marker(array $match): string {
        try {
            $marker = new url(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5));
        } catch (\moodle_exception $e) {
            return $match[0];
        }

        $launchurl = new url(self::LAUNCH_PATH);
        $params = array_filter($marker->params(), 'is_string');
        $launchparams = $marker->compare($launchurl, URL_MATCH_BASE) ? $this->get_launch_params($params) : null;
        if ($launchparams === null) {
            return $match[0];
        }

        $launchurl->params($launchparams);
        $title = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5));
        $attributes = [
            'src' => $launchurl,
            'title' => $title !== '' ? $title : get_string('defaulttitle', 'filter_panoptoltibutton'),
            'loading' => 'lazy',
            'allowfullscreen' => 'true',
        ];
        foreach (['width' => 'displaywidth', 'height' => 'displayheight'] as $attribute => $name) {
            if (preg_match('/^[1-9][0-9]{0,3}$/', $params[$name] ?? '')) {
                $attributes[$attribute] = $params[$name];
            }
        }

        return html_writer::tag('iframe', '', $attributes);
    }

    /**
     * Build the launch parameters, allowing only the Panopto tool of the course the content is displayed in.
     *
     * @param string[] $params Marker URL parameters.
     * @return array|null Launch parameters, or null if the marker is not a valid Panopto embed.
     */
    private function get_launch_params(array $params): ?array {
        $resourcelinkid = $params['resourcelinkid'] ?? '';
        $custom = $params['custom'] ?? '';
        if (
            $resourcelinkid === ''
            || clean_param($resourcelinkid, PARAM_ALPHANUMEXT) !== $resourcelinkid
            || ($custom !== '' && !is_array(json_decode($custom, true)))
        ) {
            return null;
        }

        $coursecontext = $this->context->get_course_context(false);
        $courseid = $coursecontext ? (int) $coursecontext->instanceid : SITEID;
        $toolid = $this->get_course_tool_id($courseid);
        if (!$toolid || ($params['ltitypeid'] ?? '') !== (string) $toolid) {
            return null;
        }

        return array_filter([
            'course' => $courseid,
            'ltitypeid' => $toolid,
            'resourcelinkid' => $resourcelinkid,
            'custom' => $custom,
            'contenturl' => $params['contenturl'] ?? '',
        ], fn($value) => $value !== '');
    }

    /**
     * Get the ID of the Panopto LTI tool of a course, as used by tiny_panoptoltibutton.
     *
     * @param int $courseid
     * @return int The tool ID, or 0 if the course has no Panopto tool.
     */
    protected function get_course_tool_id(int $courseid): int {
        global $CFG;
        static $toolids = [];

        if (!isset($toolids[$courseid])) {
            require_once($CFG->dirroot . '/blocks/panopto/lib/lti/panoptoblock_lti_utility.php');
            $toolids[$courseid] = (int) (\panoptoblock_lti_utility::get_course_tool($courseid)->id ?? 0);
        }

        return $toolids[$courseid];
    }
}
