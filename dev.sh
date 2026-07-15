#!/usr/bin/env bash
#
# The fast loop: push this working copy into the live Drupal pod and exercise it,
# without a commit, a pipeline, or an image build.
#
# Why this exists: CI takes minutes and only tells you about the bugs you thought
# to write a test for. This tells you in seconds whether the module even installs.
# Catch the dumb ones here; let CI catch the subtle ones.
#
# ⚠️  READ THIS BEFORE USING `enable` ON A SITE YOU CARE ABOUT
#
# The module lands in the pod's IMAGE filesystem (`web/modules/contrib`), which is
# NOT a volume — verified: zero mounts under that path. But `drush en` writes to
# `core.extension` in the DATABASE, which very much persists.
#
#   pod restart  ⇒  code gone, database still says "n8n is enabled"
#
# This module registers a service that the provider plugin depends on, so a cold
# container rebuild fatals rather than warning. The site is down until you run
# `./dev.sh heal`.
#
# This is the one way this loop is sharper than the Nextcloud equivalent, where
# `custom_apps` sits on a PVC and survives restarts. Here it does not.
#
# Safe pattern:  push → probe → remove.     Leave `enable` on only while you are
# actively working, and run `remove` when you walk away.
#
# Usage:
#   ./dev.sh push          copy the module into the pod
#   ./dev.sh enable        push + enable the modules + rebuild caches  (see above)
#   ./dev.sh heal          restore the code after a pod restart, then rebuild
#   ./dev.sh test          push + run the PHPUnit suite in the pod
#   ./dev.sh probe <file>  run a local PHP file inside the bootstrapped site
#   ./dev.sh drush <args>  run drush in the pod
#   ./dev.sh logs          tail the php logs
#   ./dev.sh shell         open a shell in the pod
#   ./dev.sh remove        uninstall the modules and delete the copied code
#   ./dev.sh restart       remove first, then roll the pod  (safe by construction)

set -euo pipefail

NS=${NS:-cloud}
SELECTOR=${SELECTOR:-app.kubernetes.io/name=drupal}
CONTAINER=${CONTAINER:-php}
# Overwrite the COMPOSER-INSTALLED copy, because that is the one Drupal loads.
# Once a site pins a released version, `composer require drupal/n8n` puts the
# module in modules/contrib — and a second copy under modules/custom is simply
# ignored, so pushing there looks like it worked and changes nothing.
DEST=${DEST:-/var/www/html/web/modules/contrib/n8n}
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

pod() {
  kubectl get pods -n "$NS" -l "$SELECTOR" \
    -o jsonpath='{.items[0].metadata.name}'
}

in_pod() {
  kubectl exec -n "$NS" "$(pod)" -c "$CONTAINER" -- "$@"
}

push() {
  local p
  p="$(pod)"
  echo "== pushing to $p:$DEST =="

  # Ship only what Drupal needs to run the module. Excluding the dev-only files
  # keeps the copy small and stops a stray vendor/ from shadowing the site's.
  in_pod sh -c "rm -rf '$DEST' && mkdir -p '$DEST'"
  tar -C "$HERE" \
    --exclude='.git' \
    --exclude='vendor' \
    --exclude='node_modules' \
    --exclude='.phpunit.cache' \
    --exclude='saga' \
    -cf - . \
  | kubectl exec -i -n "$NS" "$p" -c "$CONTAINER" -- tar -C "$DEST" -xf -

  echo "== pushed =="
}

case "${1:-}" in
  push)
    push
    ;;

  enable)
    push
    echo "== enabling =="
    in_pod drush en n8n ai_provider_n8n -y
    in_pod drush cache:rebuild
    echo "== enabled =="
    in_pod drush pm:list --status=enabled --filter=n8n --field=name
    echo
    echo "⚠️  The database now says these modules are enabled, but the code lives on"
    echo "   the pod's ephemeral image filesystem. If the pod restarts, the site"
    echo "   breaks until you run:  ./dev.sh heal"
    echo "   Run  ./dev.sh remove  when you are done."
    ;;

  # After a pod restart the database still has the modules enabled but the code is
  # gone. Putting the files back is the whole fix.
  heal)
    push
    in_pod drush cache:rebuild
    in_pod drush status --field=bootstrap
    echo "== healed =="
    ;;

  # Run a local PHP file inside the bootstrapped site. The file must open with
  # <?php or require will simply echo it at you — ask me how I know.
  probe)
    shift
    script="${1:?usage: ./dev.sh probe <file.php>}"
    head -1 "$script" | grep -q '<?php' || { echo "error: $script must start with <?php" >&2; exit 1; }
    kubectl cp "$script" "$NS/$(pod):/tmp/_probe.php" -c "$CONTAINER"
    in_pod drush php:eval 'require "/tmp/_probe.php";'
    ;;

  # PHPUnit deliberately does NOT run here: the pod is a production image built
  # with `composer install --no-dev`, so there is no phpunit and no core-dev —
  # verified, not assumed. Unit and Kernel tests run in CI, or on a local dev site
  # built per CONTRIBUTING.md. What the pod gives you that CI cannot is a real
  # site with ai, ai_assistant_api, ai_chatbot and a live openai provider already
  # installed — so use it to probe runtime behaviour, not to run the suite.
  test)
    echo "The pod is a production image with no phpunit (--no-dev)." >&2
    echo "Run unit/kernel tests in CI or on a local dev site; use './dev.sh probe' here." >&2
    exit 2
    ;;

  drush)
    shift
    in_pod drush "$@"
    ;;

  logs)
    kubectl logs -n "$NS" -l "$SELECTOR" -c "$CONTAINER" --follow --tail=100
    ;;

  shell)
    kubectl exec -it -n "$NS" "$(pod)" -c "$CONTAINER" -- bash
    ;;

  remove)
    echo "== removing =="
    in_pod drush pm:uninstall ai_provider_n8n n8n -y || true
    in_pod sh -c "rm -rf '$DEST'"
    in_pod drush cache:rebuild
    echo "== removed =="
    ;;

  # Uninstall BEFORE rolling, or the new pod boots with the modules enabled in the
  # database and no code on disk.
  restart)
    in_pod drush pm:uninstall ai_provider_n8n n8n -y || true
    kubectl rollout restart -n "$NS" deployment/drupal
    kubectl rollout status -n "$NS" deployment/drupal
    ;;

  *)
    echo "usage: ./dev.sh {push|enable|test|drush <args>|logs|shell|remove|restart}" >&2
    exit 2
    ;;
esac
