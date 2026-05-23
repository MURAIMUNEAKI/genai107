@description('Module to deploy empty Automation Runbooks (start/stop VMSS).')
param automationAccountName string
param location string
@description('Runbook (start) name')
param startRunbookName string
@description('Runbook (stop) name')
param stopRunbookName string

// Runbooks (content via publishContentLink)
// 空 Runbook (publishContentLink なし)をデプロイし、スクリプトとスケジュール設定はデプロイ完了後手動で実行
resource startRunbook 'Microsoft.Automation/automationAccounts/runbooks@2023-11-01' = {
  name: '${automationAccountName}/${startRunbookName}'
  location: location
  properties: {
    logProgress: true
    logVerbose: true
    runbookType: 'PowerShell'
    description: 'Start specified VM Scale Set'
  }
}

resource stopRunbook 'Microsoft.Automation/automationAccounts/runbooks@2023-11-01' = {
  name: '${automationAccountName}/${stopRunbookName}'
  location: location
  properties: {
    logProgress: true
    logVerbose: true
    runbookType: 'PowerShell'
    description: 'Stop specified VM Scale Set'
  }
}

output startRunbookDeployed string = startRunbook.name
output stopRunbookDeployed string = stopRunbook.name

