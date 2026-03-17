# AmneziaWG pfSense Package Repository Setup Tutorial

This tutorial will guide you through setting up a complete CI/CD pipeline and web repository for hosting AmneziaWG packages for pfSense.

## Table of Contents
1. [Prerequisites](#prerequisites)
2. [GitLab Runner Setup](#gitlab-runner-setup)
3. [Web Server Configuration](#web-server-configuration)
4. [CI/CD Pipeline Setup](#cicd-pipeline-setup)
5. [Building and Deploying Packages](#building-and-deploying-packages)
6. [pfSense Configuration](#pfsense-configuration)
7. [Testing and Verification](#testing-and-verification)
8. [Troubleshooting](#troubleshooting)

---

## Prerequisites

Before starting, ensure you have:

- A GitLab account with a repository containing this project
- A FreeBSD server (physical or virtual) for the GitLab Runner
- A web server (can be the same server) to host the repository
- Root/sudo access to both servers
- Basic knowledge of FreeBSD and GitLab CI/CD

**Required Software:**
- FreeBSD 13.x or later
- GitLab Runner
- Web server (nginx, Apache, or lighttpd)
- pkg package manager

---

## GitLab Runner Setup

### Step 1: Install GitLab Runner on FreeBSD

```bash
# Update package repository
pkg update

# Install GitLab Runner
pkg install -y gitlab-runner

# Enable GitLab Runner service
sysrc gitlab_runner_enable="YES"
```

### Step 2: Register the Runner

```bash
# Register the runner with your GitLab instance
gitlab-runner register

# You'll be prompted for:
# - GitLab instance URL (e.g., https://gitlab.com/)
# - Registration token (found in GitLab: Settings → CI/CD → Runners)
# - Runner description (e.g., "freebsd-builder")
# - Tags (enter: "freebsd-runner")
# - Executor type (enter: "shell")
```

### Step 3: Configure Runner User Permissions

```bash
# Add gitlab-runner user to wheel group for pkg operations
pw usermod gitlab-runner -G wheel

# Set permissions on existing web directory (if it already exists from nginx setup)
# This matches the nginx document root configured earlier
chown -R www:gitlab-runner /usr/local/www/awgrepo.qtsoftwareltd.com
chmod -R 2775 /usr/local/www/awgrepo.qtsoftwareltd.com

# Note: If the directory doesn't exist yet, the CI/CD pipeline will create it
# using sudo mkdir, so no need to manually create it

# Create sudoers configuration file for gitlab-runner
cat > /usr/local/etc/sudoers.d/gitlab-runner << 'EOF'
# GitLab Runner permissions for CI/CD pipeline
Defaults:gitlab-runner !requiretty
gitlab-runner ALL=(ALL) NOPASSWD: /usr/local/sbin/pkg
gitlab-runner ALL=(ALL) NOPASSWD: /bin/mkdir
gitlab-runner ALL=(ALL) NOPASSWD: /bin/rm
gitlab-runner ALL=(ALL) NOPASSWD: /usr/bin/tar
gitlab-runner ALL=(ALL) NOPASSWD: /usr/sbin/chown
gitlab-runner ALL=(ALL) NOPASSWD: /bin/chmod
EOF

# Set proper permissions on sudoers file
chmod 440 /usr/local/etc/sudoers.d/gitlab-runner

# Verify sudoers syntax
visudo -c
```

**Important**: 
- The `!requiretty` option allows sudo to run without a terminal, which is required for GitLab CI/CD pipelines.
- The web directory path **must match** your nginx `root` directive (check with `nginx -T | grep root`)
- If your nginx serves from a different path, update `.gitlab-ci.yml` variable `WEB_REPO_PATH` accordingly

**Test the configuration**:
```bash
# Switch to gitlab-runner user and test sudo access
sudo -u gitlab-runner sudo -n mkdir -p /usr/local/www/awgrepo.qtsoftwareltd.com/awgrepo/test
sudo -u gitlab-runner sudo -n rm -rf /usr/local/www/awgrepo.qtsoftwareltd.com/awgrepo/test
sudo -u gitlab-runner sudo -n ls -la /usr/local/www/awgrepo.qtsoftwareltd.com

# If these commands succeed without password prompt, configuration is correct
```

### Step 4: Start GitLab Runner

```bash
service gitlab-runner start
service gitlab-runner status
```

---

## Web Server Configuration

### Option 1: Using nginx (Recommended)

#### Install nginx

```bash
pkg install -y nginx
sysrc nginx_enable="YES"
```

#### Configure nginx for Repository Hosting

Create nginx configuration file:

```bash
# Create repository directory
mkdir -p /usr/local/www/awgrepo

# Set proper permissions
chown -R www:www /usr/local/www/awgrepo
chmod -R 755 /usr/local/www/awgrepo
```

Edit `/usr/local/etc/nginx/nginx.conf`:

```nginx
http {
    # ...existing code...
    
    server {
        listen 80;
        server_name 172.16.13.11;  # Replace with your server IP
        
        root /usr/local/www/awgrepo;
        
        location / {
            autoindex on;
            autoindex_exact_size off;
            autoindex_localtime on;
        }
        
        location ~ \.pkg$ {
            add_header Content-Type application/octet-stream;
        }
        
        location ~ \.txz$ {
            add_header Content-Type application/x-tar;
        }
    }
}
```

Start nginx:

```bash
service nginx start
```

### Option 2: Using Apache

```bash
pkg install -y apache24
sysrc apache24_enable="YES"

# Create repository directory
mkdir -p /usr/local/www/apache24/data/awgrepo
chown -R www:www /usr/local/www/apache24/data/awgrepo

# Edit /usr/local/etc/apache24/httpd.conf
# Uncomment: LoadModule autoindex_module libexec/apache24/mod_autoindex.so
# Add directory configuration for /awgrepo

service apache24 start
```

### Option 3: Using lighttpd (Lightweight)

```bash
pkg install -y lighttpd
sysrc lighttpd_enable="YES"

mkdir -p /usr/local/www/awgrepo
chown -R www:www /usr/local/www/awgrepo

# Edit /usr/local/etc/lighttpd/lighttpd.conf
# Set server.document-root = "/usr/local/www/awgrepo"
# Enable dir-listing.activate = "enable"

service lighttpd start
```

---

## CI/CD Pipeline Setup

### Understanding the Pipeline

The `.gitlab-ci.yml` file automates:
1. **Building**: Creates pfSense-compatible packages
2. **Versioning**: Tags packages with version numbers
3. **Repository Creation**: Generates pkg repository metadata
4. **Artifact Storage**: Saves compressed repository for deployment

### Pipeline Configuration Breakdown

```yaml
stages:
  - build  # Single stage for building and packaging

variables:
  PKG_VERSION: "1.0.0"  # Update this for each release
```

**Key Components:**

1. **Cleanup Phase**: Removes old builds to ensure fresh packages
2. **File Staging**: Copies application files to staging directory
3. **Version Update**: Updates manifest with current version
4. **Package Creation**: Uses `pkg create` to build the package
5. **Repository Generation**: Creates pkg repository with metadata
6. **Artifact Creation**: Compresses repository for easy distribution

### Customizing the Pipeline

To update the version:

```yaml
variables:
  PKG_VERSION: "1.0.1"  # Change this number
```

To add additional stages:

```yaml
stages:
  - build
  - test      # Add testing stage
  - deploy    # Add deployment stage
```

---

## Building and Deploying Packages

### Manual Build (Local Testing)

Before pushing to GitLab, test locally:

```bash
# Navigate to project directory
cd /path/to/AmneziaWG-Pfsense/amneziawg

# Clean previous builds
rm -rf stagedir awgrepo *.pkg *.tar.gz

# Create staging directory
mkdir -p stagedir

# Copy files
cp -r files/. stagedir/

# Update version in manifest
sed -i '' 's/"version": ".*"/"version": "1.0.0"/' metadir/+MANIFEST

# Create package
pkg create -m metadir -r stagedir -p pkg-plist -o .

# Detect architecture
ABI=$(pkg config abi)

# Create repository
mkdir -p awgrepo/${ABI}
cp pfSense-pkg-Amneziawireguard-*.pkg awgrepo/${ABI}/
pkg repo awgrepo/${ABI}

# Create tarball
tar -czf awgrepo-1.0.0-${ABI}.tar.gz awgrepo/${ABI}/
```

### Automated Build via GitLab

1. **Commit and Push Changes**:
```bash
git add .
git commit -m "Update to version 1.0.1"
git push origin main
```

2. **Monitor Pipeline**:
- Go to GitLab: **CI/CD → Pipelines**
- Watch the build progress
- Check logs for any errors

3. **Automatic Deployment**:
- The pipeline now automatically deploys to `/usr/local/www/awgrepo`
- No manual deployment needed!
- Artifacts are still available for download as backup

### Deploying to Web Server

#### Automatic Deployment (Default)

The CI/CD pipeline now automatically deploys packages to the web server directory upon successful build. The deployment process:

1. Extracts the repository tarball to `/usr/local/www/awgrepo`
2. Sets proper ownership (www:www)
3. Sets correct permissions (755)
4. Verifies deployment

**Configuration Variables:**

In `.gitlab-ci.yml`, you can customize the web path:

```yaml
variables:
  WEB_REPO_PATH: "/usr/local/www/awgrepo"  # Change if needed
```

#### Method 2: Automated Deployment (Add to CI)

**Note**: This is now built into the main pipeline. For deploying to a separate/remote server, add a deploy stage:

```yaml
stages:
  - build
  - deploy

deploy_to_server:
  stage: deploy
  script:
    - echo "Deploying to web server"
    - scp awgrepo-*.tar.gz root@172.16.13.11:/tmp/
    - ssh root@172.16.13.11 "cd /usr/local/www/awgrepo && tar -xzf /tmp/awgrepo-*.tar.gz --strip-components=1 && chown -R www:www /usr/local/www/awgrepo"
  only:
    - main
    - tags
  tags:
    - freebsd-runner
```

**Note**: Set up SSH keys for passwordless authentication.

---

## pfSense Configuration

### Step 1: Add Repository Configuration

On your pfSense system:

```bash
# Create repository configuration file
vi /usr/local/etc/pkg/repos/Amneziawireguard.conf
```

Add the following content:

```
Amneziawireguard: {
  url: "http://172.16.13.11/awgrepo/${ABI}",
  enabled: yes
}
```

**Replace `172.16.13.11`** with your actual server IP address.

### Step 2: Update Package Cache

```bash
# Update package repository cache
pkg update

# Verify the repository is available
pkg search amneziawg
```

### Step 3: Install AmneziaWG

```bash
# Install the package
pkg install pfSense-pkg-Amneziawireguard

# Verify installation
pkg info pfSense-pkg-Amneziawireguard
```

### Step 4: Configure via pfSense WebGUI

1. Log in to pfSense WebGUI
2. Navigate to **VPN → AmneziaWG**
3. Configure your AmneziaWG tunnels

---

## Testing and Verification

### Test 1: Repository Accessibility

```bash
# From any FreeBSD system or your local machine
curl http://172.16.13.11/awgrepo/

# Expected output: Directory listing showing architecture folders like FreeBSD:15:amd64
# If using nginx with autoindex, you should see an HTML directory listing
```

**Using a web browser:**
1. Open `http://172.16.13.11/awgrepo/` in your browser
2. You should see a directory listing
3. Click on the architecture folder (e.g., `FreeBSD:15:amd64/`)
4. You should see files like:
   - `pfSense-pkg-Amneziawireguard-1.0.0.pkg`
   - `packagesite.pkg`
   - `data.pkg`
   - `meta`

### Test 2: Package Metadata Verification

```bash
# Get your system's ABI
ABI=$(pkg config abi)
echo "Testing for ABI: ${ABI}"

# Check if package metadata is accessible
curl -I http://172.16.13.11/awgrepo/${ABI}/packagesite.pkg

# Should return: HTTP/1.1 200 OK

# Download and inspect the metadata
fetch http://172.16.13.11/awgrepo/${ABI}/packagesite.pkg
tar -tf packagesite.pkg

# Should show: packagesite.yaml

# Extract and view package info
tar -xf packagesite.pkg
cat packagesite.yaml | grep -A 10 "Amneziawireguard"
```

### Test 3: Complete Repository Test from pfSense

On your pfSense system (or any FreeBSD 13.x system):

```bash
# Step 1: Add the repository configuration
cat > /usr/local/etc/pkg/repos/Amneziawireguard.conf << 'EOF'
Amneziawireguard: {
  url: "http://172.16.13.11/awgrepo/${ABI}",
  enabled: yes
}
EOF

# Step 2: Update package cache
pkg update -f

# Expected output should include:
# Updating Amneziawireguard repository catalogue...
# Fetching meta.conf: 100%    179 B
# Fetching packagesite.pkg: 100%    564 B

# Step 3: Search for the package
pkg search -x "^pfSense-pkg-Amneziawireguard"

# Expected output:
# pfSense-pkg-Amneziawireguard-1.0.0    AmneziaWG VPN package for pfSense

# Step 4: Get detailed package information
pkg rquery '%n-%v: %c' pfSense-pkg-Amneziawireguard

# Step 5: Check package dependencies (dry-run install)
pkg install -n pfSense-pkg-Amneziawireguard

# This won't install but shows what would be installed
```

### Test 4: Verify Repository Configuration

```bash
# Check all configured repositories
pkg -vv | grep -A 5 "Amneziawireguard"

# Expected output:
# Amneziawireguard: {
#   url             : "http://172.16.13.11/awgrepo/FreeBSD:15:amd64",
#   enabled         : yes,
#   ...
# }

# Test repository connectivity
pkg update -r Amneziawireguard

# Should complete without errors
```

### Test 5: Web Server Access Logs

On the FreeBSD server hosting the repository:

```bash
# Monitor web server access in real-time
tail -f /var/log/nginx/access.log

# Then from another terminal or machine, try accessing:
curl http://172.16.13.11/awgrepo/FreeBSD:15:amd64/packagesite.pkg

# You should see the request logged with status 200
```

### Test 6: Package Download Test

```bash
# Download the actual package file
cd /tmp
fetch http://172.16.13.11/awgrepo/FreeBSD:15:amd64/pfSense-pkg-Amneziawireguard-1.0.0.pkg

# Verify the package
pkg info -F pfSense-pkg-Amneziawireguard-1.0.0.pkg

# Should display package information:
# Name: pfSense-pkg-Amneziawireguard
# Version: 1.0.0
# Comment: AmneziaWG VPN package for pfSense
# ...
```

### Test 7: Complete Installation Test

```bash
# Install the package (on pfSense)
pkg install pfSense-pkg-Amneziawireguard

# Verify installation
pkg info pfSense-pkg-Amneziawireguard

# Check installed files
pkg info -l pfSense-pkg-Amneziawireguard

# Test if configuration is accessible
ls -la /usr/local/etc/pkg/repos/Amneziawireguard.conf
```

### Troubleshooting Verification Issues

**Issue: curl returns "Connection refused"**
```bash
# Check if nginx is running
service nginx status

# Check if nginx is listening on port 80
sockstat -l | grep :80

# Restart nginx if needed
service nginx restart
```

**Issue: curl returns "404 Not Found"**
```bash
# Verify directory exists and has files
ls -la /usr/local/www/awgrepo/FreeBSD:15:amd64/

# Check nginx root directory configuration
grep -n "root" /usr/local/etc/nginx/nginx.conf

# Check nginx error logs
tail -n 50 /var/log/nginx/error.log
```

**Issue: pkg update fails to fetch from repository**
```bash
# Test DNS resolution (if using domain name)
ping 172.16.13.11

# Test direct URL access
fetch -o - http://172.16.13.11/awgrepo/FreeBSD:15:amd64/meta.conf

# Check if firewall is blocking (from pfSense)
telnet 172.16.13.11 80

# Verify repository URL in pkg configuration
pkg -vv | grep url
```

**Issue: Package not found after pkg update**
```bash
# Force refresh of all repositories
pkg update -f

# Check if repository is enabled
cat /usr/local/etc/pkg/repos/Amneziawireguard.conf

# Manually inspect packagesite.yaml
fetch http://172.16.13.11/awgrepo/FreeBSD:15:amd64/packagesite.pkg
tar -xf packagesite.pkg
cat packagesite.yaml
```

### Verification Checklist

Use this checklist after each build:

- [ ] Web server is accessible: `curl http://172.16.13.11/awgrepo/`
- [ ] Architecture folder exists and contains files
- [ ] Package file is present: `pfSense-pkg-Amneziawireguard-*.pkg`
- [ ] Metadata files exist: `packagesite.pkg`, `meta`, `data.pkg`
- [ ] `pkg update` succeeds on pfSense
- [ ] Package is searchable: `pkg search Amneziawireguard`
- [ ] Package info is correct: `pkg rquery '%n-%v' pfSense-pkg-Amneziawireguard`
- [ ] Dry-run install works: `pkg install -n pfSense-pkg-Amneziawireguard`

### Quick Verification Script

Create this script to quickly verify your repository after each build:

```bash
#!/bin/sh
# filepath: verify-repo.sh

REPO_URL="http://172.16.13.11/awgrepo"
ABI="FreeBSD:15:amd64"  # Change to your target ABI

echo "=== Repository Verification ==="
echo ""

echo "1. Checking repository accessibility..."
if curl -s "${REPO_URL}/" | grep -q "Index of"; then
    echo "✓ Repository is accessible"
else
    echo "✗ Repository is NOT accessible"
    exit 1
fi

echo ""
echo "2. Checking architecture folder..."
if curl -s "${REPO_URL}/${ABI}/" | grep -q "packagesite.pkg"; then
    echo "✓ Architecture folder exists with packages"
else
    echo "✗ Architecture folder or packages missing"
    exit 1
fi

echo ""
echo "3. Downloading and checking package metadata..."
cd /tmp
rm -f packagesite.pkg packagesite.yaml
if fetch -q "${REPO_URL}/${ABI}/packagesite.pkg"; then
    tar -xf packagesite.pkg 2>/dev/null
    if grep -q "Amneziawireguard" packagesite.yaml 2>/dev/null; then
        VERSION=$(grep -A 3 "name: pfSense-pkg-Amneziawireguard" packagesite.yaml | grep "version:" | awk '{print $2}' | tr -d '"')
        echo "✓ Package found: pfSense-pkg-Amneziawireguard-${VERSION}"
    else
        echo "✗ Package not found in metadata"
        exit 1
    fi
else
    echo "✗ Could not download package metadata"
    exit 1
fi

echo ""
echo "4. Verifying package file exists..."
if curl -I -s "${REPO_URL}/${ABI}/pfSense-pkg-Amneziawireguard-${VERSION}.pkg" | grep -q "200 OK"; then
    echo "✓ Package file is downloadable"
else
    echo "✗ Package file is missing or inaccessible"
    exit 1
fi

echo ""
echo "=== All checks passed! ==="
echo ""
echo "Repository URL for pfSense: ${REPO_URL}/\${ABI}"
echo "Current package version: ${VERSION}"
```

Make it executable and run:
```bash
chmod +x verify-repo.sh
./verify-repo.sh
```

---

## Troubleshooting

### Issue: GitLab Runner Cannot Execute pkg Commands

**Symptoms**: Permission denied errors during package creation

**Solution**:
```bash
# Add gitlab-runner to wheel group
pw usermod gitlab-runner -G wheel

# Grant sudo access
echo 'gitlab-runner ALL=(ALL) NOPASSWD: /usr/local/sbin/pkg' >> /usr/local/etc/sudoers

# Restart runner
service gitlab-runner restart
```

### Issue: Web Server Returns 404

**Symptoms**: Cannot access repository URL

**Solution**:
```bash
# Check if web server is running
service nginx status  # or apache24/lighttpd

# Verify directory exists and has correct permissions
ls -la /usr/local/www/awgrepo
chown -R www:www /usr/local/www/awgrepo

# Check nginx/apache error logs
tail -f /var/log/nginx/error.log
```

### Issue: pfSense Cannot Find Repository

**Symptoms**: `pkg update` doesn't show new repository

**Solution**:
```bash
# Verify repository configuration
cat /usr/local/etc/pkg/repos/Amneziawireguard.conf

# Test connectivity
ping 172.16.13.11
curl http://172.16.13.11/awgrepo/

# Force repository update
pkg update -f

# Check pkg configuration
pkg -vv
```

### Issue: Package Has Wrong Architecture

**Symptoms**: Package built for wrong ABI

**Solution**:
```bash
# On builder system, verify ABI
pkg config abi

# Should match pfSense ABI (usually FreeBSD:13:amd64)
# Ensure builder runs FreeBSD 13.x matching pfSense version
```

### Issue: Pipeline Fails During Build

**Symptoms**: CI/CD pipeline shows errors

**Solution**:
```bash
# Check GitLab CI logs for specific error
# Common issues:

# Missing files
ls -la files/ metadir/ pkg-plist

# Invalid manifest
cat metadir/+MANIFEST | jq .

# Runner not available
# Go to GitLab: Settings → CI/CD → Runners
# Verify runner is online
```

### Issue: Package Installation Fails on pfSense

**Symptoms**: Installation errors or conflicts

**Solution**:
```bash
# Check for conflicting packages
pkg info | grep -i wireguard

# View detailed error
pkg install -y pfSense-pkg-Amneziawireguard

# Check package dependencies
pkg info -d pfSense-pkg-Amneziawireguard

# Verify package integrity
pkg check -s pfSense-pkg-Amneziawireguard
```

### Issue: Multiple Architecture Folders Created

**Symptoms**: Seeing multiple FreeBSD version folders (FreeBSD:14:amd64, FreeBSD:15:amd64, etc.)

**Cause**: The pipeline runs on FreeBSD 15, but multiple builds or the `--strip-components` flag preserves old folders.

**Solution**:

```bash
# Option 1: Clean old versions before deployment (recommended)
# Add to .gitlab-ci.yml before extraction:
- echo "Cleaning old repository data"
- sudo rm -rf ${WEB_REPO_PATH}/FreeBSD:*

# Option 2: Keep multiple versions for compatibility
# No action needed - this allows different pfSense versions to use the repo
# pfSense will automatically use the matching ABI

# Option 3: Manually clean on server
ssh root@172.16.13.11
cd /usr/local/www/awgrepo
rm -rf FreeBSD:14:amd64 FreeBSD:16:amd64  # Keep only your target version
```

**Verification**:
```bash
# Check which architectures are present
curl http://172.16.13.11/awgrepo/ | grep "FreeBSD"

# Verify your target architecture has files
curl http://172.16.13.11/awgrepo/FreeBSD:15:amd64/
```

---

## Advanced Configuration

### Setting Up HTTPS for Repository

For production use, enable HTTPS:

```bash
# Install certbot
pkg install -y py39-certbot

# Get certificate (requires domain name)
certbot certonly --standalone -d repo.yourdomain.com

# Update nginx configuration
# Add SSL directives to server block
```

### Versioning Strategy

**Semantic Versioning**: Use MAJOR.MINOR.PATCH format

- **MAJOR**: Breaking changes
- **MINOR**: New features, backwards compatible
- **PATCH**: Bug fixes

Example workflow:
```bash
# Update version in .gitlab-ci.yml
PKG_VERSION: "1.2.0"

# Tag release in Git
git tag -a v1.2.0 -m "Release version 1.2.0"
git push origin v1.2.0
```

### Multi-Architecture Support

To support multiple architectures:

```yaml
build_amd64:
  script:
    # ...existing build commands...
  tags:
    - freebsd-runner-amd64

build_arm64:
  script:
    # ...existing build commands...
  tags:
    - freebsd-runner-arm64
```

### Backup Strategy

```bash
# Automated backup script
#!/bin/sh
BACKUP_DIR="/backup/awgrepo"
REPO_DIR="/usr/local/www/awgrepo"
DATE=$(date +%Y%m%d)

mkdir -p ${BACKUP_DIR}
tar -czf ${BACKUP_DIR}/awgrepo-${DATE}.tar.gz -C ${REPO_DIR} .

# Keep only last 30 days
find ${BACKUP_DIR} -name "awgrepo-*.tar.gz" -mtime +30 -delete
```

---

## Maintenance

### Regular Tasks

**Weekly**:
- Check GitLab Runner status
- Verify web server logs for errors
- Monitor disk space on repository server

**Monthly**:
- Update FreeBSD system: `pkg update && pkg upgrade`
- Review and clean old package versions
- Test package installation on fresh pfSense

**Per Release**:
- Update PKG_VERSION in `.gitlab-ci.yml`
- Tag release in Git
- Update CHANGELOG
- Test installation thoroughly

### Monitoring

Set up basic monitoring:

```bash
# Check repository size
du -sh /usr/local/www/awgrepo

# Monitor web server access
tail -f /var/log/nginx/access.log | grep awgrepo

# Check package downloads
grep "\.pkg" /var/log/nginx/access.log | wc -l
```

---

## Appendix

### Useful Commands Reference

```bash
# Package Management
pkg info                              # List installed packages
pkg search <name>                     # Search for package
pkg install <name>                    # Install package
pkg delete <name>                     # Remove package
pkg update                            # Update repository catalogs
pkg upgrade                           # Upgrade installed packages

# Repository Management
pkg repo <path>                       # Create repository metadata
pkg -vv                              # Show detailed pkg configuration
pkg config abi                        # Show system ABI

# GitLab Runner
gitlab-runner list                    # List registered runners
gitlab-runner verify                  # Verify runner connection
service gitlab-runner status          # Check runner service status

# Web Server
service nginx configtest              # Test nginx configuration
service nginx reload                  # Reload nginx configuration
tail -f /var/log/nginx/access.log    # Monitor access logs
```

### File Structure Reference

```
AmneziaWG-Pfsense/
├── amneziawg/
│   ├── .gitlab-ci.yml               # CI/CD configuration
│   ├── files/                       # Application files
│   │   └── usr/
│   │       └── local/
│   │           └── etc/
│   │               └── pkg/
│   │                   └── repos/
│   │                       └── Amneziawireguard.conf
│   ├── metadir/                     # Package metadata
│   │   └── +MANIFEST                # Package manifest
│   ├── pkg-plist                    # File listing
│   └── SETUP_TUTORIAL.md           # This file
```

### Additional Resources

- [FreeBSD Handbook - Packages](https://docs.freebsd.org/en/books/handbook/ports/)
- [GitLab CI/CD Documentation](https://docs.gitlab.com/ee/ci/)
- [pfSense Package Development](https://docs.netgate.com/pfsense/en/latest/development/)
- [pkg(8) Manual Page](https://man.freebsd.org/cgi/man.cgi?pkg)

---

## Support and Contributing

For issues, questions, or contributions:
- Open an issue in the GitLab repository
- Submit merge requests for improvements
- Contact the maintainers

**Last Updated**: 2024
**Version**: 1.0.0