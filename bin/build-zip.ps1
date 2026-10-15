$ErrorActionPreference = "Stop"

$pluginDir = $PSScriptRoot | Split-Path -Parent
$distDir = Join-Path $pluginDir "dist"
$buildDir = Join-Path $distDir "billio"
$zipPath = Join-Path $pluginDir "billio.zip"

if (Test-Path $distDir) {
    Remove-Item -Recurse -Force $distDir
}
if (Test-Path $zipPath) {
    Remove-Item -Force $zipPath
}

New-Item -ItemType Directory -Path $buildDir -Force | Out-Null

$copyItems = @(
    "assets",
    "inc",
    "languages",
    "templates",
    "vendor",
    "billio.php",
    "readme.txt",
    "uninstall.php",
    "license.txt",
    "composer.json"
)

foreach ($item in $copyItems) {
    $src = Join-Path $pluginDir $item
    if (Test-Path $src) {
        Copy-Item -Recurse -Force $src $buildDir
    }
}

# Remove development-only tools from vendor inside buildDir
$devVendorFolders = @(
    "vendor/bin",
    "vendor/squizlabs",
    "vendor/wp-coding-standards",
    "vendor/dealerdirect",
    "vendor/phpcsstandards"
)

foreach ($devFolder in $devVendorFolders) {
    $target = Join-Path $buildDir $devFolder
    if (Test-Path $target) {
        Remove-Item -Recurse -Force $target
    }
}

# Remove unwanted dev files, git, tests, docs
Get-ChildItem -Path $buildDir -Recurse -Include ".git*", "tests", "Tests", "test", "*.md", "*.yml", "*.xml" | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue

Compress-Archive -Path $buildDir -DestinationPath $zipPath -Force

Remove-Item -Recurse -Force $distDir

Write-Host "Successfully built: $zipPath"
$size = (Get-Item $zipPath).Length / 1MB
Write-Host ("Zip file size: {0:N2} MB" -f $size)
