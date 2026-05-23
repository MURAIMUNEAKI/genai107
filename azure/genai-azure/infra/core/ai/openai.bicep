@description('Name of the Azure OpenAI resource')
param name string

@description('Location for the Azure OpenAI resource')
param location string = resourceGroup().location

@description('Tags to apply to the Azure OpenAI resource')
param tags object = {}

@description('SKU name for Azure OpenAI (S0 = Standard)')
param skuName string = 'S0'

@description('Model deployment name (e.g., gpt-4o)')
param deploymentName string = 'gpt-4o'

@description('Deployment type (Standard, GlobalStandard, ProvisionedManaged, GlobalProvisionedManaged, DataZoneStandard, DataZoneProvisionedManaged)')
param deploymentType string = 'Standard'

@description('Model name to deploy')
param modelName string = 'gpt-4o'

@description('Model version to deploy')
param modelVersion string = '2024-11-20'

@description('Model capacity (TPM in thousands, e.g., 10 = 10K TPM)')
param modelCapacity int = 10

@description('Public network access setting (Enabled or Disabled)')
@allowed(['Enabled', 'Disabled'])
param publicNetworkAccess string = 'Disabled'

@description('Subnet ID for Private Endpoint')
param privateEndpointSubnetId string = ''

@description('Enable Private Endpoint')
param enablePrivateEndpoint bool = true

@description('Virtual Network IDs allowed to access (when public access is enabled)')
param allowedVirtualNetworkResourceIds array = []

@description('IP rules for network access')
param allowedIpRules array = []

// Azure OpenAI Service (Cognitive Services)
resource openai 'Microsoft.CognitiveServices/accounts@2023-05-01' = {
  name: name
  location: location
  tags: tags
  kind: 'OpenAI'
  sku: {
    name: skuName
  }
  identity: {
    type: 'SystemAssigned'
  }
  properties: {
    customSubDomainName: name
    publicNetworkAccess: publicNetworkAccess
    networkAcls: {
      defaultAction: (publicNetworkAccess == 'Disabled' || !empty(allowedIpRules)) ? 'Deny' : 'Allow'
      virtualNetworkRules: [for vnetId in allowedVirtualNetworkResourceIds: {
        id: vnetId
        ignoreMissingVnetServiceEndpoint: false
      }]
      ipRules: [for ipRule in allowedIpRules: {
        value: ipRule
      }]
    }
    disableLocalAuth: false  // Managed Identity を推奨するが、互換性のため false
  }
}

// GPT-4o Model Deployment
// Deployment types: Standard, GlobalStandard, ProvisionedManaged, GlobalProvisionedManaged, DataZoneStandard, DataZoneProvisionedManaged
// See: https://learn.microsoft.com/azure/ai-foundry/foundry-models/concepts/deployment-types
resource deployment 'Microsoft.CognitiveServices/accounts/deployments@2023-05-01' = {
  name: deploymentName
  parent: openai
  sku: {
    name: deploymentType
    capacity: modelCapacity
  }
  properties: {
    model: {
      format: 'OpenAI'
      name: modelName
      version: modelVersion
    }
    raiPolicyName: 'Microsoft.Default'
  }
}

// Private Endpoint for Azure OpenAI
// Must wait for deployment to complete before creating Private Endpoint
resource privateEndpoint 'Microsoft.Network/privateEndpoints@2023-11-01' = if (enablePrivateEndpoint && !empty(privateEndpointSubnetId)) {
  name: '${name}-pe'
  location: location
  tags: tags
  dependsOn: [
    deployment
  ]
  properties: {
    subnet: {
      id: privateEndpointSubnetId
    }
    privateLinkServiceConnections: [
      {
        name: '${name}-pe-connection'
        properties: {
          privateLinkServiceId: openai.id
          groupIds: [
            'account'
          ]
        }
      }
    ]
  }
}

// Private DNS Zone for Azure OpenAI (privatelink.openai.azure.com)
resource privateDnsZone 'Microsoft.Network/privateDnsZones@2020-06-01' = if (enablePrivateEndpoint && !empty(privateEndpointSubnetId)) {
  name: 'privatelink.openai.azure.com'
  location: 'global'
  tags: tags
}

// Private DNS Zone Group (links Private Endpoint to DNS Zone)
resource privateDnsZoneGroup 'Microsoft.Network/privateEndpoints/privateDnsZoneGroups@2023-11-01' = if (enablePrivateEndpoint && !empty(privateEndpointSubnetId)) {
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
resource privateDnsZoneVnetLink 'Microsoft.Network/privateDnsZones/virtualNetworkLinks@2020-06-01' = if (enablePrivateEndpoint && !empty(privateEndpointSubnetId)) {
  name: '${name}-vnet-link'
  parent: privateDnsZone
  location: 'global'
  tags: tags
  properties: {
    registrationEnabled: false
    virtualNetwork: {
      id: split(privateEndpointSubnetId, '/subnets/')[0]
    }
  }
}

// Outputs
output openaiId string = openai.id
output openaiName string = openai.name
output openaiEndpoint string = openai.properties.endpoint
output openaiPrincipalId string = openai.identity.principalId
output deploymentName string = deployment.name
output privateEndpointId string = enablePrivateEndpoint && !empty(privateEndpointSubnetId) ? privateEndpoint.id : ''
output privateDnsZoneId string = enablePrivateEndpoint && !empty(privateEndpointSubnetId) ? privateDnsZone.id : ''

@description('Azure OpenAI API Key (primary)')
@secure()
output openaiKey string = openai.listKeys().key1
