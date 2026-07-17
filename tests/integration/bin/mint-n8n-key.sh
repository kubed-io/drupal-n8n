#!/usr/bin/env bash
#
# Mint an n8n public-API key for the integration tests — a PREREQUISITE, not a
# feature. The module under test does nothing about how an n8n key is created;
# this exists only so the suite can act as "an admin who already pasted a key".
#
# Ported from the sibling nextcloud-n8n project. Same n8n, same API, so this
# transfers unchanged — and so do its two hard-won traps, both of which cost
# somebody an afternoon:
#
#   1. n8n has NO headless key mint. You log in over the INTERNAL REST API as the
#      env-provisioned owner, then create a public API key:
#        POST /rest/login     -> sets an n8n-auth session cookie
#        POST /rest/api-keys  -> returns { data: { rawApiKey: "<jwt>" } }
#
#   2. n8n's auth cookie attributes make curl's cookie JAR drop it, so we read the
#      Set-Cookie header and replay it verbatim. Using -b/-c here silently fails.
#
# Inputs (env):  N8N_URL, N8N_OWNER_EMAIL, N8N_OWNER_PASSWORD
# Output:        the raw API key on stdout, nothing else. Exits non-zero if it
#                cannot obtain one — the pipeline must fail loud before the suite
#                runs, rather than run every scenario against a 401.

set -euo pipefail

: "${N8N_URL:?N8N_URL is required}"
N8N_OWNER_EMAIL="${N8N_OWNER_EMAIL:-owner@example.com}"
N8N_OWNER_PASSWORD="${N8N_OWNER_PASSWORD:-n8npassword}"

# 1. Log in; capture the n8n-auth cookie from the response headers, not the jar.
cookie=$(
  curl -fsS -D - -o /dev/null -X POST "$N8N_URL/rest/login" \
    -H 'Content-Type: application/json' \
    -d "{\"emailOrLdapLoginId\":\"$N8N_OWNER_EMAIL\",\"password\":\"$N8N_OWNER_PASSWORD\"}" \
  | grep -i '^set-cookie:' | sed 's/^[Ss]et-[Cc]ookie: *//' | cut -d';' -f1 | paste -sd'; ' -
)
if [ -z "$cookie" ]; then
  echo "mint-n8n-key: login did not return an auth cookie" >&2
  exit 1
fi

# 2. Create a public API key with the session cookie. The label must be unique per
# key — n8n has UNIQUE(userId, label) and a duplicate 500s — so stamp it.
label="integration-tests-$(date +%s)-$$"

# Scopes the suite needs: read workflows to prove model discovery, create and
# activate them so preload can seed the fixtures, and manage tags because the site
# tag is a first-class feature — the preload creates tags and attaches them, and a
# key without tag:list 403s on the very first call. Newer n8n scopes API keys, so
# a missing scope here is a silent, surprising 403 later, not a 401 up front.
scopes='["workflow:read","workflow:list","workflow:create","workflow:update","workflow:delete","workflow:activate","workflow:deactivate","tag:create","tag:read","tag:list","tag:update","workflowTags:list","workflowTags:update"]'

raw=$(
  curl -fsS -X POST "$N8N_URL/rest/api-keys" \
    -H 'Content-Type: application/json' \
    -H "Cookie: $cookie" \
    -d "{\"label\":\"$label\",\"expiresAt\":null,\"scopes\":$scopes}" \
  | sed -n 's/.*"rawApiKey":"\([^"]*\)".*/\1/p'
)
if [ -z "$raw" ]; then
  echo "mint-n8n-key: /rest/api-keys did not return a rawApiKey" >&2
  exit 1
fi

printf '%s' "$raw"
