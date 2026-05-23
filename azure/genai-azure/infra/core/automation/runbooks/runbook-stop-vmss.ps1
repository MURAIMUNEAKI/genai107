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
    Write-Output 'Weekend detected - skipping stop action.'
    return
}

try {
    Write-Output "Stopping VMSS '$VMScaleSetName' in resource group '$ResourceGroupName'"
    Stop-AzVmss -ResourceGroupName $ResourceGroupName -VMScaleSetName $VMScaleSetName -Force -ErrorAction Stop
    Write-Output "Stop command issued successfully for VMSS '$VMScaleSetName'"
}
catch {
    Write-Error "Failed to stop VMSS '$VMScaleSetName': $_"
    throw
}
