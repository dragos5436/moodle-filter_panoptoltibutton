# Panopto Tiny Editor embeds filter

Moodle removes iframes from content written by users without trusted text
permissions, such as students' assignment online text submissions and forum
posts. This filter lets Panopto videos inserted with the Panopto Tiny Editor
button (`tiny_panoptoltibutton`) display in that content.

When the content is saved, Panopto videos are stored as links, with the video
title as the link text. The filter turns them back into videos after Moodle has
cleaned the content. Only links to this site's Panopto launch page that use the
course's Panopto LTI tool are converted.

On the assignment Submissions page, videos are shown as links unless
**Embed videos on the Submissions page** is enabled in the filter settings.

## Requirements

- Moodle 4.5
- `tiny_panoptoltibutton` (and its dependency `block_panopto`)

## Installation

1. Copy this folder to `[moodle]/filter/panoptoltibutton`.
2. Visit Site administration > Notifications to install the plugin.
3. Go to Site administration > Plugins > Filters > Manage filters and set
   **Panopto Tiny Editor embeds** to **On**.

Videos that Moodle removed before this filter was enabled cannot be recovered
and must be inserted again. Videos saved before version 1.3 of this filter are
shown with the title "Panopto video"; insert them again to show their title.
