param name string
param location string
param tags object

@description('The email address of the owner of the service')
@minLength(1)
param publisherEmail string

@description('The name of the owner of the service')
@minLength(1)
param publisherName string

@description('Subnet Resource ID for APIM outbound VNet integration')
param vnetIntegrationSubnetId string

@description('The pricing tier of this API Management service')
@allowed([
  'Consumption'
  'Developer'
  'Standard'
  'Premium'
  'BasicV2'
  'StandardV2'
  'PremiumV2'
])
param sku string

@description('The instance size of this API Management service.')
@allowed([ 0, 1, 2 ])
param skuCount int

@description('Azure Application Insights Name')
param applicationInsightsName string
param workspaceId string

@description('生成AI入出力(全カテゴリ)をログ出力する場合は true。個別カテゴリ (DeveloperPortalAuditLogs, GatewayLlmLogs, WebSocketConnectionLogs) のみを有効にする場合は false。')
param enableGenAiIoLogging bool = false

@description('Secret value for APIM to App Gateway authentication')
@secure()
param apimSecret string = ''

@description('APIM Named Value reference for the Functions default host key. Example: {{func-code-interpreter-default-key}}')
param functionHostKeyNamedValue string = 'PLACEHOLDER-FUNCTION-HOST-KEY'

resource apimService 'Microsoft.ApiManagement/service@2024-05-01' = {
  name: name
  location: location
  tags: union(tags, { 'azd-service-name': name })
  sku: {
    name: sku
    capacity: (sku == 'Consumption') ? 0 : ((sku == 'Developer') ? 1 : skuCount)
  }
  identity: {
    type: 'SystemAssigned'
  }
  properties: {

    virtualNetworkConfiguration: {
      subnetResourceId: vnetIntegrationSubnetId
    }
    virtualNetworkType: 'External'

    publicNetworkAccess: 'Enabled'

    publisherEmail: publisherEmail
    publisherName: publisherName
    // Custom properties are not supported for Consumption SKU
    customProperties: sku == 'Consumption' ? {} : {
      'Microsoft.WindowsAzure.ApiManagement.Gateway.Security.Ciphers.TLS_ECDHE_RSA_WITH_AES_256_CBC_SHA': 'false'
      'Microsoft.WindowsAzure.ApiManagement.Gateway.Security.Ciphers.TLS_ECDHE_RSA_WITH_AES_128_CBC_SHA': 'false'
      'Microsoft.WindowsAzure.ApiManagement.Gateway.Security.Ciphers.TLS_RSA_WITH_AES_128_GCM_SHA256': 'false'
      'Microsoft.WindowsAzure.ApiManagement.Gateway.Security.Ciphers.TLS_RSA_WITH_AES_256_CBC_SHA256': 'false'
      'Microsoft.WindowsAzure.ApiManagement.Gateway.Security.Ciphers.TLS_RSA_WITH_AES_128_CBC_SHA256': 'false'
      'Microsoft.WindowsAzure.ApiManagement.Gateway.Security.Ciphers.TLS_RSA_WITH_AES_256_CBC_SHA': 'false'
      'Microsoft.WindowsAzure.ApiManagement.Gateway.Security.Ciphers.TLS_RSA_WITH_AES_128_CBC_SHA': 'false'
      'Microsoft.WindowsAzure.ApiManagement.Gateway.Security.Ciphers.TripleDes168': 'false'
      'Microsoft.WindowsAzure.ApiManagement.Gateway.Security.Protocols.Tls10': 'false'
      'Microsoft.WindowsAzure.ApiManagement.Gateway.Security.Protocols.Tls11': 'false'
      'Microsoft.WindowsAzure.ApiManagement.Gateway.Security.Protocols.Ssl30': 'false'
      'Microsoft.WindowsAzure.ApiManagement.Gateway.Security.Backend.Protocols.Tls10': 'false'
      'Microsoft.WindowsAzure.ApiManagement.Gateway.Security.Backend.Protocols.Tls11': 'false'
      'Microsoft.WindowsAzure.ApiManagement.Gateway.Security.Backend.Protocols.Ssl30': 'false'
    }
  }
}

// Named Value for APIM secret (used for App Gateway WAF authentication)
resource apimSecretNamedValue 'Microsoft.ApiManagement/service/namedValues@2024-05-01' = if (!empty(apimSecret)) {
  name: 'apim-secret'
  parent: apimService
  properties: {
    displayName: 'apim-secret'
    secret: true
    value: apimSecret
  }
}

resource funcHostKeyNamedValue 'Microsoft.ApiManagement/service/namedValues@2024-05-01' = {
  name: 'function-app-key'
  parent: apimService
  properties: {
    displayName: 'function-app-key'
    secret: true
    value: functionHostKeyNamedValue
  }
}

// 診断設定
resource diagnosticsSettings 'Microsoft.Insights/diagnosticSettings@2021-05-01-preview' = {
  name: '${apimService.name}-diagnosticsSetting'
  scope: apimService
  properties: {
    workspaceId: workspaceId
    logAnalyticsDestinationType: 'Dedicated'
    // enableGenAiIoLogging が true の場合は categoryGroup ベースで全体/監査ログを有効化
    // false の場合は個別カテゴリ (生成AI I/O を含まない) のみ
    logs: enableGenAiIoLogging ? [
      {
        categoryGroup: 'allLogs'
        enabled: true
        retentionPolicy: {
          enabled: true
        }
      }
      {
        categoryGroup: 'audit'
        enabled: true
        retentionPolicy: {
          enabled: true
        }
      }
    ] : [
      {
        category: 'DeveloperPortalAuditLogs'
        enabled: true
        retentionPolicy: {
          enabled: true
        }
      }
      {
        category: 'GatewayLlmLogs'
        enabled: true
        retentionPolicy: {
          enabled: true
        }
      }
      {
        category: 'WebSocketConnectionLogs'
        enabled: true
        retentionPolicy: {
          enabled: true
        }
      }
    ]
    metrics: [
      {
        category: 'AllMetrics'
        enabled: true
      }
    ]
  }
}

// Azure Monitorを用いる診断設定
resource apimLogger 'Microsoft.ApiManagement/service/loggers@2022-08-01' = {
  parent: apimService
  name: 'azuremonitor'
  properties: {
    loggerType: 'azureMonitor'
    isBuffered: true
  }
}


resource apiDiagnostics 'Microsoft.ApiManagement/service/diagnostics@2022-08-01' = {
  name: 'azuremonitor'
  parent: apimService
  properties: {
    alwaysLog: 'allErrors'
    backend: {
      request: {
        body: {
          bytes: 1024
        }
        headers: [
          'unique_name'
        ]
      }
      response: {
        body: {
          bytes: 1024
        }
      }
    }
    frontend: {
      request: {
        body: {
          bytes: 1024
        }
      }
      response: {
        body: {
          bytes: 1024
        }
      }
    }
    logClientIp: true
    loggerId: apimLogger.id
    metrics: true
    sampling: {
      percentage: 100
      samplingType: 'fixed'
    }
    verbosity: 'verbose'
  }
}


// application insights
resource appInsightsComponents 'Microsoft.Insights/components@2020-02-02' = {
  name: applicationInsightsName
  location: location
  kind: 'other'
  properties: {
    Application_Type: 'other'
  }
}

// Application Insightsを用いる診断設定
resource apiManagement_logger_appInsights 'Microsoft.ApiManagement/service/loggers@2019-01-01' = {
  parent: apimService
  name: 'applicationInsights'
  properties: {
    loggerType: 'applicationInsights'
    credentials: {
      instrumentationKey: reference(appInsightsComponents.id, '2015-05-01').InstrumentationKey
    }
  }
}

resource apiManagement_diagnostics_appInsights 'Microsoft.ApiManagement/service/diagnostics@2019-01-01' = {
  parent: apimService
  name: 'applicationinsights'
  properties: {
    alwaysLog: 'allErrors'
    loggerId: apiManagement_logger_appInsights.id
    sampling: {
      samplingType: 'fixed'
      percentage: 100
    }
  }
}

output apimServiceName string = apimService.name
output identityPrincipalId string = apimService.identity.principalId
