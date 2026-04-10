#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage:
  ./build-update.sh [--out-dir DIR] [--name ZIP_NAME] [--secret-key-b64 BASE64] [--major] [--dry-run]

Description:
  Creates a zip for the full LaneAssist module (Modules/Custom/LaneAssist),
  computes SHA-256, and creates an Ed25519 signature of the hash.
  Also bumps module version in version.php on every build:
    - default: minor bump (X.Y -> X.(Y+1))
    - --major: major bump (X.Y -> (X+1).0)

Secrets:
  Provide the signing key as:
    --secret-key-b64 ...
  or environment variable:
    LANEASSIST_UPDATE_SECRET_KEY_B64

Outputs:
  - <zip>.zip
  - SHA-256 is printed to console
  - Signature is printed to console
EOF
}

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REL_MODULE_PATH="Modules/Custom/LaneAssist"
MODULE_SOURCE_DIR="$SCRIPT_DIR"
MODULE_VERSION="$(php -r 'require $argv[1]; echo getLaneAssistModuleVersion();' "$SCRIPT_DIR/version.php" 2>/dev/null || echo "unknown")"
VERSION_BUMP_MODE="minor"

OUT_DIR="$SCRIPT_DIR"
ZIP_NAME=""
SECRET_KEY_B64="${LANEASSIST_UPDATE_SECRET_KEY_B64:-}"
DRY_RUN=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --out-dir)
      OUT_DIR="$2"
      shift 2
      ;;
    --name)
      ZIP_NAME="$2"
      shift 2
      ;;
    --secret-key-b64)
      SECRET_KEY_B64="$2"
      shift 2
      ;;
    --major)
      VERSION_BUMP_MODE="major"
      shift
      ;;
    --dry-run)
      DRY_RUN=1
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      usage
      exit 1
      ;;
  esac
done

if [[ "$MODULE_VERSION" == "unknown" || -z "$MODULE_VERSION" ]]; then
  echo "Unable to read module version from $SCRIPT_DIR/version.php" >&2
  exit 1
fi

NEXT_MODULE_VERSION="$(php -r '
$version = trim((string)$argv[1]);
$mode = trim((string)$argv[2]);
if (!preg_match("/^(\\d+)\\.(\\d+)$/", $version, $m)) {
    fwrite(STDERR, "Unsupported version format: $version\\n");
    exit(2);
}
$major = intval($m[1]);
$minor = intval($m[2]);
if ($mode === "major") {
    $major++;
    $minor = 0;
} else {
    $minor++;
}
echo $major . "." . $minor;
' "$MODULE_VERSION" "$VERSION_BUMP_MODE")"

if [[ -z "$ZIP_NAME" ]]; then
  ZIP_NAME="laneassist-module-v${NEXT_MODULE_VERSION}.zip"
fi

if [[ "$ZIP_NAME" != *.zip ]]; then
  ZIP_NAME="${ZIP_NAME}.zip"
fi

ZIP_PATH="$OUT_DIR/$ZIP_NAME"

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "[dry-run] Module version: $MODULE_VERSION -> $NEXT_MODULE_VERSION ($VERSION_BUMP_MODE bump)"
  echo "[dry-run] Module source: $MODULE_SOURCE_DIR"
  echo "[dry-run] Module path:  $REL_MODULE_PATH"
  echo "[dry-run] Zip output:   $ZIP_PATH"
  echo "[dry-run] SHA-256:      will be printed to console"
  echo "[dry-run] Signature:    will be printed to console"
  if [[ -z "$SECRET_KEY_B64" ]]; then
    echo "[dry-run] Secret key:   not provided"
  else
    echo "[dry-run] Secret key:   provided"
  fi
  exit 0
fi

command -v zip >/dev/null 2>&1 || { echo "zip command not found" >&2; exit 1; }
command -v sha256sum >/dev/null 2>&1 || { echo "sha256sum command not found" >&2; exit 1; }
command -v php >/dev/null 2>&1 || { echo "php command not found" >&2; exit 1; }

php -r 'if (!function_exists("sodium_crypto_sign_detached")) { fwrite(STDERR, "libsodium extension missing in PHP\n"); exit(2); }'

if [[ -z "$SECRET_KEY_B64" ]]; then
  echo "Missing secret key. Use --secret-key-b64 or LANEASSIST_UPDATE_SECRET_KEY_B64" >&2
  exit 1
fi

if [[ ! -f "$SCRIPT_DIR/version.php" ]]; then
  echo "Cannot read version file: $SCRIPT_DIR/version.php" >&2
  exit 2
fi

TMP_VERSION_FILE="$(mktemp)"
VERSION_FILE_MODE="$(stat -c '%a' "$SCRIPT_DIR/version.php" 2>/dev/null || echo '664')"
sed -E "s/define\('LANEASSIST_MODULE_VERSION',[[:space:]]*'[^']*'\);/define('LANEASSIST_MODULE_VERSION', '$NEXT_MODULE_VERSION');/" \
  "$SCRIPT_DIR/version.php" > "$TMP_VERSION_FILE"
chmod "$VERSION_FILE_MODE" "$TMP_VERSION_FILE" 2>/dev/null || true

if ! grep -q "define('LANEASSIST_MODULE_VERSION', '$NEXT_MODULE_VERSION');" "$TMP_VERSION_FILE"; then
  rm -f "$TMP_VERSION_FILE"
  echo "Could not update LANEASSIST_MODULE_VERSION in $SCRIPT_DIR/version.php" >&2
  exit 3
fi

mv "$TMP_VERSION_FILE" "$SCRIPT_DIR/version.php"

MODULE_VERSION="$NEXT_MODULE_VERSION"

mkdir -p "$OUT_DIR"
rm -f "$ZIP_PATH"

STAGE_ROOT="$(mktemp -d)"
trap 'rm -rf "$STAGE_ROOT"' EXIT

mkdir -p "$STAGE_ROOT/Modules/Custom"
cp -a "$MODULE_SOURCE_DIR" "$STAGE_ROOT/Modules/Custom/LaneAssist"

# Normalize file modes in staged payload to avoid restrictive perms after updates.
find "$STAGE_ROOT/$REL_MODULE_PATH" -type d -exec chmod 775 {} +
find "$STAGE_ROOT/$REL_MODULE_PATH" -type f -exec chmod 664 {} +
find "$STAGE_ROOT/$REL_MODULE_PATH" -type f -name '*.sh' -exec chmod 775 {} +

(
  cd "$STAGE_ROOT"
  zip -r "$ZIP_PATH" "$REL_MODULE_PATH" \
    -x "*/.git/*" \
    "*/laneassist-module*.zip" \
    "*/.env" \
    "*/docs/screenshots/*" \
    "build-update.sh" \
    ".gitignore" \
    "*/.env.*" \
       "*/node_modules/*" \
       "*/vendor/*"
)

ZIP_SHA256="$(sha256sum "$ZIP_PATH" | awk '{print $1}')"

ZIP_SIGNATURE="$(LANEASSIST_UPDATE_SECRET_KEY_B64="$SECRET_KEY_B64" php -r '
$hash = trim((string)$argv[1]);
$secretB64 = getenv("LANEASSIST_UPDATE_SECRET_KEY_B64");
if ($hash === "") {
  fwrite(STDERR, "Empty hash input\n");
    exit(3);
}
$secret = sodium_base642bin($secretB64, SODIUM_BASE64_VARIANT_ORIGINAL);
$signature = sodium_crypto_sign_detached($hash, $secret);
echo sodium_bin2base64($signature, SODIUM_BASE64_VARIANT_ORIGINAL), PHP_EOL;
' "$ZIP_SHA256")"

echo "Created:   $ZIP_PATH"
echo "Version:   $MODULE_VERSION"
echo "SHA-256:   $ZIP_SHA256"
echo "Signature: $ZIP_SIGNATURE"
echo ""
echo "Copy this signature into the updater text field:"
echo "$ZIP_SIGNATURE"
echo ""
echo "Use in updater:"
echo "- Upload ZIP: $ZIP_NAME"
echo "- Paste signature shown above"
