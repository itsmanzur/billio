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

# Refresh languages/billio.pot from the current source before anything is
# copied, so the zip never ships a .pot whose Project-Id-Version has
# drifted behind billio.php's own Version header (this happened once:
# the .pot sat at a stale version for a couple of releases because
# bin/make-pot.php was only ever run by hand, on no fixed schedule).
if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw "php is not on PATH - needed to run bin/make-pot.php and Composer for the production build."
}

& php (Join-Path $pluginDir "bin\make-pot.php")
if ($LASTEXITCODE -ne 0) {
    throw "bin/make-pot.php failed (exit $LASTEXITCODE)."
}

$billioPhpContent = Get-Content (Join-Path $pluginDir "billio.php") -Raw
$pluginVersion     = [regex]::Match($billioPhpContent, 'Version:\s*([0-9.]+)').Groups[1].Value
$potContent        = Get-Content (Join-Path $pluginDir "languages\billio.pot") -Raw
$potVersion        = [regex]::Match($potContent, 'Project-Id-Version:\s*Billio\s+([0-9.]+)').Groups[1].Value

if (-not $pluginVersion) {
    throw "Could not read a Version from billio.php - refusing to build with an unverified .pot version."
}
if ($potVersion -ne $pluginVersion) {
    throw "languages/billio.pot's Project-Id-Version ($potVersion) does not match billio.php's Version ($pluginVersion) even after regenerating it - bin/make-pot.php may be broken. Not building."
}
Write-Host "languages/billio.pot regenerated at version $pluginVersion."

# Guard against shipping a .mo that's stale relative to its .po — unlike
# the .pot above, the .mo isn't auto-recompiled here (compiling bakes in
# a human translator's actual work, so that step stays manual/explicit,
# see bin/compile-mo.php), but a .po edited after the last compile and
# never recompiled is a real, easy-to-miss mistake worth failing loudly on.
$poPath = Join-Path $pluginDir "languages\billio-bn_BD.po"
$moPath = Join-Path $pluginDir "languages\billio-bn_BD.mo"
if ((Test-Path $poPath) -and (Test-Path $moPath)) {
    $poTime = (Get-Item $poPath).LastWriteTimeUtc
    $moTime = (Get-Item $moPath).LastWriteTimeUtc
    if ($poTime -gt $moTime) {
        throw "billio-bn_BD.mo is older than .po - recompile (php bin/compile-mo.php) before building."
    }
}

# The bundled Noto Sans Bengali font (fonts/*.ttf, already in $copyItems
# below) is SIL OFL 1.1 — that license requires the license text itself
# to travel with the font. Fail the build rather than ship attribution-less
# font files.
$oflPath = Join-Path $pluginDir "fonts\OFL.txt"
if (-not (Test-Path $oflPath)) {
    throw "fonts/OFL.txt is missing - the bundled Noto Sans Bengali font's SIL OFL 1.1 license text must ship alongside it. Add fonts/OFL.txt (from https://fonts.google.com/noto/specimen/Noto+Sans+Bengali or https://scripts.sil.org/OFL) before building."
}

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
# copying the dev vendor/ folder. (php itself was already confirmed above.)
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

# Double-check the license text actually landed inside the zip itself
# (not just the source tree) — catches it ever getting swept up by the
# dev-file exclude filters above, or any other packaging mistake.
Add-Type -AssemblyName System.IO.Compression.FileSystem
$verifyZip = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    $hasOfl = $null -ne $verifyZip.GetEntry("billio/fonts/OFL.txt")
} finally {
    $verifyZip.Dispose()
}
if (-not $hasOfl) {
    throw "billio.zip was built but does not contain billio/fonts/OFL.txt - the font's license text didn't make it into the package. Not shipping this zip."
}

Write-Host "Successfully built: $zipPath"
$size = (Get-Item $zipPath).Length / 1MB
Write-Host ("Zip file size: {0:N2} MB" -f $size)
