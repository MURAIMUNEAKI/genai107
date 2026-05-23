@description('Deploys networking components: VNet, subnets, NSG + rule, Public IP, Application Gateway (Standard_v2).\nApplication Gateway structure aligned closely with exported template while omitting read-only id properties.')
param location string
@description('Virtual Network name')
param vnetName string
@description('Address space (CIDR) for the VNet')
param addressSpace string
@description('Subnet for Application Gateway (dedicated)')
param appGatewaySubnetName string
@description('CIDR prefix for Application Gateway subnet')
param appGatewaySubnetPrefix string

@description('Private Endpoint subnet name')
param peSubnetName string
@description('Private Endpoint subnet prefix')
param peSubnetPrefix string

@description('Subnet for backend VMSS')
param vmssSubnetName string
@description('CIDR prefix for backend subnet')
param vmssSubnetPrefix string

@description('Subnet for Function App integration')
param functionSubnetName string
@description('CIDR prefix for Function App subnet')
param functionSubnetPrefix string

@description('NSG for apim integration subnet')
param apimIntegrationSubnetName string
@description('CIDR prefix for apim integration subnet')
param apimIntegrationSubnetPrefix string

@description('Public IP name for App Gateway')
param publicIpName string = 'appgw-pip'
@description('NSG name for backend subnet')
param backendNsgName string
@description('Static private IP for AppGW private frontend (must be in appGatewaySubnetPrefix)')
param appGatewayPrivateIpAddress string
@description('Application Gateway SKU name')
param appGatewaySku string
@description('Application Gateway name')
param appGatewayName string
@description('Backend address pool name (VMSS pool)')
param backendPoolName string
@description('HTTP settings name')
param httpSettingsName string
@description('Probe name')
param probeName string
@description('Listener / Rule priority (e.g. 100)')
param rulePriority int
@description('Request routing rule name')
param ruleName string
@description('Frontend port (HTTP)')
param frontendPort int
@description('Probe path')
param probePath string
@description('Probe interval seconds')
param probeInterval int
@description('Probe timeout seconds')
param probeTimeout int
@description('Probe unhealthy threshold')
param probeUnhealthyThreshold int
@description('App Gateway instance capacity (Standard_v2)')
param appGatewayCapacity int

@description('Azure Functions default hostname (FQDN) for backend pool')
param functionAppHostname string = ''
@description('Private IP address of Functions Private Endpoint (when enabled)')
param functionPrivateEndpointIp string = ''
@description('Functions backend pool name')
param functionBackendPoolName string = 'functionBackendPool'
@description('Functions HTTP settings name')
param functionHttpSettingsName string = 'functionHttpSettings'
@description('Functions probe name')
param functionProbeName string = 'functionProbe'
@description('Functions probe path')
param functionProbePath string = '/code-interpreter/health'

@description('OpenAI probe name')
param openaiProbeName string = 'openaiProbe'
@description('OpenAI probe path (uses AOAI /openai/health-style endpoint)')
param openaiProbePath string = '/openai/health'

@description('Azure OpenAI public FQDN (e.g. myaoai.openai.azure.com) used as TLS hostname')
param openaiPublicFqdn string = ''

@description('Backend API authentication header name for WAF')
param wafRequiredHeaderName string = 'x-apim-secret'
@description('Backend API authentication header value for WAF (change this!)')
@secure()
param wafRequiredHeaderValue string


// VNet
resource vnet 'Microsoft.Network/virtualNetworks@2023-09-01' = {
  name: vnetName
  location: location
  properties: {
    addressSpace: {
      addressPrefixes: [ addressSpace ]
    }
    subnets: [
      {
        name: appGatewaySubnetName
        properties: {
          addressPrefix: appGatewaySubnetPrefix
        }
      }
      {
        name: peSubnetName
        properties: {
          addressPrefix: peSubnetPrefix
        }
      }
      {
        name: vmssSubnetName
        properties: {
          addressPrefix: vmssSubnetPrefix
          networkSecurityGroup: {
            id: backendNsg.id
          }
        }
      }
      {
        name: functionSubnetName
        properties: {
          addressPrefix: functionSubnetPrefix
          networkSecurityGroup: {
            id: backendNsg.id
          }
          delegations: [
            {
              name: 'functionAppDelegation'
              properties: {
                serviceName: 'Microsoft.App/environments'
              }
            }
          ]
          serviceEndpoints: [
            {
              service: 'Microsoft.Storage'
              locations: [location]
            }
          ]
        }
      }
      {
        name: apimIntegrationSubnetName
        properties: {
          addressPrefix: apimIntegrationSubnetPrefix
          delegations: [
            {
              name: 'apimOutboundVnetIntegration'
              properties: {
                serviceName: 'Microsoft.Web/serverFarms'
              }
            }
          ]
          networkSecurityGroup: {
            id: apimIntegrationNsg.id
          }
        }
      }
    ]
  }
}

// Backend NSG
resource backendNsg 'Microsoft.Network/networkSecurityGroups@2023-09-01' = {
  name: backendNsgName
  location: location
  properties: {}
}

// Allow VNet inbound HTTP
resource backendNsgRuleAllowVnetHttp 'Microsoft.Network/networkSecurityGroups/securityRules@2023-09-01' = {
  name: 'allow-vnet-http'
  parent: backendNsg
  properties: {
    priority: 200
    direction: 'Inbound'
    access: 'Allow'
    protocol: 'Tcp'
    sourceAddressPrefix: 'VirtualNetwork'
    sourcePortRange: '*'
    destinationAddressPrefix: '*'
    destinationPortRange: '80'
  }
}

resource apimIntegrationNsg 'Microsoft.Network/networkSecurityGroups@2023-09-01' = {
  name: '${appGatewayName}-apimint-nsg'
  location: location
  properties: {
    securityRules: [
      {
        name: 'Allow_Storage_Out_443'
        properties: {
          direction: 'Outbound'
          access: 'Allow'
          priority: 100
          protocol: 'Tcp'
          sourceAddressPrefix: 'VirtualNetwork'
          sourcePortRange: '*'
          destinationAddressPrefix: 'Storage'
          destinationPortRange: '443'
        }
      }
      {
        name: 'Allow_AppGateway_Out_80'
        properties: {
          direction: 'Outbound'
          access: 'Allow'
          priority: 110
          protocol: 'Tcp'
          sourceAddressPrefix: apimIntegrationSubnetPrefix
          sourcePortRange: '*'
          destinationAddressPrefix: appGatewaySubnetPrefix
          destinationPortRange: '80'
        }
      }
      {
        name: 'Allow_AppGateway_Out_443'
        properties: {
          direction: 'Outbound'
          access: 'Allow'
          priority: 120
          protocol: 'Tcp'
          sourceAddressPrefix: apimIntegrationSubnetPrefix
          sourcePortRange: '*'
          destinationAddressPrefix: appGatewaySubnetPrefix
          destinationPortRange: '443'
        }
      }
    ]
  }
}

resource publicIp 'Microsoft.Network/publicIPAddresses@2023-09-01' = {
  name: publicIpName
  location: location
  sku: { name: 'Standard' }
  properties: {
    publicIPAllocationMethod: 'Static'
  }
}

resource wafPolicy 'Microsoft.Network/applicationGatewayWebApplicationFirewallPolicies@2024-03-01' = {
  name: '${appGatewayName}-wafpolicy'
  location: location
  properties: {
    policySettings: {
      state: 'Enabled'
      mode: 'Prevention'
    }
    managedRules: {
      managedRuleSets: [
        {
          ruleSetType: 'OWASP'
          ruleSetVersion: '3.2'
        }
      ]
    }
    customRules: [
      // 1) 許可（ヘッダに特定文字列が含まれる場合）
      {
        name: 'AllowWhenSecretHeaderPresent'
        priority: 1
        ruleType: 'MatchRule'
        action: 'Allow'
        state: 'Enabled'
        matchConditions: [
          {
            matchVariables: [
              { variableName: 'RequestHeaders', selector: wafRequiredHeaderName }
            ]
            operator: 'Contains'                // 完全文字列のみ（ワイルドカード不可）
            negationConditon: false
            transforms: [ 'Lowercase' ]        // 任意（値側も小文字に寄せると堅い）
            matchValues: [ toLower(wafRequiredHeaderValue) ]
          }
        ]
      }
      // 2) それ以外は Block（WAFの仕様上 403）
      {
        name: 'BlockWhenSecretHeaderMissing'
        priority: 2
        ruleType: 'MatchRule'
        action: 'Block'
        state: 'Enabled'
        matchConditions: [
          {
            matchVariables: [
              { variableName: 'RequestHeaders', selector: wafRequiredHeaderName }
            ]
            operator: 'Contains'
            negationConditon: true
            transforms: [ 'Lowercase' ]
            matchValues: [ toLower(wafRequiredHeaderValue) ]
          }
        ]
      }
    ]
  }
}

// Application Gateway (aligned with exported structure; id fields omitted)
resource appGateway 'Microsoft.Network/applicationGateways@2024-07-01' = {
  name: appGatewayName
  location: location
  properties: {
    sku: {
      name: appGatewaySku
      tier: appGatewaySku
      capacity: appGatewayCapacity
    }
    firewallPolicy: {
      id: wafPolicy.id
    }
    gatewayIPConfigurations: [
      {
        name: 'appGatewayFrontendIP'
        properties: {
          subnet: {
            id: resourceId('Microsoft.Network/virtualNetworks/subnets', vnet.name, appGatewaySubnetName)
          }
        }
      }
    ]
    sslCertificates: []
    trustedRootCertificates: []
    trustedClientCertificates: []
    sslProfiles: []
    frontendIPConfigurations: [
      {
        name: 'feip-public'
        properties: {
          publicIPAddress: { id: publicIp.id }
        }
      }
      {
        name: 'feip-private'
        properties: {
          subnet: {
            id: resourceId('Microsoft.Network/virtualNetworks/subnets', vnet.name, appGatewaySubnetName)
          }
          privateIPAllocationMethod: 'Static'
          privateIPAddress: appGatewayPrivateIpAddress
        }
      }
    ]
    frontendPorts: [
      {
        name: 'appGatewayFrontendPort'
        properties: {
          port: frontendPort
        }
      }
    ]
    backendAddressPools: [
      {
        name: 'appGatewayBackendPool'
        properties: {
          backendAddresses: []
        }
      }
      {
        name: backendPoolName
        properties: {
          backendAddresses: []
        }
      }
      {
        name: functionBackendPoolName
        properties: {
          backendAddresses: !empty(functionAppHostname) ? [
            {
              fqdn: functionAppHostname
            }
          ] : !empty(functionPrivateEndpointIp) ? [
            {
              ipAddress: functionPrivateEndpointIp
            }
          ] : []
        }
      }
      {
        name: 'openaiBackendPool'
        properties: {
          backendAddresses: !empty(openaiPublicFqdn) ? [
            {
              fqdn: openaiPublicFqdn
            }
          ] : []
        }
      }
    ]
    loadDistributionPolicies: []
    backendHttpSettingsCollection: [
      {
        name: httpSettingsName
        properties: {
          port: 80
          protocol: 'Http'
          cookieBasedAffinity: 'Disabled'
          connectionDraining: {
            enabled: false
            drainTimeoutInSec: 1
          }
          pickHostNameFromBackendAddress: false
          requestTimeout: 60
          probe: {
            id: resourceId('Microsoft.Network/applicationGateways/probes', appGatewayName, probeName)
          }
        }
      }
      {
        name: functionHttpSettingsName
        properties: {
          port: 443
          protocol: 'Https'
          cookieBasedAffinity: 'Disabled'
          connectionDraining: {
            enabled: false
            drainTimeoutInSec: 1
          }
          pickHostNameFromBackendAddress: true
          requestTimeout: 60
          probe: {
            id: resourceId('Microsoft.Network/applicationGateways/probes', appGatewayName, functionProbeName)
          }
        }
      }
      {
        name: 'openaiHttpSettings'
        properties: {
          port: 443
          protocol: 'Https'
          cookieBasedAffinity: 'Disabled'
          connectionDraining: {
            enabled: false
            drainTimeoutInSec: 1
          }
          pickHostNameFromBackendAddress: false
          hostName: !empty(openaiPublicFqdn) ? openaiPublicFqdn : ''
          requestTimeout: 300
          probe: {
            id: resourceId('Microsoft.Network/applicationGateways/probes', appGatewayName, openaiProbeName)
          }
        }
      }
    ]
    backendSettingsCollection: []
    httpListeners: [
      {
        name: 'appGatewayHttpListener'
        properties: {
          frontendIPConfiguration: {
            id: resourceId('Microsoft.Network/applicationGateways/frontendIPConfigurations', appGatewayName, 'feip-private')
          }
          frontendPort: {
            id: resourceId('Microsoft.Network/applicationGateways/frontendPorts', appGatewayName, 'appGatewayFrontendPort')
          }
          protocol: 'Http'
          hostNames: []
          requireServerNameIndication: false
        }
      }
    ]
    listeners: []
    urlPathMaps: [
      {
        name: 'apiPathMap'
        properties: {
          defaultBackendAddressPool: {
            id: resourceId('Microsoft.Network/applicationGateways/backendAddressPools', appGatewayName, backendPoolName)
          }
          defaultBackendHttpSettings: {
            id: resourceId('Microsoft.Network/applicationGateways/backendHttpSettingsCollection', appGatewayName, httpSettingsName)
          }
          pathRules: [
            {
              name: 'codeInterpreterPathRule'
              properties: {
                paths: [
                  '/code-interpreter/*'
                ]
                backendAddressPool: {
                  id: resourceId('Microsoft.Network/applicationGateways/backendAddressPools', appGatewayName, functionBackendPoolName)
                }
                backendHttpSettings: {
                  id: resourceId('Microsoft.Network/applicationGateways/backendHttpSettingsCollection', appGatewayName, functionHttpSettingsName)
                }
              }
            }
            {
              name: 'openaiDirectPathRule'
              properties: {
                paths: [
                  '/openai/*'
                ]
                backendAddressPool: {
                  id: resourceId('Microsoft.Network/applicationGateways/backendAddressPools', appGatewayName, 'openaiBackendPool')
                }
                backendHttpSettings: {
                  id: resourceId('Microsoft.Network/applicationGateways/backendHttpSettingsCollection', appGatewayName, 'openaiHttpSettings')
                }
              }
            }
          ]
        }
      }
    ]
    requestRoutingRules: [
      {
        name: ruleName
        properties: {
          ruleType: 'PathBasedRouting'
          priority: rulePriority
          httpListener: {
            id: resourceId('Microsoft.Network/applicationGateways/httpListeners', appGatewayName, 'appGatewayHttpListener')
          }
          urlPathMap: {
            id: resourceId('Microsoft.Network/applicationGateways/urlPathMaps', appGatewayName, 'apiPathMap')
          }
        }
      }
    ]
    routingRules: []
    probes: [
      {
        name: probeName
        properties: {
          protocol: 'Http'
          path: probePath
          host: 'localhost'
          interval: probeInterval
          timeout: probeTimeout
          unhealthyThreshold: probeUnhealthyThreshold
          minServers: 0
          match: {
            statusCodes: [ '200-399' ]
          }
        }
      }
      {
        name: functionProbeName
        properties: {
          protocol: 'Https'
          path: functionProbePath
          interval: probeInterval
          timeout: probeTimeout
          unhealthyThreshold: probeUnhealthyThreshold
          minServers: 0
          match: {
            statusCodes: [ '200-399' ]
          }
          pickHostNameFromBackendHttpSettings: true
        }
      }
      {
        name: openaiProbeName
        properties: {
          protocol: 'Https'
          path: openaiProbePath
          interval: probeInterval
          timeout: probeTimeout
          unhealthyThreshold: probeUnhealthyThreshold
          minServers: 0
          match: {
            statusCodes: [ '200-399', '404' ]
          }
          pickHostNameFromBackendHttpSettings: true
        }
      }
    ]
    redirectConfigurations: []
    privateLinkConfigurations: []
    enableHttp2: false
  }
  dependsOn: [ backendNsgRuleAllowVnetHttp ]
}

output vnetId string = vnet.id
// output peSubnetId string = resourceId('Microsoft.Network/virtualNetworks/subnets', vnet.name, peSubnetName)
output peSubnetId string = '${vnet.id}/subnets/${peSubnetName}'
output vmssSubnetId string = resourceId('Microsoft.Network/virtualNetworks/subnets', vnet.name, vmssSubnetName)
output functionSubnetId string = resourceId('Microsoft.Network/virtualNetworks/subnets', vnet.name, functionSubnetName)
output apimIntegrationSubnetId string = resourceId('Microsoft.Network/virtualNetworks/subnets', vnet.name, apimIntegrationSubnetName)
output appGatewayBackendPoolId string = resourceId('Microsoft.Network/applicationGateways/backendAddressPools', appGateway.name, backendPoolName)
output functionBackendPoolId string = resourceId('Microsoft.Network/applicationGateways/backendAddressPools', appGateway.name, functionBackendPoolName)
output appGatewayId string = appGateway.id
output appGatewayNameOut string = appGateway.name
output appGatewayPrivateFrontendIp string = appGatewayPrivateIpAddress
output publicIpAddress string = publicIp.properties.ipAddress
