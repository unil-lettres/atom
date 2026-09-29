# AtoM 2.10.2 UNIL candidate — independent static semantic review

Date: 2026-09-29. Operations: #1, #7, #2.

Reviewed committed UNIL source `563f14893`, upstream `v2.10.2`, common ancestor
`f7f5a638e5cd7b040de4f3e0da8fa47ccb117553`, and the merge index/working changes
in the separate `atom-2.10.2-unil` candidate. This reviewer made no repository
or service changes. References below are source-relative; line numbers refer
to the named side or the initial merged source and can shift after integration.

## Seven direct overlap files

1. **`.github/workflows/generate-sbom.yml`**: UNIL updates GitHub action versions;
   upstream pins Trivy to a digest. Both changes coexist in the candidate. No
   application-runtime conflict identified. CI execution and action/container
   compatibility have not been tested by this review.

2. **`docker/docker-compose.dev.yml`**: upstream advances Percona from 8.0 to
   8.4; this is a database upgrade boundary, not an incidental AtoM code update.
   The root agent's candidate working diff retains `percona:8.0` in dev and test.
   Qualification therefore needs the existing Percona 8.0 runtime. Any eventual
   8.4 migration needs separate data-compatibility and recovery evidence.

3. **`apps/qubit/modules/clipboard/actions/exportCheckAction.class.php`**:
   merged source retains the UNIL root-relative download URL at line 65 and
   incorporates the upstream CSP-safe `data-action="refresh"` link at line 78.
   These are distinct completed-versus-pending-job paths and are compatible
   statically. Required tests: AJAX pending/failed/completed export states,
   progress refresh, same-origin HTTPS download and actual file retrieval.
   Upstream itself contains a stray quote in `href="#""` here and in
   `apps/qubit/modules/clipboard/actions/exportAction.class.php:261`; this is
   an inherited markup defect, not a discovered UNIL merge regression. Review
   DOM/handler behavior, or correct the exact typo with a recorded local diff.

4. **`lib/model/QubitInformationObject.php`**: UNIL deletion and publication
   hooks remain (`recordDeletionForTree` in `delete`, line 324 in UNIL source;
   `recordVisibilityChange` in `setPublicationStatus`). Upstream changes
   `getByTitleIdentifierAndRepo` from `?int` to `array` (line 2171 in the merged
   source), searches translations independently of current culture and handles
   multiple matches. Its caller updates in `lib/QubitFlatfileImport.class.php`
   and `lib/QubitXmlImport.class.php` must remain with that change. Candidate
   merge includes them. Required tests: no/one/multiple matches, translated
   title and repository name, inherited repository, CSV/EAD match/update and
   delete-and-replace. Exercise OAI side effects of those operations as well
   as direct deletion and publication changes. Retain the existing tombstone
   class/schema/migration and verify the table in the isolated test database.

5. **`lib/myUser.class.php`**: retain UNIL helpers/cookie comparison and upstream
   removal of the unrestricted culture-header setter. Upstream now implements
   header handling in `lib/filter/QubitCultureHeaderFilter.class.php`, with
   `app_i18n_languages` allowlisting at lines 44–51 and app filter registration.
   Restoring the old setter would bypass that restriction. Test default French,
   configured English, disallowed/empty headers, login/logout and reverse-proxy
   cache-bypass cookie. OIDC is still disabled and is not qualified by retaining
   helper methods (see limitation below).

6. **`plugins/arElasticSearchPlugin/lib/arElasticSearchPluginUtil.class.php`**:
   UNIL fallback escaping for an empty `app_escape_queries` setting remains
   at lines 356–365. Upstream overlap only changes a scroll-limit comment.
   Required runtime cases include punctuation, quotes, slashes, `&&`/`||`,
   accents, configured versus empty escaping settings, and intended advanced
   query syntax. Static preservation does not prove Elasticsearch behavior.

7. **`vendor/symfony/lib/plugins/sfPropelPlugin/lib/vendor/propel/util/DebugPDO.php`**:
   honor upstream deletion. Upstream removes the whole debug-PDO subsystem,
   mappings and Symfony integration, so retaining the UNIL PHP 8.3 signature
   patch in an orphaned class is inappropriate. UNIL `docker/bootstrap.php`
   already generates `PropelPDO` for dev/test (lines 217 and 245). Check generated
   and persisted database configuration also selects `PropelPDO`, then exercise
   web and CLI connections under the candidate PHP version. The root agent has
   removed this file in the candidate.

## Additional rename/delete conflict: disabled OIDC menu

Delete `plugins/arOidcPlugin.disabled/modules/menu/templates/_userMenu.php`.
The UNIL-renamed file has exactly the original merge-base blob
`2236f243d9900cbe7e34faa47fb771c1dc06a44d`; no UNIL functional modification is
lost. Upstream deletes this obsolete Bootstrap2 override. Core
`apps/qubit/modules/menu/templates/_userMenu.php` already selects
`_userMenu.mod_ext_auth.php`, which contains Bootstrap5 OIDC/CAS branches.

The reviewed candidate index contains **zero tracked files under
`plugins/arOidcPlugin/`**. Upstream translation and login/list-template changes
followed the rename into `.disabled`. `config/ProjectConfiguration.class.php`
still comments out OIDC activation at lines 53–57. Keep that boundary; do not
rename the directory back as part of conflict resolution.

Existing latent limitation: UNIL `myUser::parseProviderIdFromUrl()` and
`validateProviderId($providerId)` do not match signatures of the disabled
`oidcUser` overrides (`parseProviderIdFromURL(string $url): ?string` and
`validateProviderId(string $providerId = '', bool ...): ?string`). This predates
the merge and matters if OIDC is reactivated. It does not establish a failure
in the current disabled-plugin runtime, and this review proposes no unrelated
authentication rewrite.

## UNIL theme and upstream filter/handler inheritance

- `apps/qubit/config/filters.yml` includes `culture_header` immediately after
  `settings`. There is no tracked UNIL theme/module filter override. The UNIL
  theme's configuration adjusts decorator precedence and enables the B5 base;
  it does not replace the application filter chain. The upstream culture filter
  is therefore present for the UNIL theme at source level.
- `plugins/arLettresB5Plugin/js/main.js:2` imports Dominion B5's `js/main`.
  Upstream Dominion main now imports `historyNavigation`, `windowReload` and
  `physicalStorageAddToClipboard`. Those handlers are inherited by the UNIL
  source entry, including the delegated refresh handler for AJAX-added alerts.
- `plugins/arLettresB5Plugin/templates/layout.php:3` retains `#wrapper`, which
  `js/windowReload.js:5` requires. The normal content and alerts are children of
  that wrapper. `historyNavigation.js` uses document-wide `data-action="back"`
  lookup; no missing wrapper dependency identified for it.
- UNIL `_layout_start_webpack.php` matches the upstream Dominion source layout
  structure and uses `htmlWebpackPlugin.tags.headTags`. `webpack.config.js:32`
  discovers plugins with both an entry and this template, and lines 60–68
  regenerate `_layout_start.php` using the relevant plugin/vendor chunks.
- The tracked UNIL generated `_layout_start.php:17` still references historical
  hashed bundle names before build. The candidate must rebuild assets and
  verify that the generated template and all referenced bundles exist in the
  final artifact. Copying PHP/JS source alone would leave new handlers absent.
- The UNIL template continues to call the core Google Analytics partial; the
  candidate core partial has upstream nonce handling, so there is no shadowed
  old GA partial in this theme.

No source-level theme gap beyond the necessary asset rebuild was identified.
Browser evidence is still required for export/job refresh, error/back links,
clipboard changes, menus and responsive display under the actual CSP.

## Other UNIL boundaries checked

The null-resource ACL guard in `plugins/qbAclPlugin/lib/QubitAcl.class.php:770`
and public EAD option propagation in
`plugins/sfEadPlugin/modules/sfEadPlugin/actions/indexAction.class.php` are
outside upstream overlap and retained. Test absent/invalid resources, public
versus authenticated exports, and exclusion of draft/restricted records.

OAI tests should cover physical hierarchical deletion, unpublish/republish,
`GetRecord`, `ListRecords`/`ListIdentifiers`, active/deleted union pagination,
date windows and supported metadata prefixes. HTTP 200 alone is insufficient.

The working candidate version suffix was observed in
`qubitConfiguration::VERSION` despite the adjacent required `x.y.z` comment.
The root agent was notified to check consumers or keep release identity in
separate image/release metadata. No conclusion on suffix compatibility is
claimed here.

## Limits

This is static source/merge review, not a complete security audit or runtime
acceptance. No candidate build, PHP tests, database connection, browser flow,
OIDC activation, migration, deployment or production change was performed by
this reviewer. Other agent test evidence must be recorded separately. Local
CLI log and nginx bot-filter patches were observed in candidate working diff
but not independently runtime-qualified by this review.
