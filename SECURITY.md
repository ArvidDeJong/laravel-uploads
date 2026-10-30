# Security policy

This package stores the files people upload and decides who may open the private ones, so security reports are taken seriously.

## Supported versions

Only the latest minor release of 1.x receives security fixes. Upgrade before reporting.

## What counts as a vulnerability

For example:

- a private upload, conversion or page that can be read without a valid signed link, after the link expired, or by a user the policy should refuse;
- a way to store a file outside the configured disks or path, or to overwrite another upload's file;
- a file that is not removed on a hard delete although the package says it is;
- the Livewire components changing uploads of a model the user may not update.

## Reporting a vulnerability

Please do **not** open a public issue. Report it privately instead:

- via [GitHub private vulnerability reporting](https://github.com/ArvidDeJong/laravel-uploads/security/advisories/new), or
- by email to info@arvid.nl.

Include the package version, the Laravel version and the steps or request that reproduce it.

You will get a reply within a week. Once a fix is released, the advisory is published and you are credited, unless you prefer not to be.
