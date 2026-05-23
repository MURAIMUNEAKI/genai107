metadata name = 'Azure Functions Private Endpoint'
metadata description = 'Creates a Private Endpoint for Azure Functions with Private DNS Zone integration'

@description('Name of the Function App')
param functionAppName string

@description('Resource ID of the Function App')
param functionAppId string

@description('Primary location for the Private Endpoint')
param location string = resourceGroup().location

@description('Tags for the Private Endpoint resources')
param tags object = {}

@description('Subnet ID for the Private Endpoint')
param privateEndpointSubnetId string

@description('Virtual Network ID for DNS Zone link')
param virtualNetworkId string

@description('Static Private IP Address (optional). If specified, this IP will be assigned to the Private Endpoint.')
param staticPrivateIpAddress string = ''

// Private Endpoint for Azure Functions
resource privateEndpoint 'Microsoft.Network/privateEndpoints@2024-05-01' = {
  name: '${functionAppName}-pe'
  location: location
  tags: tags
  properties: {
    subnet: {
      id: privateEndpointSubnetId
    }
    // 静的Private IP設定（オプション）
    ipConfigurations: !empty(staticPrivateIpAddress) ? [
      {
        name: 'ipconfig1'
        properties: {
          groupId: 'sites'
          memberName: 'sites'
          privateIPAddress: staticPrivateIpAddress
        }
      }
    ] : []
    privateLinkServiceConnections: [
      {
        name: '${functionAppName}-pe-connection'
        properties: {
          privateLinkServiceId: functionAppId
          groupIds: [
            'sites'
          ]
        }
      }
    ]
  }
}

// Private DNS Zone for Azure Functions (privatelink.azurewebsites.net)
resource privateDnsZone 'Microsoft.Network/privateDnsZones@2020-06-01' = {
  name: 'privatelink.azurewebsites.net'
  location: 'global'
  tags: tags
}

// Private DNS Zone Group (links Private Endpoint to DNS Zone)
resource privateDnsZoneGroup 'Microsoft.Network/privateEndpoints/privateDnsZoneGroups@2024-05-01' = {
  name: 'default'
  parent: privateEndpoint
  properties: {
    privateDnsZoneConfigs: [
      {
        name: 'config1'
        properties: {
          privateDnsZoneId: privateDnsZone.id
        }
      }
    ]
  }
}

// Link Private DNS Zone to VNet
resource privateDnsZoneVnetLink 'Microsoft.Network/privateDnsZones/virtualNetworkLinks@2020-06-01' = {
  name: '${functionAppName}-vnet-link'
  parent: privateDnsZone
  location: 'global'
  tags: tags
  properties: {
    registrationEnabled: false
    virtualNetwork: {
      id: virtualNetworkId
    }
  }
}

// Outputs
output privateEndpointId string = privateEndpoint.id
output privateEndpointName string = privateEndpoint.name
output privateDnsZoneId string = privateDnsZone.id
// 静的IPが指定されている場合はそれを返し、指定されていない場合は空文字列
// 実際に割り当てられたIPを取得するには、デプロイ後にNetwork Interfaceから取得する必要があります
output privateIpAddress string = !empty(staticPrivateIpAddress) ? staticPrivateIpAddress : ''
