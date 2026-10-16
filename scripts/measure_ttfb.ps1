$ErrorActionPreference = "Stop"

$base = $env:BASE_URL
if (-not $base) { $base = "http://127.0.0.1:8080/waamini" }
$base = $base.TrimEnd("/")

$pages = @(
  "/index.php",
  "/features.php",
  "/pricing.php",
  "/support.php",
  "/blogs.php",
  "/real-estate.php",
  "/login.php",
  "/register.php"
)

Write-Host "Measuring TRUE TTFB (ms) for: $base"
Write-Host "Note: prefers curl.exe timings (time_starttransfer) when available."

function Measure-TtfbCurl($url) {
  $exe = (Get-Command curl.exe -ErrorAction SilentlyContinue)
  if (-not $exe) { return $null }

  # Print headers to stdout so we can extract status + X-WA-Cache
  # -D -      : dump response headers to stdout
  # -o NUL    : discard body
  # -sS       : silent but show errors
  # -w        : append timing markers
  $txt = ""
  try {
    $out = & $exe.Source -D - -o NUL -s -w "`n__WA_TIME_START__:%{time_starttransfer}`n__WA_TIME_TOTAL__:%{time_total}`n" $url 2>&1
    $txt = ($out | Out-String)
  } catch {
    $txt = ($_ | Out-String)
  }

  $status = ""
  $cache = ""
  if ($txt -match "HTTP/\\S+\\s+(\\d{3})") { $status = $Matches[1] }
  if ($txt -match "(?im)^X-WA-Cache:\\s*([^\\r\\n]+)") { $cache = $Matches[1].Trim() }

  $tStart = $null
  if ($txt -match "__WA_TIME_START__:(\\d+(?:\\.\\d+)?)") { $tStart = [double]$Matches[1] }

  if ([string]::IsNullOrWhiteSpace($status)) { $status = "" }
  if ($tStart -eq $null) {
    if ([string]::IsNullOrWhiteSpace($status)) { $status = "ERR" }
    return @{ ok=$false; ms=0; url=$url; status=$status; cache=$cache }
  }
  if ([string]::IsNullOrWhiteSpace($status)) { $status = "200" }
  return @{ ok=$true; ms=[int]([Math]::Round($tStart * 1000.0)); url=$url; status=$status; cache=$cache }
}

Add-Type -AssemblyName System.Net.Http
$handler = New-Object System.Net.Http.HttpClientHandler
$handler.AutomaticDecompression = [System.Net.DecompressionMethods]::GZip -bor [System.Net.DecompressionMethods]::Deflate
$handler.UseProxy = $false
$handler.Proxy = $null
$client = New-Object System.Net.Http.HttpClient($handler)
$client.Timeout = [TimeSpan]::FromSeconds(30)

function Measure-TtfbDotNet($url) {
  $sw = [System.Diagnostics.Stopwatch]::StartNew()
  try {
    $req = New-Object System.Net.Http.HttpRequestMessage([System.Net.Http.HttpMethod]::Get, $url)
    $resp = $client.SendAsync($req, [System.Net.Http.HttpCompletionOption]::ResponseHeadersRead).GetAwaiter().GetResult()
  } catch {
    $sw.Stop()
    return @{ ok=$false; ms=$sw.ElapsedMilliseconds; url=$url; status="ERR"; cache="" }
  }
  $sw.Stop()
  $cache = ""
  if ($resp.Headers.Contains("X-WA-Cache")) { $cache = ($resp.Headers.GetValues("X-WA-Cache") | Select-Object -First 1) }
  $status = [int]$resp.StatusCode
  $resp.Dispose()
  return @{ ok=$true; ms=$sw.ElapsedMilliseconds; url=$url; status=$status; cache=$cache }
}

foreach ($p in $pages) {
  $url = "$base$p"
  $r = Measure-TtfbCurl $url
  if (-not $r) { $r = Measure-TtfbDotNet $url }
  if (-not $r.ok) {
    Write-Host ("{0,8}  {1}  {2}" -f $r.ms, $r.url, $r.status)
  } else {
    $cacheTag = if ($r.cache) { "cache=$($r.cache)" } else { "cache=-" }
    Write-Host ("{0,8}  {1}  {2}  {3}" -f $r.ms, $r.url, $r.status, $cacheTag)
  }
}

$client.Dispose()

