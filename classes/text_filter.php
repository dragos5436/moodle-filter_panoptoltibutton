<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace filter_panoptoltibutton;

/**
 * Expands validated Panopto editor markers after Moodle has purified HTML.
 *
 * @package    filter_panoptoltibutton
 * @copyright 2026 Panopto
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_filter extends \core_filters\text_filter {
    /**
     * Load the editor integration on pages where this filter is active.
     *
     * @param \moodle_page $page Current page.
     * @param \context $context Current context.
     */
    public function setup($page, $context) {
        global $CFG;

        if (!$page->requires->should_create_one_time_item_now('filter_panoptoltibutton-editor')) {
            return;
        }

        $page->requires->js_call_amd('filter_panoptoltibutton/editor', 'init', [[
            'wwwroot' => $CFG->wwwroot,
        ]]);
    }

    /**
     * Expand Panopto markers in rendered HTML.
     *
     * @param string $text Text to filter.
     * @param array $options Filter options.
     * @return string Filtered text.
     */
    public function filter($text, array $options = []) {
        if ($text === '' || ($options['stage'] ?? '') !== 'post_clean'
                || stripos($text, 'panopto-embed') === false) {
            return $text;
        }

        $pattern = '~<a\b(?=[^>]*\bclass\s*=\s*["\'][^"\']*\bpanopto-embed\b[^"\']*["\'])'
            . '[^>]*\bhref\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</a>~is';

        $result = preg_replace_callback($pattern, [$this, 'replace_marker'], $text);
        return $result === null ? $text : $result;
    }

    /**
     * Replace one marker if its launch URL passes validation.
     *
     * @param array $match Regular expression match.
     * @return string Replacement HTML.
     */
    private function replace_marker(array $match): string {
        $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = parse_url($url);

        if ($parts === false || !$this->is_local_launch_url($parts)) {
            return $match[0];
        }

        parse_str($parts['query'] ?? '', $query);
        if (!$this->has_valid_launch_parameters($query)) {
            return $match[0];
        }

        $allowed = [
            'course' => (string) $query['course'],
            'ltitypeid' => (string) $query['ltitypeid'],
            'resourcelinkid' => (string) $query['resourcelinkid'],
        ];

        if (!empty($query['custom'])) {
            $allowed['custom'] = $query['custom'];
        }

        if (!empty($query['contenturl'])) {
            $allowed['contenturl'] = $query['contenturl'];
        }

        $iframeurl = (new \moodle_url('/lib/editor/tiny/plugins/panoptoltibutton/view.php', $allowed))
            ->out(false);

        $dimensions = '';
        foreach (['displaywidth' => 'width', 'displayheight' => 'height'] as $parameter => $attribute) {
            if (!empty($query[$parameter]) && preg_match('/^[1-9][0-9]{0,3}$/', (string) $query[$parameter])) {
                $dimensions .= ' ' . $attribute . '="' . $query[$parameter] . '"';
            }
        }

        return '<iframe src="' . s($iframeurl) . '"' . $dimensions . ' allowfullscreen="true"></iframe>';
    }

    /**
     * Check that the marker points only to this Moodle site's launch endpoint.
     *
     * @param array $parts Parsed URL.
     * @return bool Whether the URL is local and uses the expected path.
     */
    private function is_local_launch_url(array $parts): bool {
        global $CFG;

        $wwwroot = parse_url($CFG->wwwroot);
        $rootpath = rtrim($wwwroot['path'] ?? '', '/');
        return !empty($parts['host'])
            && !empty($wwwroot['host'])
            && empty($parts['user'])
            && empty($parts['pass'])
            && strcasecmp($parts['scheme'] ?? '', $wwwroot['scheme'] ?? '') === 0
            && strcasecmp($parts['host'], $wwwroot['host']) === 0
            && ($parts['port'] ?? null) === ($wwwroot['port'] ?? null)
            && ($parts['path'] ?? '') === $rootpath . '/lib/editor/tiny/plugins/panoptoltibutton/view.php';
    }

    /**
     * Validate the launch parameters required by view.php.
     *
     * @param array $query Parsed query parameters.
     * @return bool Whether parameters are safe to use.
     */
    private function has_valid_launch_parameters(array $query): bool {
        foreach (['course', 'ltitypeid', 'resourcelinkid'] as $required) {
            if (!isset($query[$required]) || !is_string($query[$required])) {
                return false;
            }
        }

        if (!preg_match('/^[1-9][0-9]*$/', $query['course'])
                || !preg_match('/^[1-9][0-9]*$/', $query['ltitypeid'])
                || !preg_match('/^[a-zA-Z0-9_-]+$/', $query['resourcelinkid'])) {
            return false;
        }

        if (isset($query['custom']) && $query['custom'] !== '') {
            if (!is_string($query['custom'])) {
                return false;
            }

            $decodedcustom = json_decode($query['custom'], true);
            if (!is_array($decodedcustom) || json_last_error() !== JSON_ERROR_NONE) {
                return false;
            }
        }

        if (isset($query['contenturl']) && !is_string($query['contenturl'])) {
            return false;
        }

        return $this->is_expected_panopto_tool((int) $query['course'], (int) $query['ltitypeid']);
    }

    /**
     * Confirm that the LTI type is the Panopto tool configured for the course.
     *
     * @param int $courseid Moodle course ID.
     * @param int $ltitypeid LTI type ID.
     * @return bool Whether the type is the expected Panopto tool.
     */
    protected function is_expected_panopto_tool(int $courseid, int $ltitypeid): bool {
        global $CFG, $DB;

        static $results = [];
        $cachekey = $courseid . ':' . $ltitypeid;
        if (array_key_exists($cachekey, $results)) {
            return $results[$cachekey];
        }

        $utilitypath = $CFG->dirroot . '/blocks/panopto/lib/lti/panoptoblock_lti_utility.php';
        if (!$DB->record_exists('course', ['id' => $courseid]) || !is_readable($utilitypath)) {
            return $results[$cachekey] = false;
        }

        require_once($utilitypath);
        $tool = \panoptoblock_lti_utility::get_course_tool($courseid);

        return $results[$cachekey] = !empty($tool->id) && (int) $tool->id === $ltitypeid;
    }
}

