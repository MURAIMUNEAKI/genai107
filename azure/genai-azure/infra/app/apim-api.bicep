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

@description('Relative URL uniquely identifying this API and all of its resource paths within the API Management service instance. It is appended to the API endpoint base URL specified during the service instance creation to form a public URL for this API.')
param apiPath string

@description('Absolute URL of the web frontend')
param corsOriginUrl string
param apiBackendId string = 'backend1'

@description('Allowed caller source IPs for APIM ip-filter. Empty disables filtering.')
param apiAllowedSourceIps array = []

@description('Enable vLLM API transform for Plamo translation format. If false, passthrough OpenAI-compatible API.')
param enablePlamoCustomApiTransform bool = false

@description('vLLM model name for API policy')
param vllmModelName string = 'pfnet/plamo-2-translate'

// Load policy templates
var policy_template_transform = loadTextContent('apim-api-policy.xml')
var policy_template_passthrough = loadTextContent('apim-api-policy-passthrough.xml')
var policy_template = enablePlamoCustomApiTransform ? policy_template_transform : policy_template_passthrough

var _ipAddressLines = [for ip in apiAllowedSourceIps: format('            <address>{0}</address>', ip)]
var _ipFilterXml = empty(apiAllowedSourceIps)
  ? ''
  : format('        <ip-filter action="allow">\n{0}\n        </ip-filter>', join(_ipAddressLines, '\n'))

var policy_template1 = replace(policy_template ,'{origin}', corsOriginUrl)
var policy_template2 = replace(policy_template1 ,'{backend-id}', apiBackendId)
var policy_template3 = replace(policy_template2 ,'{ip-filter}', _ipFilterXml)
var apiPolicyContent = replace(policy_template3, '{vllm-model-name}', vllmModelName)

resource apimService 'Microsoft.ApiManagement/service@2024-05-01' existing = {
  name: name
}

resource aoaiApi 'Microsoft.ApiManagement/service/apis@2024-05-01' = {
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
    }
    type: 'http'
    format: 'openapi'
    serviceUrl: 'https://example.com'
    value: loadTextContent('Completions.yml')
  }
}

resource apiPolicy 'Microsoft.ApiManagement/service/apis/policies@2024-05-01' = {
  name: 'policy'
  parent: aoaiApi
  properties: {
    format: 'rawxml'
    value: apiPolicyContent
  }
}

output SERVICE_API_URI string = '${apimService.properties.gatewayUrl}/${apiPath}'
output apiResourceId string = aoaiApi.id
output apiNameOut string = aoaiApi.name
