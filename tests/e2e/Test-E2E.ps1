# PrestaBridge E2E Test — PowerShell runner
param(
    [Parameter(Mandatory = $true)][string]$Secret,
    [string]$RouterUrl = "https://prestabridge-router.meriscrap.workers.dev/import",
    [string]$Sku = ("TEST-E2E-" + [DateTimeOffset]::UtcNow.ToUnixTimeSeconds())
)

$bodyObj = @{
    products  = @(@{
            sku               = $Sku
            name              = "Testowy rower E2E PrestaBridge"
            price             = 1299.99
            description       = "<p>Produkt testowy E2E - mozna usunac po weryfikacji.</p>"
            description_short = "Produkt testowy E2E"
            quantity          = 3
            weight            = 12.5
            active            = $true
            images            = @("https://picsum.photos/seed/$Sku/800/600", "https://picsum.photos/seed/${Sku}b/800/600")
        })
    batchSize = 5
}
$body = $bodyObj | ConvertTo-Json -Depth 5

# Generowanie HMAC-SHA256
$timestamp = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds().ToString()
$hmacKey = [System.Text.Encoding]::UTF8.GetBytes($Secret)
$hmacMsg = [System.Text.Encoding]::UTF8.GetBytes("$timestamp.$body")
$hmac = [System.Security.Cryptography.HMACSHA256]::new($hmacKey)
$sigHex = ($hmac.ComputeHash($hmacMsg) | ForEach-Object { $_.ToString("x2") }) -join ""
$authHeader = "$timestamp.$sigHex"

Write-Host ""
Write-Host "Wysylam do CF Router..." -ForegroundColor Yellow
Write-Host "SKU: $Sku"
Write-Host "Auth: $($authHeader.Substring(0,35))..."
Write-Host ""

try {
    $headers = @{ "Content-Type" = "application/json"; "X-PrestaBridge-Auth" = $authHeader }
    $response = Invoke-WebRequest -Uri $RouterUrl -Method POST -UseBasicParsing -Headers $headers -Body $body
    $json = $response.Content | ConvertFrom-Json
    Write-Host "HTTP $($response.StatusCode)" -ForegroundColor Green
    Write-Host ($json | ConvertTo-Json -Depth 5)

    if ($json.success) {
        $s = $json.summary
        Write-Host ""
        Write-Host "Przyjete: $($s.totalAccepted) | Odrzucone: $($s.totalRejected) | Paczki: $($s.batchesCreated)" -ForegroundColor Cyan
        Write-Host "Request ID: $($json.requestId)"
        Write-Host ""
        Write-Host "Produkt trafil do Queue. Sprawdz za ~15s w PS BO -> Katalog (SKU: $Sku)" -ForegroundColor Green
    }
    else {
        Write-Host "Router odrzucil zadanie - sprawdz AUTH_SECRET!" -ForegroundColor Red
    }
}
catch {
    Write-Host "Blad: $($_.Exception.Message)" -ForegroundColor Red
    try {
        $errBody = $_.Exception.Response.GetResponseStream()
        $reader = [System.IO.StreamReader]::new($errBody)
        Write-Host "Response: $($reader.ReadToEnd())"
    }
    catch {}
}
