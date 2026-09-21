# Panopto Tiny Editor embeds filter

This is the companion Moodle text filter for
`tiny_panoptoltibutton`.

No changes to the Panopto Tiny Editor plugin are required. When this filter is
active, its editor integration converts only local Panopto `view.php` iframes
to ordinary `panopto-embed` links as Tiny serializes content. These links
survive Moodle HTML purification and editor preparation. They are shown as
iframes again inside Tiny, so the editing experience remains unchanged.

Copy this directory to:

```text
<moodle-root>/filter/panoptoltibutton/
```

Complete the Moodle plugin upgrade, then enable **Panopto Tiny Editor embeds**
under **Site administration > Plugins > Filters > Manage filters**.
Set it to **On** at site level, then purge all caches so Moodle serves the new
AMD module.

At render time, the filter runs only after Moodle HTML purification. It recognizes only
`panopto-embed` markers whose links point to this Moodle site's
`lib/editor/tiny/plugins/panoptoltibutton/view.php` endpoint, validates the
required launch parameters and configured Panopto course tool, and converts
those markers into Panopto iframes.
It does not allow arbitrary iframe HTML or external iframe URLs.

Existing content from which Moodle has already removed an iframe cannot be
recovered. Open the editor and insert those Panopto videos again after this
filter version is installed.

## Verification

After inserting a video and submitting the form, inspect the stored HTML. It
should contain an anchor similar to:

```html
<a class="panopto-embed" href="https://moodle.example/.../view.php?...">Panopto video</a>
```

Rendered output should contain the corresponding local `view.php` iframe.
