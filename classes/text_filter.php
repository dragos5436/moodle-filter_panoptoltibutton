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
     * Expand Panopto markers in rendered HTML.
     *
     * @param string $text Text to filter.
     * @param array $options Filter options.
     * @return string Filtered text.
     */
    public function filter($text, array $options = []) {
        if ($text === '' || ($options['stage'] ?? '') !== 'post_clean') {
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

        if (isset($query['custom_b64'])) {
            $allowed['custom_b64'] = $query['custom_b64'];
        } else {
            $allowed['custom'] = $query['custom'];
        }

        if (!empty($query['contenturl'])) {
            $allowed['contenturl'] = $query['contenturl'];
        }

        $iframeurl = (new \moodle_url('/lib/editor/tiny/plugins/panoptoltibutton/view.php', $allowed))
            ->out(false);

        return '<iframe src="' . s($iframeurl) . '" allowfullscreen="true"></iframe>';
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
        return !empty($parts['host'])
            && !empty($wwwroot['host'])
            && strcasecmp($parts['host'], $wwwroot['host']) === 0
            && ($parts['port'] ?? null) === ($wwwroot['port'] ?? null)
            && ($parts['path'] ?? '') === '/lib/editor/tiny/plugins/panoptoltibutton/view.php';
    }

    /**
     * Validate the launch parameters required by view.php.
     *
     * @param array $query Parsed query parameters.
     * @return bool Whether parameters are safe to use.
     */
    private function has_valid_launch_parameters(array $query): bool {
        $hascustom = isset($query['custom_b64']) || isset($query['custom']);
        $validresource = isset($query['resourcelinkid'])
            && preg_match('/^[a-zA-Z0-9_-]+$/', (string) $query['resourcelinkid']);
        $validcustom = !$hascustom || (isset($query['custom_b64'])
            ? preg_match('/^[a-zA-Z0-9_-]+$/', (string) $query['custom_b64'])
            : json_decode((string) $query['custom'], true) !== null);

        return isset($query['course'], $query['ltitypeid'])
            && ctype_digit((string) $query['course'])
            && ctype_digit((string) $query['ltitypeid'])
            && $validresource
            && $hascustom
            && $validcustom;
    }
}

