#!/usr/bin/env bash
# Render Kustomize overlays and validate them with kubeconform (CRDs from the datreeio CRDs-catalog when
# available, otherwise skipped with -ignore-missing-schemas).
# Author: ShokirjonMK — https://github.com/ShokirjonMK/Yii3-Enterprise-Platform
#
#   scripts/ci/k8s-validate.sh [overlay ...]      (default: staging production)
#
# Uses local kubectl + kubeconform when on PATH, otherwise the pinned container images.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
export MSYS_NO_PATHCONV=1
OVERLAYS=("${@:-staging}")
[[ $# -eq 0 ]] && OVERLAYS=(staging production)
OUT="${K8S_RENDER_DIR:-runtime/k8s-render}"
mkdir -p "$OUT"

# Docker bind-mount path (Git Bash on Windows needs the Windows form)
if pwd -W >/dev/null 2>&1; then HOSTDIR="$(pwd -W)"; else HOSTDIR="$ROOT"; fi

KUBECTL_IMAGE="${KUBECTL_IMAGE:-registry.k8s.io/kubectl:v1.31.0}"
KUBECONFORM_IMAGE="${KUBECONFORM_IMAGE:-ghcr.io/yannh/kubeconform:v0.6.7}"
K8S_VERSION="${K8S_VERSION:-1.31.0}"

kustomize_build() {
  if command -v kubectl >/dev/null 2>&1; then
    kubectl kustomize "$1"
  else
    docker run --rm -v "$HOSTDIR":/work -w /work "$KUBECTL_IMAGE" kustomize "$1"
  fi
}

kubeconform_run() {
  local args=(-strict -summary -output text -kubernetes-version "$K8S_VERSION"
    -schema-location default
    -schema-location 'https://raw.githubusercontent.com/datreeio/CRDs-catalog/main/{{.Group}}/{{.ResourceKind}}_{{.ResourceAPIVersion}}.json'
    -ignore-missing-schemas)
  if command -v kubeconform >/dev/null 2>&1; then
    kubeconform "${args[@]}" "$1"
  else
    docker run --rm -v "$HOSTDIR":/work -w /work "$KUBECONFORM_IMAGE" "${args[@]}" "$1"
  fi
}

rc=0
for o in "${OVERLAYS[@]}"; do
  file="$OUT/$o.yaml"
  echo "==> kustomize build infra/kubernetes/overlays/$o"
  kustomize_build "infra/kubernetes/overlays/$o" > "$file"
  echo "    $(grep -c '^kind:' "$file") objects -> $file"
  echo "==> kubeconform $o"
  kubeconform_run "$file" || rc=1
done
exit $rc
