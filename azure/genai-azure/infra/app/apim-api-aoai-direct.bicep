@description('Name of the existing API Management service instance')
param name string

@description('Resource name to uniquely identify this API within the API Management service instance')
@minLength(1)
param apiName string

@description('The Display Name of the API')
@minLength(1)
@maxLength(300)
param apiDisplayName string

@description('Description of the API. May include HTML formatting tags.')
@minLength(1)
param apiDescription string

@description('Relative URL uniquely identifying this API and all of its resource paths within the API Management service instance.')
param apiPath string

@description('Absolute URL of the web frontend')
param corsOriginUrl string

@description('Backend ID for App Gateway')
param apiBackendId string

@description('Allowed caller source IPs for APIM ip-filter. Empty disables filtering.')
param apiAllowedSourceIps array = []

@description('Azure OpenAI account name (for public FQDN e.g. cog-xxxx.openai.azure.com)')
param openaiAccountName string

@description('Azure OpenAI API Key')
@secure()
param openaiApiKey string

@description('VNet ID used by APIM/AppGW for private DNS linking')
param vnetId string = ''

var _ipAddressLines = [for ip in apiAllowedSourceIps: format('            <address>{0}</address>', ip)]
var _ipFilterXml = empty(apiAllowedSourceIps)
	? ''
	: format('        <ip-filter action="allow">\n{0}\n        </ip-filter>', join(_ipAddressLines, '\n'))

// AOAI 直叩き用 API ポリシー
// - Backend: App Gateway (Private IP)
// - 認証: Azure OpenAI API Key
// - パス: AppGW 側の `/openai/*` ルールにマッチさせるため `/openai` プレフィックスを維持
var aoaiDirectApiPolicy = '''
<policies>
  <inbound>
    <base />
    <set-backend-service backend-id="{backend-id}" />
    {ip-filter}
    <cors allow-credentials="false">
      <allowed-origins>
        <origin>{origin}</origin>
      </allowed-origins>
      <allowed-methods>
        <method>GET</method>
        <method>POST</method>
        <method>OPTIONS</method>
      </allowed-methods>
      <allowed-headers>
        <header>*</header>
      </allowed-headers>
    </cors>

    <!-- Azure OpenAI API Key 認証 -->
    <set-header name="api-key" exists-action="override">
      <value>{{openai-api-key}}</value>
    </set-header>

    <rewrite-uri template="@("/openai" + context.Operation.UrlTemplate)" copy-unmatched-params="true" />
  </inbound>
  <backend>
    <base />
  </backend>
  <outbound>
    <base />
  </outbound>
  <on-error>
    <base />
  </on-error>
</policies>
'''

var policy_template1 = replace(aoaiDirectApiPolicy, '{origin}', corsOriginUrl)
var policy_template2 = replace(policy_template1, '{backend-id}', apiBackendId)
var apiPolicyContent = replace(policy_template2, '{ip-filter}', _ipFilterXml)

resource apimService 'Microsoft.ApiManagement/service@2024-05-01' existing = {
	name: name
}

// Named Value for Azure OpenAI API Key
resource openaiApiKeyNamedValue 'Microsoft.ApiManagement/service/namedValues@2024-05-01' = {
  name: 'openai-api-key'
  parent: apimService
  properties: {
    displayName: 'openai-api-key'
    secret: true
    value: openaiApiKey
  }
}

// Private DNS zone for AOAI public FQDN -> Private Endpoint IP mapping (APIM/AppGW VNet only)
resource openaiPublicDnsZone 'Microsoft.Network/privateDnsZones@2020-06-01' = if (!empty(vnetId)) {
  name: 'openai.azure.com'
  location: 'global'
}

// Link DNS zone to the VNet where APIM/AppGW live (use APIM's vnet, inferred from its resourceGroup and name)
// NOTE: We assume APIM is deployed into the same VNet used by AppGW via VNet integration.
resource openaiPublicDnsVnetLink 'Microsoft.Network/privateDnsZones/virtualNetworkLinks@2020-06-01' = if (!empty(vnetId)) {
  name: '${name}-openai-public-vnet-link'
  parent: openaiPublicDnsZone
  location: 'global'
  properties: {
    registrationEnabled: false
    virtualNetwork: {
      id: vnetId
    }
  }
}

// CNAME: cog-xxxx.openai.azure.com -> cog-xxxx.privatelink.openai.azure.com
resource openaiPublicDnsCname 'Microsoft.Network/privateDnsZones/CNAME@2020-06-01' = if (!empty(vnetId)) {
  name: openaiAccountName
  parent: openaiPublicDnsZone
  properties: {
    ttl: 300
    cnameRecord: {
      cname: format('{0}.privatelink.openai.azure.com', openaiAccountName)
    }
  }
}

// Azure OpenAI 互換 Chat Completions API (OpenAI-style)
// - Public path:   /openai/chat/completions?api-version=v1
// - Backend path:  /openai/chat/completions (AppGW 経由で AOAI に転送)
resource openaiDirectApi 'Microsoft.ApiManagement/service/apis@2024-05-01' = {
  name: apiName
  parent: apimService
  properties: {
    description: apiDescription
    displayName: apiDisplayName
    path: apiPath
    protocols: [ 'https' ]
    subscriptionRequired: true
    subscriptionKeyParameterNames: {
      header: 'x-api-key'
      query: 'subscription-key'
    }
  }
}

// POST /v1/chat/completions
resource chatCompletionsOperation 'Microsoft.ApiManagement/service/apis/operations@2024-05-01' = {
  name: 'chat-completions'
  parent: openaiDirectApi
  properties: {
    displayName: 'Chat Completions (OpenAI compatible)'
    method: 'POST'
    urlTemplate: '/v1/chat/completions'
    description: 'OpenAI-compatible chat completions endpoint proxied to Azure OpenAI via App Gateway.'
    request: {
      representations: [
        {
          contentType: 'application/json'
        }
      ]
    }
    responses: [
      {
        statusCode: 200
        description: 'Success'
        representations: [
          {
            contentType: 'application/json'
          }
        ]
      }
    ]
  }
}

// POST /v1/responses
resource responsesOperation 'Microsoft.ApiManagement/service/apis/operations@2024-05-01' = {
  name: 'responses'
  parent: openaiDirectApi
  properties: {
    displayName: 'Create Response (OpenAI compatible)'
    method: 'POST'
    urlTemplate: '/v1/responses'
    description: 'OpenAI-compatible responses endpoint for structured outputs proxied to Azure OpenAI via App Gateway.'
    request: {
      representations: [
        {
          contentType: 'application/json'
        }
      ]
    }
    responses: [
      {
        statusCode: 200
        description: 'Success'
        representations: [
          {
            contentType: 'application/json'
          }
        ]
      }
    ]
  }
}

resource apiPolicy 'Microsoft.ApiManagement/service/apis/policies@2024-05-01' = {
	name: 'policy'
	parent: openaiDirectApi
	properties: {
		format: 'rawxml'
		value: apiPolicyContent
	}
	dependsOn: [
		openaiApiKeyNamedValue
	]
}

output SERVICE_OPENAI_DIRECT_API_URI string = '${apimService.properties.gatewayUrl}/${apiPath}'
output apiResourceId string = openaiDirectApi.id
output apiNameOut string = openaiDirectApi.name

