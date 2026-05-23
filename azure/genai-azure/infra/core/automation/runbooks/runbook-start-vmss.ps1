param(
    [Parameter(Mandatory=$true)]
    [string]$ResourceGroupName,
    [Parameter(Mandatory=$true)]
    [string]$VMScaleSetName
)

try {
    Write-Output "Authenticating using managed identity..."
    Connect-AzAccount -Identity -ErrorAction Stop
    Write-Output "Authenticated: $(Get-AzContext).Account"
}
catch {
    Write-Error "Failed to authenticate using managed identity: $_"
    throw
}

if ((Get-Date).DayOfWeek -in 'Saturday','Sunday') {
    Write-Output 'Weekend detected - skipping start action.'
    return
}

try {
    Write-Output "Starting VMSS '$VMScaleSetName' in resource group '$ResourceGroupName'"
    Start-AzVmss  -ResourceGroupName $ResourceGroupName -VMScaleSetName $VMScaleSetName -ErrorAction Stop
    Write-Output "Start command issued successfully for VMSS '$VMScaleSetName'"
}
catch {
    Write-Error "Failed to start VMSS '$VMScaleSetName': $_"
    throw
}
