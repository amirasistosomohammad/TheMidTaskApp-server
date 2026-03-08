# Generate a 64-character hex token (32 bytes) for SCHEDULER_TOKEN
$bytes = New-Object byte[] 32
[Security.Cryptography.RNGCryptoServiceProvider]::Create().GetBytes($bytes)
$hex = -join ($bytes | ForEach-Object { '{0:x2}' -f $_ })
Write-Host $hex
