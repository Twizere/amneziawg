#!/bin/sh
# filepath: /Users/twizerepacifique/PROJECTS/QT/AmneziaWG/AmneziaWG-Pfsense/amneziawg/verify-repo.sh

REPO_URL="http://172.16.13.11/awgrepo"

# Detect OS and use appropriate download tool
if command -v fetch >/dev/null 2>&1; then
    DOWNLOAD_CMD="fetch -qo"
elif command -v curl >/dev/null 2>&1; then
    DOWNLOAD_CMD="curl -so"
else
    echo "Error: Neither fetch nor curl found"
    exit 1
fi

echo "=== AmneziaWG Repository Verification ==="
echo ""

echo "1. Checking repository accessibility..."
if curl -s "${REPO_URL}/" | grep -q "Index of"; then
    echo "✓ Repository is accessible"
else
    echo "✗ Repository is NOT accessible"
    exit 1
fi

echo ""
echo "2. Detecting available architectures..."
HTML_CONTENT=$(curl -s "${REPO_URL}/")
AVAILABLE_ARCHS=$(echo "$HTML_CONTENT" | grep -o 'href="FreeBSD[^"]*/"' | sed 's/href="//g' | sed 's/\/"//g' | sed 's/%3A/:/g' | sort -u)

if [ -z "$AVAILABLE_ARCHS" ]; then
    echo "✗ No architecture folders found"
    exit 1
fi

echo "Available architectures:"
echo "$AVAILABLE_ARCHS" | while read arch; do
    echo "  - $arch"
done

# Use FreeBSD:14:amd64 if available (pfSense 2.7.x default), otherwise first available
if echo "$AVAILABLE_ARCHS" | grep -q "FreeBSD:14:amd64"; then
    ABI="FreeBSD:14:amd64"
elif echo "$AVAILABLE_ARCHS" | grep -q "FreeBSD:13:amd64"; then
    ABI="FreeBSD:13:amd64"
else
    ABI=$(echo "$AVAILABLE_ARCHS" | head -n 1)
fi

echo ""
echo "Using architecture: ${ABI}"

# Try to access the folder - test both with and without encoding
ABI_ENCODED=$(echo "$ABI" | sed 's/:/%3A/g')

echo ""
echo "3. Checking architecture folder contents..."

# First try URL-encoded version (what nginx serves)
if curl -s "${REPO_URL}/${ABI_ENCODED}/" | grep -q "packagesite.pkg"; then
    echo "✓ Architecture folder exists with packages"
    USE_ENCODED=1
# Then try plain version (in case nginx accepts it)
elif curl -s "${REPO_URL}/${ABI}/" | grep -q "packagesite.pkg"; then
    echo "✓ Architecture folder exists with packages"
    USE_ENCODED=0
    ABI_ENCODED="$ABI"
else
    echo "✗ Architecture folder or packages missing"
    echo ""
    echo "Debug: Testing URLs..."
    echo "  Encoded URL: ${REPO_URL}/${ABI_ENCODED}/"
    echo "  Plain URL: ${REPO_URL}/${ABI}/"
    exit 1
fi

echo ""
echo "4. Downloading and checking package metadata..."
TMPDIR=$(mktemp -d)
cd "$TMPDIR"

if ${DOWNLOAD_CMD} packagesite.pkg "${REPO_URL}/${ABI_ENCODED}/packagesite.pkg" 2>/dev/null; then
    tar -xf packagesite.pkg 2>/dev/null
    if [ -f packagesite.yaml ] && grep -q "Amneziawireguard" packagesite.yaml 2>/dev/null; then
        # Handle both JSON and YAML formats
        if grep -q '"version":' packagesite.yaml 2>/dev/null; then
            # JSON format
            VERSION=$(grep -o '"name":"pfSense-pkg-Amneziawireguard"[^}]*' packagesite.yaml | grep -o '"version":"[^"]*"' | cut -d'"' -f4)
        else
            # YAML format
            VERSION=$(grep -A 3 "name: pfSense-pkg-Amneziawireguard" packagesite.yaml | grep "version:" | awk '{print $2}' | tr -d '"' | tr -d "'")
        fi
        echo "✓ Package found: pfSense-pkg-Amneziawireguard-${VERSION}"
    else
        echo "✗ Package not found in metadata"
        rm -rf "$TMPDIR"
        exit 1
    fi
else
    echo "✗ Could not download package metadata"
    rm -rf "$TMPDIR"
    exit 1
fi

echo ""
echo "5. Verifying package file exists..."
HTTP_CODE=$(curl -I -s -o /dev/null -w "%{http_code}" "${REPO_URL}/${ABI_ENCODED}/pfSense-pkg-Amneziawireguard-${VERSION}.pkg")
if [ "$HTTP_CODE" = "200" ]; then
    FILESIZE=$(curl -I -s "${REPO_URL}/${ABI_ENCODED}/pfSense-pkg-Amneziawireguard-${VERSION}.pkg" | grep -i content-length | awk '{print $2}' | tr -d '\r')
    if [ -n "$FILESIZE" ]; then
        if [ "$FILESIZE" -gt 1048576 ]; then
            SIZE_MB=$((FILESIZE / 1048576))
            echo "✓ Package file is downloadable (Size: ${SIZE_MB} MB)"
        else
            SIZE_KB=$((FILESIZE / 1024))
            echo "✓ Package file is downloadable (Size: ${SIZE_KB} KB)"
        fi
    else
        echo "✓ Package file is downloadable"
    fi
else
    echo "✗ Package file is missing or inaccessible (HTTP ${HTTP_CODE})"
    rm -rf "$TMPDIR"
    exit 1
fi

echo ""
echo "6. Listing all packages in repository..."
PKG_FILES=$(curl -s "${REPO_URL}/${ABI_ENCODED}/" | grep -o 'pfSense-pkg-[^"]*\.pkg' | sort -u)
if [ -n "$PKG_FILES" ]; then
    echo "$PKG_FILES" | while read pkg; do
        echo "  - $pkg"
    done
else
    echo "  - pfSense-pkg-Amneziawireguard-${VERSION}.pkg"
fi

rm -rf "$TMPDIR"

echo ""
echo "=== All checks passed! ==="
echo ""
echo "Repository Details:"
echo "  URL: ${REPO_URL}"
echo "  Architecture: ${ABI}"
echo "  Package: pfSense-pkg-Amneziawireguard-${VERSION}"
echo ""
echo "To use on pfSense, create /usr/local/etc/pkg/repos/Amneziawireguard.conf:"
echo ""
echo "Amneziawireguard: {"
echo "  url: \"${REPO_URL}/\${ABI}\","
echo "  enabled: yes"
echo "}"
echo ""
echo "Then run on pfSense:"
echo "  pkg update"
echo "  pkg install pfSense-pkg-Amneziawireguard"

