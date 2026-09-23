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
 * Tests for the Panopto Tiny Editor embeds filter.
 *
 * @package    filter_panoptoltibutton
 * @copyright  2026 Panopto
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \filter_panoptoltibutton\text_filter
 */
final class text_filter_test extends \advanced_testcase {
    /**
     * Build a marker link as saved by the editor integration.
     *
     * @param array $params Launch parameter overrides.
     * @param string $text Link text.
     * @return string
     */
    private function marker(array $params = [], string $text = 'Panopto video'): string {
        $url = new url('/lib/editor/tiny/plugins/panoptoltibutton/view.php', $params + [
            'course' => 2,
            'ltitypeid' => 3,
            'resourcelinkid' => 'resource_123',
            'custom' => json_encode(['video' => 'abc']),
        ]);

        return html_writer::link($url, $text, ['class' => 'panopto-embed']);
    }

    /**
     * Get a filter for which every course uses Panopto LTI tool 3.
     *
     * @param \core\context|null $context Filter context, the system context by default.
     * @return text_filter
     */
    private function get_filter(?\core\context $context = null): text_filter {
        return new class($context ?? \core\context\system::instance(), []) extends text_filter {
            #[\Override]
            protected function get_course_tool_id(int $courseid): int {
                return 3;
            }
        };
    }

    /**
     * Markers are only expanded after the text has been cleaned.
     */
    public function test_marker_is_only_expanded_post_clean(): void {
        $marker = $this->marker();
        $filter = $this->get_filter();

        $this->assertSame($marker, $filter->filter_stage_pre_clean($marker, []));
        $this->assertSame($marker, $filter->filter_stage_string($marker, []));
        $this->assertStringContainsString('<iframe ', $filter->filter_stage_post_clean($marker, []));
    }

    /**
     * Each marker keeps its own launch parameters, dimensions and title.
     */
    public function test_multiple_markers_are_expanded(): void {
        $first = $this->marker(['resourcelinkid' => 'first', 'displaywidth' => '640', 'displayheight' => '360'], '');
        $second = $this->marker(['resourcelinkid' => 'second'], 'Q&amp;A session');

        $result = $this->get_filter()->filter($first . '<p>Between</p>' . $second);

        $this->assertSame(2, substr_count($result, '<iframe '));
        $this->assertStringContainsString('</iframe><p>Between</p><iframe ', $result);
        $this->assertStringContainsString('resourcelinkid=first', $result);
        $this->assertStringContainsString('resourcelinkid=second', $result);
        $this->assertStringContainsString(' width="640" height="360"', $result);
        $this->assertStringContainsString(' title="Panopto video"', $result);
        $this->assertStringContainsString(' title="Q&amp;A session"', $result);
        $this->assertStringContainsString(' loading="lazy"', $result);
        $this->assertStringNotContainsString('displaywidth=', $result);
        $this->assertStringNotContainsString('displayheight=', $result);
    }

    /**
     * The launch uses the course the content is displayed in.
     */
    public function test_launch_uses_display_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $filter = $this->get_filter(\core\context\course::instance($course->id));

        $result = $filter->filter($this->marker(['course' => $course->id + 1]));

        $this->assertStringContainsString('view.php?course=' . $course->id . '&amp;ltitypeid=3&amp;', $result);
    }

    /**
     * Empty custom data, as written by tiny_panoptoltibutton for some videos, is accepted.
     */
    public function test_empty_custom_parameter_is_accepted(): void {
        $result = $this->get_filter()->filter($this->marker(['custom' => '']));

        $this->assertStringContainsString('<iframe ', $result);
        $this->assertStringNotContainsString('custom=', $result);
    }

    /**
     * Markers for an LTI tool other than the course Panopto tool are not expanded.
     */
    public function test_other_lti_tool_is_not_expanded(): void {
        $marker = $this->marker(['ltitypeid' => 4]);

        $this->assertSame($marker, $this->get_filter()->filter($marker));
    }

    /**
     * External, malformed and incomplete markers are not expanded.
     */
    public function test_invalid_markers_are_not_expanded(): void {
        global $CFG;

        $launchurl = $CFG->wwwroot . '/lib/editor/tiny/plugins/panoptoltibutton/view.php';
        $markers = [
            'external' => '<a class="panopto-embed" href="https://example.com/lib/editor/tiny/plugins/panoptoltibutton/view.php'
                . '?ltitypeid=3&amp;resourcelinkid=abc">Video</a>',
            'other page' => '<a class="panopto-embed" href="' . $CFG->wwwroot . '/course/view.php'
                . '?ltitypeid=3&amp;resourcelinkid=abc">Video</a>',
            'array parameter' => '<a class="panopto-embed" href="' . $launchurl
                . '?ltitypeid[]=3&amp;resourcelinkid=abc">Video</a>',
            'malformed custom' => $this->marker(['custom' => 'not-json']),
            'invalid resource link' => $this->marker(['resourcelinkid' => 'abc def']),
            'missing resource link' => $this->marker(['resourcelinkid' => '']),
        ];
        $filter = $this->get_filter();

        foreach ($markers as $name => $marker) {
            $this->assertSame($marker, $filter->filter($marker), $name);
        }
    }

    /**
     * Moodle sites installed in a subdirectory are supported.
     */
    public function test_wwwroot_subdirectory_is_supported(): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->wwwroot = 'https://moodle.example.com/learning';

        $result = $this->get_filter()->filter($this->marker());

        $this->assertStringContainsString(
            'src="https://moodle.example.com/learning/lib/editor/tiny/plugins/panoptoltibutton/view.php?',
            $result
        );
    }

    /**
     * Markers stay links on the assignment grading table.
     */
    public function test_assignment_grading_page_is_skipped(): void {
        global $PAGE;

        $this->resetAfterTest();
        $PAGE->set_pagetype('mod-assign-grading');
        $marker = $this->marker();

        $this->assertSame($marker, $this->get_filter()->filter($marker));
    }
}
