# AtoM 2.10.2 UNIL candidate

Technical release dossier linked to unil-ops Operations #1, #7 and #2.
Task lifecycle and timing remain in Operations.

## Source identity

Candidate release identifier: **2.10.2-unil.1-rc1**. AtoM's numeric VERSION stays
2.10.2 as required by its documented version contract; the UNIL identifier lives
in manifest.json and the qualification-image label.

Starting point: published development 1bda7391fd5ec8efabed6f83dd22dd2903c3f012,
associated with the accepted August18 image. Its Git tree is identical to local
563f14893 (tree42299d93213ed12ae4e45b4f2ae85b5053a8b90c), so the candidate keeps
published ancestry without losing the final hierarchy-navigation changes.
Upstream annotated v2.10.2 tag c189c2f79e7c56629d30024d1d07454fc974f9ea resolves
to02a70b8a4b23a805256abd0a14cd0f93e311a581; both identities matched the official
GitHub upstream via ls-remote on September29. The tag is unsigned; no signature
verification is claimed. The merge-base precedes v2.10.1; the merge therefore
includes intervening upstream changes, not just the v2.10.1..v2.10.2 patch range.

## Deliberate integration decisions

- Honor upstream deletion of the DebugPDO subsystem; its old UNIL PHP8 signature
  workaround becomes obsolete. Keep generated PropelPDO config.
- Honor deletion of the old OIDC plugin user-menu partial (identical to common
  ancestor after UNIL rename). OIDC remains disabled. Its optional reactivation
  is unqualified and would require addressing inherited helper signatures.
- Preserve Percona8.0 in dev AND test, as well as stage/prod. No implicit8.4 upgrade.
- Retain the new upstream culture-header allowlist/filter and rebuild UNIL assets
  so CSP-compatible refresh handlers are present.
- Include the prepared CLI-log permission fix (#2) and accepted nginx bot filter
  (#195). Unrelated AGENTS.md changes remain only in the original checkout.
- No change to schema.yml or migration classes relative to the UNIL baseline;
  migration198/tombstone table must remain in any accepted existing database.

unil-patches.json accounts for all37 source commits, seven semantic overlaps and
local overlays. semantic-review.md distinguishes source review from acceptance.

## Staging recipe boundary (#7)

staging-runtime-baseline-20260929.json records inspected images/mounts without
secrets. The running named code volume is distinct from the staging Git checkout.
Preserve uploads/downloads/data/cache binds, host TLS, accepted nginx filter,
worker XDEBUG_MODE=off, and previous images/configuration. The old disabled
systemd unit must not be enabled as part of this release.

staging-image-override.yml is a preparation overlay for the inspected on-VM
Compose file; it changes only app and worker image references. It is not a full
standalone deployment recipe or authorization to apply. Before use, validate an
accepted repository@sha256 digest, resolved configuration, source/volume drift,
cache write permissions, disk capacity and coherent protected recovery. No
floating development tag qualifies a reviewed release.

The code-volume refresh must have its own recovery procedure, matched previous
image and snapshot decision. Never apply a generic checkout reset, Docker prune
or unreviewed volume removal. Shared NFS is outside VM snapshot rollback.

## Qualification and limits

See unil-ops/docs/reports/atom-release-preparation-2026-09-29/ for dated logs.
The local qualification image replaces all source and Composer/npm dependencies
on cached PHP8.3.32 runtime e2a03acf8150, then rebuilds assets from lockfiles.
It is not a fresh build of the moving upstream PHP/PECL base and is not published.

Tests run on task-owned disposable Percona8.0/Elasticsearch/cache/queue, internal
network, no public ports, no original source/data mounts. Config is generated
inside disposable containers only, so no test credentials enter this worktree.
The installer needs a2GiB CLI ceiling for initial multilingual fixture import;
this is a QA-only setting, not a change to production PHP limits.

Further acceptance must exercise real browser login/navigation, public and
restricted exports, CSP clipboard refresh, print preview and OAI harvesting
against the exact intended release image. The upstream clipboard action markup
contains an extra quote in href; it was not introduced by this merge and requires
browser observation, not a claim of a new UNIL fix. Elasticsearch replacement
#4, OS maintenance and credential rotation #5 remain separate scopes.

A release image must use a reviewed clean build context: the current .dockerignore
does not exclude all generated config/data. This worktree has never been booted
against real data; do not later build from a QA-populated directory unchecked.

No server deployment or new wiki publication in this preparation. Future changes
to actual deployment procedures must reconcile VM/deployment/wiki docs. Separate
#170 reboot documentation retains its own publication boundary.

## Local results recorded September29

Full PHPUnit including two new real-model OAI regression tests:362 tests,917
assertions passed. Independent culture allowlist:4 tests,8 assertions. PHP lint:
61 merged PHP files plus the new test passed; PHP CS Fixer accepts the new test.
Real search:status as www-data passes twice across repeated startup and retains
a marker in qubit_cli.log. PHP-FPM configuration validates; all3 generated UNIL
asset references exist. These checks do not establish browser/deployment acceptance.
