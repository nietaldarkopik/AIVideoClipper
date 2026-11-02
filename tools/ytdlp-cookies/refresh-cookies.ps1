<#
.SYNOPSIS
  Refreshes the YouTube cookies yt-dlp uses (see backend .env
  YTDLP_COOKIES_FROM_BROWSER) straight from this machine's Chrome profile,
  automatically - no manual "export cookies.txt via a browser extension" step.

.DESCRIPTION
  yt-dlp's own --cookies-from-browser chrome fails outright with "Could not
  copy Chrome cookie database" whenever Chrome is actually running (Windows
  file-locks the live Cookies/Local State files against the kind of copy
  yt-dlp tries) - see https://github.com/yt-dlp/yt-dlp/issues/7271. That
  made the manual export-with-an-extension route (Get cookies.txt LOCALLY)
  the only working option, but exported cookies rotate/expire, so it has to
  be repeated by hand every time YouTube starts blocking downloads again
  with "Sign in to confirm you are not a bot" / "cookies are no longer valid".

  This script gets past the lock with robocopy instead of a plain file copy
  (robocopy retries past Chrome's transient locks; /R:2 /W:1 keeps that
  bounded rather than hanging) - copying just "Local State" (holds the
  DPAPI-wrapped key Chrome's cookie encryption uses) and the active
  profile's Cookies database into a throwaway temp directory that mirrors
  Chrome's own "User Data\<profile>" layout, which is what
  --cookies-from-browser expects when given a path instead of just a
  browser name. It then runs yt-dlp against that untouched copy with BOTH
  --cookies-from-browser (source) and --cookies (destination) set, which is
  yt-dlp's own documented way to dump browser cookies out to a portable
  Netscape-format file - the same file UrlVideoDownloader.php's
  cookiesFromBrowser setting already points at, so a worker restart is all
  that's needed afterward (php artisan queue:restart still won't
  auto-restart the workers on this unsupervised Windows setup - restart
  them manually, or ask Claude to).

.PARAMETER Profile
  Chrome profile folder name under "User Data" (default: Default - the main
  profile). Use "Profile 1", "Profile 2" etc. for a secondary Chrome profile.

.PARAMETER OutFile
  Where to write the refreshed Netscape cookies.txt. Defaults to the path
  backend/.env's YTDLP_COOKIES_FROM_BROWSER already points at.

.PARAMETER TestUrl
  A stable, always-public YouTube video used only to trigger yt-dlp's cookie
  extraction/export pass (--simulate, nothing is downloaded).

.EXAMPLE
  .\refresh-cookies.ps1
  Re-run any time downloads start failing with a cookies/bot-check error -
  or schedule it (see the paired ytdlp:refresh-cookies artisan command,
  which this script's exit code and stdout/stderr are designed to work with
  via Process::run) so it never needs to be run by hand again.
#>
param(
    [string]$Profile = "Default",
    [string]$OutFile = "C:\wamp64\www\clipper-tool\backend\storage\app\private\ytdlp-cookies.txt",
    [string]$TestUrl = "https://www.youtube.com/watch?v=jNQXAC9IVRw" # first video ever uploaded to YouTube - stable, always public
)

$ErrorActionPreference = "Stop"

# Chrome (since ~2024) holds its Cookies database under a continuous
# exclusive OS-level lock while running, specifically to make casual cookie
# theft by external tools harder - confirmed here: robocopy, esentutl (no
# /vss), and a plain retried Copy-Item were all tried first and every one
# hit "being used by another process" every single time, not just
# occasionally. `esentutl /y /vss` (reads through a Volume Shadow Copy
# snapshot instead of opening the file directly) is the one approach that
# gets past this without having to close Chrome - but creating a VSS
# snapshot is an admin-only operation, so this script must run elevated.
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Error "This script must run as Administrator (needed for esentutl's /vss snapshot, which is how it reads Chrome's cookie database without closing Chrome). Right-click refresh-cookies.bat and choose 'Run as administrator', or run PowerShell itself elevated first."
    exit 1
}

$chromeUserData = Join-Path $env:LOCALAPPDATA "Google\Chrome\User Data"
$localState = Join-Path $chromeUserData "Local State"
$profileDir = Join-Path $chromeUserData $Profile
$cookiesDb = Join-Path $profileDir "Network\Cookies"

if (-not (Test-Path $localState)) {
    Write-Error "Chrome 'Local State' not found at $localState - is Chrome installed for this Windows user?"
    exit 1
}
if (-not (Test-Path $cookiesDb)) {
    # Older Chrome versions kept Cookies directly under the profile folder,
    # not under Network\ - fall back rather than failing outright.
    $cookiesDb = Join-Path $profileDir "Cookies"
    if (-not (Test-Path $cookiesDb)) {
        Write-Error "No Cookies database found under profile '$Profile' ($profileDir). Pass -Profile if you use a non-default Chrome profile."
        exit 1
    }
}

$tempRoot = Join-Path $env:TEMP ("ytdlp-chrome-cookies-" + [guid]::NewGuid().ToString("N"))
$tempProfileDir = Join-Path $tempRoot $Profile
$tempNetworkDir = Join-Path $tempProfileDir "Network"
New-Item -ItemType Directory -Path $tempNetworkDir -Force | Out-Null

    function Copy-ChromeFile([string]$From, [string]$To) {
        $output = & esentutl.exe /y /vss $From /d $To /o 2>&1
        if (-not (Test-Path $To)) {
            Write-Host ($output -join "`n")
            throw "esentutl could not copy '$From' even with /vss"
        }
    }

try {
    Write-Host "Copying Chrome cookie data (past any lock)..."

    $destLocalState = Join-Path $tempRoot "Local State"
    Copy-ChromeFile -From $localState -To $destLocalState

    $cookiesDbDir = Split-Path $cookiesDb -Parent
    $destCookiesFile = if ($cookiesDbDir -like "*\Network") { Join-Path $tempNetworkDir "Cookies" } else { Join-Path $tempProfileDir "Cookies" }
    Copy-ChromeFile -From $cookiesDb -To $destCookiesFile

    Write-Host "Extracting cookies via yt-dlp..."
    $outDir = Split-Path $OutFile -Parent
    if (-not (Test-Path $outDir)) { New-Item -ItemType Directory -Path $outDir -Force | Out-Null }

    & yt-dlp --cookies-from-browser "chrome::$tempProfileDir" --cookies $OutFile --skip-download --simulate --no-warnings $TestUrl
    if ($LASTEXITCODE -ne 0) { throw "yt-dlp exited with code $LASTEXITCODE - see output above" }

    if ((-not (Test-Path $OutFile)) -or (Get-Item $OutFile).Length -eq 0) {
        throw "yt-dlp ran but $OutFile is missing or empty - no cookies were exported"
    }

    Write-Host "Cookies refreshed: $OutFile ($((Get-Item $OutFile).Length) bytes)"
    exit 0
}
finally {
    Remove-Item -Path $tempRoot -Recurse -Force -ErrorAction SilentlyContinue
}
