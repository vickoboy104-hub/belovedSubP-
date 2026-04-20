param(
    [Parameter(Mandatory = $true)]
    [string]$Owner,

    [Parameter(Mandatory = $true)]
    [string]$Repo,

    [string]$Branch = "main",

    [string]$Path = "tests",

    [string]$OutputDir = "downloaded-test-images",

    [string]$Token = $env:GITHUB_TOKEN
)

$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"

$imageExtensions = @(
    ".png", ".jpg", ".jpeg", ".gif", ".webp", ".bmp", ".svg", ".ico", ".avif"
)

function Get-GitHubHeaders {
    param([string]$AuthToken)

    $headers = @{
        "User-Agent" = "Codex-Image-Downloader"
        "Accept"     = "application/vnd.github+json"
    }

    if ($AuthToken) {
        $headers["Authorization"] = "Bearer $AuthToken"
    }

    return $headers
}

function Get-RepoItems {
    param(
        [string]$OwnerName,
        [string]$RepoName,
        [string]$RepoPath,
        [string]$Ref,
        [hashtable]$Headers
    )

    $encodedPath = [System.Uri]::EscapeDataString($RepoPath) -replace "%2F", "/"
    $uri = "https://api.github.com/repos/$OwnerName/$RepoName/contents/$encodedPath?ref=$Ref"
    return Invoke-RestMethod -Uri $uri -Headers $Headers -Method Get
}

function Download-ImagesRecursively {
    param(
        [string]$OwnerName,
        [string]$RepoName,
        [string]$RepoPath,
        [string]$Ref,
        [string]$TargetRoot,
        [hashtable]$Headers
    )

    $items = Get-RepoItems -OwnerName $OwnerName -RepoName $RepoName -RepoPath $RepoPath -Ref $Ref -Headers $Headers

    foreach ($item in @($items)) {
        if ($item.type -eq "dir") {
            Download-ImagesRecursively `
                -OwnerName $OwnerName `
                -RepoName $RepoName `
                -RepoPath $item.path `
                -Ref $Ref `
                -TargetRoot $TargetRoot `
                -Headers $Headers
            continue
        }

        if ($item.type -ne "file") {
            continue
        }

        $extension = [System.IO.Path]::GetExtension($item.name).ToLowerInvariant()
        if ($imageExtensions -notcontains $extension) {
            continue
        }

        $relativePath = $item.path.Substring($Path.Length).TrimStart("/")
        $destination = Join-Path $TargetRoot $relativePath
        $destinationDir = Split-Path -Parent $destination

        if ($destinationDir -and !(Test-Path $destinationDir)) {
            New-Item -ItemType Directory -Path $destinationDir -Force | Out-Null
        }

        Invoke-WebRequest -Uri $item.download_url -Headers $Headers -OutFile $destination
        Write-Host "Downloaded $($item.path) -> $destination"
    }
}

$headers = Get-GitHubHeaders -AuthToken $Token

if (!(Test-Path $OutputDir)) {
    New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
}

try {
    Download-ImagesRecursively `
        -OwnerName $Owner `
        -RepoName $Repo `
        -RepoPath $Path `
        -Ref $Branch `
        -TargetRoot (Resolve-Path $OutputDir).Path `
        -Headers $headers
}
catch {
    Write-Error @"
Failed to fetch files from GitHub.

Check:
1. The owner/repo/path/branch values are correct.
2. The repository is public, or you supplied a valid GitHub token.
3. Your token can read repository contents if the repo is private.

Original error:
$($_.Exception.Message)
"@
}
