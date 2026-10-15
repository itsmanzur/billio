$ErrorActionPreference = "Stop"

# Builds billio.zip for distribution:
#   1. copies only the runtime files into dist/billio,
#   2. installs Composer dependencies there with --no-dev (so no dev
#      packages, and an autoloader/classmap that doesn't reference them),
#   3. zips with forward-slash entry names. Windows PowerShell 5.1's
#      Compress-Archive writes backslash paths, which unzip on Linux hosts
#      (and WordPress.org) as single files named "inc\Plugin.php".
#
# Usage: powershell -File bin/build-zip.ps1

$pluginDir = $PSScriptRoot | Split-Path -Parent
$distDir   = Join-Path $pluginDir "dist"
$buildDir  = Join-Path $distDir "billio"
$zipPath   = Join-Path $pluginDir "billio.zip"
$composer  = Join-Path $pluginDir "composer.phar"

if (Test-Path $distDir) {
    Remove-Item -Recurse -Force $distDir
}
if (Test-Path $zipPath) {
    Remove-Item -Force $zipPath
}

New-Item -ItemType Directory -Path $buildDir -Force | Out-Null

$copyItems = @(
    "assets",
    "fonts",
    "inc",
    "languages",
    "templates",
    "billio.php",
    "readme.txt",
    "uninstall.php",
    "license.txt",
    "composer.json",
    "composer.lock"
)

foreach ($item in $copyItems) {
    $src = Join-Path $pluginDir $item
    if (Test-Path $src) {
        Copy-Item -Recurse -Force $src $buildDir
    }
}

# Production dependencies only. Fail loudly rather than fall back to
# copying the dev vendor/ folder.
if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw "php is not on PATH - needed to run Composer for the production build."
}
if (-not (Test-Path $composer)) {
    throw "composer.phar not found at $composer"
}

& php $composer install --no-dev --optimize-autoloader --no-interaction --no-progress --working-dir="$buildDir"
if ($LASTEXITCODE -ne 0) {
    throw "composer install --no-dev failed (exit $LASTEXITCODE)."
}

# The lock file only matters for building; it isn't needed at runtime.
Remove-Item -Force (Join-Path $buildDir "composer.lock") -ErrorAction SilentlyContinue

# Remove unwanted dev files, git, tests, docs.
Get-ChildItem -Path $buildDir -Recurse -Force -Include ".git*", "tests", "Tests", "test", "*.md", "*.yml", "*.xml", "*.xml.dist" |
    Remove-Item -Recurse -Force -ErrorAction SilentlyContinue

# Zip with forward-slash entry names under a top-level "billio/" folder.
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    $baseLength = $distDir.TrimEnd('\', '/').Length + 1
    Get-ChildItem -Path $buildDir -Recurse -File -Force | ForEach-Object {
        $entryName = $_.FullName.Substring($baseLength).Replace('\', '/')
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip, $_.FullName, $entryName, [System.IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
    }
}
finally {
    $zip.Dispose()
}

Remove-Item -Recurse -Force $distDir

Write-Host "Successfully built: $zipPath"
$size = (Get-Item $zipPath).Length / 1MB
Write-Host ("Zip file size: {0:N2} MB" -f $size)
