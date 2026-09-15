# Panopto Tiny Editor embeds filter

This is the companion Moodle text filter for
`tiny_panoptoltibutton`.

Copy this directory to:

```text
<moodle-root>/filter/panoptoltibutton/
```

Complete the Moodle plugin upgrade, then enable **Panopto Tiny Editor embeds**
under **Site administration > Plugins > Filters > Manage filters**.

The filter runs after Moodle HTML purification. It recognizes only
`panopto-embed` markers whose links point to this Moodle site's
`lib/editor/tiny/plugins/panoptoltibutton/view.php` endpoint, validates the
required launch parameters, and converts those markers into Panopto iframes.
It does not allow arbitrary iframe HTML or external iframe URLs.
