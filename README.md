# Footprint

> **See what your Joomla extensions really weigh on your site.**

Footprint is an administrator component for Joomla 6 that shows which
extensions, folders and database tables consume the most disk space, files
and rows — with sortable lists, charts and drilldowns.

![screenshot coming soon]()


## Features

- **Dashboard** — unified overview: disk footprint vs database footprint.
- **Files** — raw folder listing (container-aware depth, browsable with
  breadcrumbs) or grouped by installed extension, with standout folders
  (e.g. backup directories) and an "Other" catch-all.
- **Database** — raw table listing or grouped by extension via an automatic
  3-layer resolver (SQL manifest parsing → name heuristic → Joomla Core →
  Other).
- Chunked AJAX scanning with cached results — nothing rescans without an
  explicit click.
- Bundled Chart.js, no CDN calls.
- Translated: English, Danish, German, Swedish, Norwegian (Bokmål), Finnish.


## Requirements

- Joomla 6.x
- PHP 8.1+
- MySQL / MariaDB


## Installation

Install the release zip via *System → Install → Extensions*, then open
*Components → Footprint*.


## Development

The installable source lives at [https://github.com/janich/com_footprint](https://github.com/janich/com_footprint).


## License

GPL v2 or later. (C) 2026 Janich Rasmussen — <https://github.com/janich/com_footprint>
