#!/usr/bin/env bash
# Kustomize deploy used by GitLab CI (deploy-staging / deploy-production).
#
#   scripts/ci/deploy.sh <overlay> <namespace> <image-tag>
#
# Order (expand/contract migrations, see docs/DEPLOYMENT.md):
#   1. pin images in the overlay (kustomize edit set image)
#   2. delete the previous migration Job, apply ONLY the migration Job, wait until it completes (fail fast)
#   3. apply everything else (Deployments, Services, CRs, ...) and wait for every rollout
#
# Requires: kubectl, kustomize, yq (mikefarah v4) — all present in the alpine/k8s image.
# Env: KUBECONFIG (file), CI_REGISTRY_IMAGE, optional MIGRATE_TIMEOUT (s, default 900),
#      ROLLOUT_TIMEOUT (default 600s), DEPLOYMENTS (space separated override).
set -euo pipefail

OVERLAY="${1:?overlay name (staging|production)}"
NAMESPACE="${2:?namespace}"
TAG="${3:?image tag}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OVERLAY_DIR="$ROOT/infra/kubernetes/overlays/$OVERLAY"
MIGRATE_JOB="${MIGRATE_JOB:-platform-migrate}"
MIGRATE_TIMEOUT="${MIGRATE_TIMEOUT:-900}"
ROLLOUT_TIMEOUT="${ROLLOUT_TIMEOUT:-600s}"
DEPLOYMENTS="${DEPLOYMENTS:-web worker scheduler relay consumer-platform-default consumer-platform-retry}"
REGISTRY_IMAGE="${CI_REGISTRY_IMAGE:?CI_REGISTRY_IMAGE must be set}"

[[ -d "$OVERLAY_DIR" ]] || { echo "overlay not found: $OVERLAY_DIR" >&2; exit 2; }

echo "==> pinning images to tag $TAG"
(
  cd "$OVERLAY_DIR"
  kustomize edit set image \
    "yep-php=$REGISTRY_IMAGE/php:$TAG" \
    "yep-nginx=$REGISTRY_IMAGE/nginx:$TAG"
)

RENDERED="$(mktemp)"
trap 'rm -f "$RENDERED"' EXIT
kustomize build "$OVERLAY_DIR" > "$RENDERED"

echo "==> server-side dry run"
kubectl apply --dry-run=server -n "$NAMESPACE" -f "$RENDERED" >/dev/null

echo "==> migration job $MIGRATE_JOB"
kubectl -n "$NAMESPACE" delete job "$MIGRATE_JOB" --ignore-not-found --wait=true
yq eval "select(.kind == \"Job\" and .metadata.name == \"$MIGRATE_JOB\")" "$RENDERED" | kubectl apply -n "$NAMESPACE" -f -

deadline=$(( $(date +%s) + MIGRATE_TIMEOUT ))
while :; do
  succeeded="$(kubectl -n "$NAMESPACE" get job "$MIGRATE_JOB" -o jsonpath='{.status.succeeded}' 2>/dev/null || true)"
  failed="$(kubectl -n "$NAMESPACE" get job "$MIGRATE_JOB" -o jsonpath='{.status.conditions[?(@.type=="Failed")].status}' 2>/dev/null || true)"
  if [[ "${succeeded:-0}" -ge 1 ]]; then
    echo "migration job completed"
    break
  fi
  if [[ "$failed" == "True" ]]; then
    echo "migration job FAILED — application rollout aborted (old pods keep running)" >&2
    kubectl -n "$NAMESPACE" logs "job/$MIGRATE_JOB" --all-containers --tail=200 >&2 || true
    exit 1
  fi
  if (( $(date +%s) > deadline )); then
    echo "migration job timed out after ${MIGRATE_TIMEOUT}s" >&2
    kubectl -n "$NAMESPACE" logs "job/$MIGRATE_JOB" --all-containers --tail=200 >&2 || true
    exit 1
  fi
  sleep 5
done
kubectl -n "$NAMESPACE" logs "job/$MIGRATE_JOB" --all-containers --tail=50 || true

echo "==> applying workloads"
yq eval "select(.kind != \"Job\" or .metadata.name != \"$MIGRATE_JOB\")" "$RENDERED" | kubectl apply -n "$NAMESPACE" -f -

echo "==> waiting for rollouts"
for d in $DEPLOYMENTS; do
  if kubectl -n "$NAMESPACE" get deployment "$d" >/dev/null 2>&1; then
    kubectl -n "$NAMESPACE" rollout status "deployment/$d" --timeout="$ROLLOUT_TIMEOUT"
  else
    echo "deployment $d not present in $NAMESPACE — skipped"
  fi
done

echo "==> deployed $TAG to $NAMESPACE"
kubectl -n "$NAMESPACE" get deployments -o wide
