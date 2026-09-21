<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace filter_panoptoltibutton;

/**
 * Tests for the Panopto marker filter.
 *
 * @package    filter_panoptoltibutton
 * @copyright  2026 Panopto
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \filter_panoptoltibutton\text_filter
 */
final class text_filter_test extends \advanced_testcase {
    /**
     * Build a valid marker.
     *
     * @param array $parameters Query parameter overrides.
     * @param string $label Marker label.
     * @return string Marker HTML.
     */
    private function marker(array $parameters = [], string $label = 'Panopto video'): string {
        $parameters += [
            'course' => 2,
            'ltitypeid' => 3,
            'resourcelinkid' => 'resource_123',
            'custom' => json_encode(['video' => 'abc']),
        ];
        $url = new \moodle_url('/lib/editor/tiny/plugins/panoptoltibutton/view.php', $parameters);

        return \html_writer::link($url, $label, ['class' => 'panopto-embed']);
    }

    /**
     * Return the filter under test.
     *
     * @return text_filter
     */
    private function get_filter(): text_filter {
        return new class(\context_system::instance(), []) extends text_filter {
            /**
             * Isolate marker tests from Panopto block database configuration.
             *
             * @param int $courseid Moodle course ID.
             * @param int $ltitypeid LTI type ID.
             * @return bool
             */
            protected function is_expected_panopto_tool(int $courseid, int $ltitypeid): bool {
                return true;
            }
        };
    }

    /**
     * The marker must not expand before HTML purification.
     */
    public function test_marker_is_only_expanded_post_clean(): void {
        $marker = $this->marker();
        $filter = $this->get_filter();

        $this->assertSame($marker, $filter->filter($marker, ['stage' => 'pre_clean']));
        $this->assertStringContainsString('<iframe ', $filter->filter($marker, ['stage' => 'post_clean']));
    }

    /**
     * Multiple markers retain their own launch details and dimensions.
     */
    public function test_multiple_markers_are_expanded(): void {
        $first = $this->marker([
            'resourcelinkid' => 'first',
            'displaywidth' => '640',
            'displayheight' => '360',
        ]);
        $second = $this->marker(['resourcelinkid' => 'second'], 'Second video');

        $result = $this->get_filter()->filter($first . '<p>Between</p>' . $second, ['stage' => 'post_clean']);

        $this->assertSame(2, substr_count($result, '<iframe '));
        $this->assertStringContainsString('resourcelinkid=first', $result);
        $this->assertStringContainsString('resourcelinkid=second', $result);
        $this->assertStringContainsString(' width="640" height="360"', $result);
        $this->assertStringNotContainsString('displaywidth=', $result);
        $this->assertStringNotContainsString('displayheight=', $result);
    }

    /**
     * Empty custom data emitted by the original Tiny plugin is valid.
     */
    public function test_empty_custom_parameter_is_accepted(): void {
        $result = $this->get_filter()->filter($this->marker(['custom' => '']), ['stage' => 'post_clean']);

        $this->assertStringContainsString('<iframe ', $result);
    }

    /**
     * A marker for an LTI tool other than the course Panopto tool remains inert.
     */
    public function test_unexpected_lti_tool_is_not_expanded(): void {
        $filter = new class(\context_system::instance(), []) extends text_filter {
            /**
             * Reject the test tool.
             *
             * @param int $courseid Moodle course ID.
             * @param int $ltitypeid LTI type ID.
             * @return bool
             */
            protected function is_expected_panopto_tool(int $courseid, int $ltitypeid): bool {
                return false;
            }
        };
        $marker = $this->marker();

        $this->assertSame($marker, $filter->filter($marker, ['stage' => 'post_clean']));
    }

    /**
     * External, malformed, and incomplete markers remain inert links.
     */
    public function test_invalid_markers_are_not_expanded(): void {
        global $CFG;

        $validurl = (new \moodle_url('/lib/editor/tiny/plugins/panoptoltibutton/view.php', [
            'course' => 2,
            'ltitypeid' => 3,
            'resourcelinkid' => 'resource_123',
            'custom' => '{}',
        ]))->out(false);
        $invalidmarkers = [
            '<a class="panopto-embed" href="https://example.invalid/view.php?course=2">External</a>',
            '<a class="panopto-embed" href="' . s(str_replace('custom=%7B%7D', 'custom=not-json', $validurl))
                . '">Malformed</a>',
            '<a class="panopto-embed" href="' . s($CFG->wwwroot
                . '/lib/editor/tiny/plugins/panoptoltibutton/view.php?course[]=2&amp;ltitypeid=3'
                . '&amp;resourcelinkid=resource_123') . '">Array</a>',
        ];
        $filter = $this->get_filter();

        foreach ($invalidmarkers as $marker) {
            $this->assertSame($marker, $filter->filter($marker, ['stage' => 'post_clean']));
        }
    }

    /**
     * Moodle installations below a URL path are supported.
     */
    public function test_wwwroot_subdirectory_is_supported(): void {
        global $CFG;

        $oldwwwroot = $CFG->wwwroot;
        $CFG->wwwroot = 'https://moodle.example.test/learning';
        try {
            $result = $this->get_filter()->filter($this->marker(), ['stage' => 'post_clean']);
            $this->assertStringContainsString('<iframe ', $result);
            $this->assertStringContainsString('/learning/lib/editor/tiny/plugins/panoptoltibutton/view.php', $result);
        } finally {
            $CFG->wwwroot = $oldwwwroot;
        }
    }
}
