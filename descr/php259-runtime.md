# AtoM: PHP runtime reconciliation

Operations #259 records deployment and acceptance. This branch reconciles the
application and construction sources; it does not deploy them. The PHP 8.3
Dockerfile uses the verified 8.3.36 official manifest digest and asserts the
version. The separately retained runtime-overlay.Dockerfile documents the
source-verified binary overlay used in the attended maintenance. A fresh source
build has a different image identity and must be qualified before deployment.

Staging/production Compose requires explicit reviewed PHP image references.
Set the variables in the operator environment or a private Compose env file;
never use an old image merely to satisfy interpolation. Validate the full
environment-specific Compose/override set before any write. Existing host
overlays, generated configuration, code/cache volumes and external media must
be reconciled against the operations runbook. Do not remove volumes, migrate
SQL or restart a VM as part of Git reconciliation. Never copy host credentials
or raw operational diagnostics into this public repository.

The emergency PHP 8.3 overlay takes a reviewed pre-change application image as
BASE and the official php-8.3.36.tar.xz as its build context. Verify source SHA256
dd54693aa334c5c4d72e9aee96b082c2473750a18a6c1d105789c93a3b1692fd
before building. Exact environment image identities and retained recovery
belong in the private operations evidence.

The application source includes the qualified 2.10.2 UNIL ACL, safe-error and
hierarchy batching changes through 70d985659. The opt-in capacity Compose/nginx
configuration was reconciled by exact hash against the accepted deployment.
Web and worker code/cache compatibility and controlled startup order remain
required; PHP image replacement alone does not refresh mounted code.
