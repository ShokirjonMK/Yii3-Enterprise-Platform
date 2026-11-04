#!/usr/bin/env bash
# Manual rollback of all platform Deployments to their previous ReplicaSet revision.
#
#   scripts/ci/rollback.sh <namespace> [revision]
#
# Database migrations are NOT reverted (expand/contract: the previous release must run against the expanded
# schema). For a data-level rollback use CNPG PITR (infra/kubernetes/.../pitr-recovery example, docs/DEPLOYMENT.md).
set -euo pipefail

NAMESPACE="${1:?namespace}"
REVISION="${2:-}"
DEPLOYMENTS="${DEPLOYMENTS:-web worker scheduler relay consumer-platform-default consumer-platform-retry}"
ROLLOUT_TIMEOUT="${ROLLOUT_TIMEOUT:-600s}"

for d in $DEPLOYMENTS; do
  if ! kubectl -n "$NAMESPACE" get deployment "$d" >/dev/null 2>&1; then
    echo "deployment $d not present — skipped"
    continue
  fi
  echo "==> $d history"
  kubectl -n "$NAMESPACE" rollout history "deployment/$d" | tail -5
  if [[ -n "$REVISION" ]]; then
    kubectl -n "$NAMESPACE" rollout undo "deployment/$d" --to-revision="$REVISION"
  else
    kubectl -n "$NAMESPACE" rollout undo "deployment/$d"
  fi
done
for d in $DEPLOYMENTS; do
  kubectl -n "$NAMESPACE" get deployment "$d" >/dev/null 2>&1 || continue
  kubectl -n "$NAMESPACE" rollout status "deployment/$d" --timeout="$ROLLOUT_TIMEOUT"
done
kubectl -n "$NAMESPACE" get deployments -o wide
