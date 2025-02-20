# CitadelQuest Release Process

This document outlines the process for creating and publishing CitadelQuest releases.

## Release Types

CitadelQuest uses two types of release packages:

1. **Pre-built Package (Release A)**
   - Full application with all dependencies
   - Pre-compiled assets and vendor libraries
   - Template database
   - Size: ~12MB
   - Filename format: `citadelquest-prebuilt-vX.Y.Z-alpha.zip`

2. **Installation Package (Release B)**
   - Lightweight installer script
   - Downloads and sets up the pre-built package
   - Size: ~8KB
   - Filename format: `citadelquest-installer-vX.Y.Z-alpha.zip`

## Release Process

### 1. Update Version Numbers

Update the version number in this single-source-of-truth:
```/src/CitadelVersion.php
private string $version = 'vX.Y.Z-alpha';
```

### 2. Create Release Branch/Tag

```bash
# Create and switch to a new release branch - skip this step for today
#git checkout -b release/vX.Y.Z-alpha

# Create release tag
git tag -a vX.Y.Z-alpha -m "CitadelQuest vX.Y.Z-alpha release"
```

### 3. Build Release Packages

```bash
# Build the pre-built package (Release A)
./build-release.sh

# Build the installer package (Release B)
./build-installer.sh
```

### 4. Push to GitHub

```bash
# Push the release branch - skip this step for today
#git push origin release/vX.Y.Z-alpha

# Push the tag
git push origin vX.Y.Z-alpha
```

### 5. Create GitHub Release

Option A: Using GitHub CLI (Recommended)
```bash
# Create the release with notes
gh release create "vX.Y.Z-alpha" \
  --title "CitadelQuest vX.Y.Z-alpha" \
  --notes-file - << 'EOF'
CitadelQuest vX.Y.Z-alpha Release

This release includes:
[List major changes and improvements]

## Release Packages

1. **Pre-built Package** (Release A):
   - Full application with all dependencies
   - Pre-compiled assets
   - Template database
   - Size: [size]

2. **Installation Package** (Release B):
   - Lightweight installer
   - Downloads and sets up the pre-built package
   - Size: [size]

### Installation
1. Download the Installation Package (`citadelquest-installer-vX.Y.Z-alpha.zip`)
2. Upload `install.php` to your web server
3. Access `install.php` through your web browser
4. The installer will automatically:
   - Check environment requirements
   - Download and extract the pre-built package
   - Set up the database
   - Configure permissions
EOF

# Upload release assets
gh release upload "vX.Y.Z-alpha" \
  citadelquest-prebuilt-vX.Y.Z-alpha.zip \
  citadelquest-installer-vX.Y.Z-alpha.zip
```

Option B: Using GitHub Web Interface
1. Go to https://github.com/CitadelQuest/CitadelQuest/releases/new
2. Choose the tag: `vX.Y.Z-alpha`
3. Title: `CitadelQuest vX.Y.Z-alpha`
4. Use the same release notes format as shown in Option A
5. Upload both release packages:
   - `citadelquest-prebuilt-vX.Y.Z-alpha.zip`
   - `citadelquest-installer-vX.Y.Z-alpha.zip`

## Build Scripts

### build-release.sh
Creates the pre-built package (Release A) containing:
- Full application code
- Vendor dependencies
- Pre-compiled assets
- Template database

### build-installer.sh
Creates the installation package (Release B) containing:
- `install.php`: Main installer script

## Post-Release

1. Verify both packages can be downloaded from GitHub
2. Test the installation process on a fresh server
3. Document any issues in GitHub
4. Update documentation if needed

## Version Naming Convention

Format: `vX.Y.Z-alpha`
- X: Major version (breaking changes)
- Y: Minor version (new features)
- Z: Patch version (bug fixes)
- alpha: Development stage

