# Work Plan Manager

A WordPress plugin for managing Work Plans, Goals, and Objectives with a streamlined admin interface, group-based editor permissions, and export support.

- **Version:** 1.4.3
- **Author:** KC Web Programmers
- **Text Domain:** `work-plan-manager`

## Features

- **Work Plan hierarchy** — manage `workplan` → `workplan-goal` → `workplan-objective` custom post types through a single admin screen, backed by ACF relationship fields (`related_work_plan_goals`, `work_plan_objectives`, `objective_outputs`).
- **Custom admin UI** — a dedicated top-level admin menu page, built on `wp-element`/`wp-components`/`wp-api-fetch`, with AJAX-driven create/edit/delete for work plans, goals, and objectives.
- **Bimonthly report cross-references** — objective outputs are enriched with references to any Bimonthly Report Manager highlight items tagged against them, showing title, author, date, and summary inline.
- **Group/region-based permissions** — a custom `manage_workplans`/`edit_workplans`/`edit_others_workplans`/etc. capability set, mapped to a set of region-specific editor roles (`central_east_editor`, `great_lakes_editor`, `mid_america_editor`, `new_england_editor`, `northeast_caribbean_editor`, `southeast_editor`, `mountain_plains_editor`, `northwest_editor`, `pacific_southwest_editor`, `south_southwest_editor`), so editors only see/edit work plans tagged to their `group` taxonomy term.
- **Capability self-healing** — on activation and via an admin routine, existing users' capabilities are reconciled against their role (admins get full access, regional editors get group-restricted access, everyone else has workplan capabilities stripped).
- **Export handling** — AJAX-triggered export generation with a dedicated download endpoint, writing to a protected `uploads/workplan-exports/` directory (`.htaccess`-locked) that is cleaned up hourly via WP-Cron (files older than 2 hours are removed).
- **Auto-numbering helpers** — next-available goal letter (A–Z) and next-available objective number are computed automatically for new entries.
- **Requirements checking** — activation is blocked (with an admin notice) if Advanced Custom Fields isn't active, and ongoing admin notices flag missing PHP/WordPress versions or required post types/taxonomies (`workplan`, `workplan-goal`, `workplan-objective`, `group`, `grant-year`).

## Requirements

- WordPress 5.0+
- PHP 7.4+
- **Advanced Custom Fields (ACF)** — required; the plugin will deactivate itself on activation if ACF is not active.
- Custom post types `workplan`, `workplan-goal`, `workplan-objective` and taxonomies `group`, `grant-year` are expected to already be registered elsewhere (theme or another plugin/ACF configuration).
- Optional: [PublishPress Permissions](https://publishpress.com/permissions/) (`pp_get_groups_for_user`) for an alternate source of group membership, used as a fallback if role-based group mapping doesn't resolve.
- Optional: Bimonthly Report Manager plugin, if present, its highlight items get cross-referenced into objective outputs.

## File Structure

```
work-plan-manager/
├── work-plan-manager.php      # Main plugin file: capabilities, admin menu, AJAX handlers
├── includes/
│   ├── installation.php       # Activation/deactivation/uninstall, requirements checks, upgrade routine
│   ├── admin-page.php         # Admin screen markup
│   ├── export-handler.php     # AJAX export + download endpoint
│   ├── utility-functions.php  # Data-fetching helpers, permission checks, completion status
│   └── acf-behaviors.php      # Optional ACF field enhancements (not loaded by default)
├── js/work-plan-manager.js
└── css/work-plan-manager.css
```
