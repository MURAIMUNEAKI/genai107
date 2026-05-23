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

var _ipAddressLines = [for ip in apiAllowedSourceIps: format('            <address>{0}</address>', ip)]
var _ipFilterXml = empty(apiAllowedSourceIps)
  ? ''
  : format('        <ip-filter action="allow">\n{0}\n        </ip-filter>', join(_ipAddressLines, '\n'))

// Code Interpreter API ポリシー (App Gateway 経由で Functions にルーティング)
// Functions の default host key は APIM Named Value から参照する。
var codeInterpreterApiPolicy = '''
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
    <!-- Functions host key を Named Value からヘッダーに注入 -->
    <set-header name="x-functions-key" exists-action="override">
      <value>{{function-app-key}}</value>
    </set-header>
    <!-- App Gateway 経由で Functions にルーティング -->
    <!-- APIパスプレフィックスを再度追加してバックエンドに転送 -->
    <set-variable name="original-path" value="@(context.Operation.UrlTemplate)" />
    <rewrite-uri template="@("/code-interpreter" + context.Operation.UrlTemplate)" copy-unmatched-params="true" />
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

var policy_template1 = replace(codeInterpreterApiPolicy, '{origin}', corsOriginUrl)
var policy_template2 = replace(policy_template1, '{backend-id}', apiBackendId)
var apiPolicyContent = replace(policy_template2, '{ip-filter}', _ipFilterXml)

resource apimService 'Microsoft.ApiManagement/service@2024-05-01' existing = {
  name: name
}

resource openaiApi 'Microsoft.ApiManagement/service/apis@2024-05-01' = {
  name: apiName
  parent: apimService
  properties: {
    description: apiDescription
    displayName: apiDisplayName
    path: apiPath
    protocols: [ 'https' ]
    subscriptionRequired: true
    type: 'http'
    subscriptionKeyParameterNames: {
      header: 'x-api-key'
      query: 'subscription-key'
    }
  }
}

// 主要な OpenAI エンドポイントを追加
resource healthOperation 'Microsoft.ApiManagement/service/apis/operations@2024-05-01' = {
  name: 'health'
  parent: openaiApi
  properties: {
    displayName: 'Health Check'
    method: 'GET'
    urlTemplate: '/health'
    description: 'Health check endpoint'
  }
}

resource codeInterpreterResponsesOperation 'Microsoft.ApiManagement/service/apis/operations@2024-05-01' = {
  name: 'code-interpreter-responses'
  parent: openaiApi
  properties: {
    displayName: 'Code-interpreter response API'
    method: 'POST'
    urlTemplate: '/responses'
    description: 'Code-interpreter response API'
    request: {
      representations: [
        {
          contentType: 'application/json'
          examples: {
            default: {
              value: {
                inputs: {
                  input_text: 'このExcelファイルから〇〇のワードを抽出して件数を集計してください。'
                  files: [
                    {
                      key: 'any_file'
                      files: [
                        {
                          filename: 'fuga.Excel'
                          content: 'ZnVnYQ==...'
                        }
                        {
                          filename: 'hoge.Excel'
                          content: 'ZnVnYQ==...'
                        }
                      ]
                    }
                  ]
                }
              }
            }
          }
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
            examples: {
              default: {
                value: {
                  outputs: 'LLM output'
                  artifacts: [
                    {
                      display_name: 'response.md'
                      content: '<base64_data>'
                    }
                  ]
                }
              }
            }
          }
        ]
      }
    ]
  }
}

resource testOpenaiOperation 'Microsoft.ApiManagement/service/apis/operations@2024-05-01' = {
  name: 'test-openai'
  parent: openaiApi
  properties: {
    displayName: 'Test OpenAI Connection'
    method: 'GET'
    urlTemplate: '/test-openai'
    description: 'Test OpenAI connection with a simple message'
    request: {
      queryParameters: [
        {
          name: 'message'
          type: 'string'
          required: false
          defaultValue: 'Hello'
        }
      ]
    }
  }
}

resource chatCompletionsOperation 'Microsoft.ApiManagement/service/apis/operations@2024-05-01' = {
  name: 'chat-completions'
  parent: openaiApi
  properties: {
    displayName: 'Chat Completions'
    method: 'POST'
    urlTemplate: '/chat/completions'
    description: 'OpenAI-compatible chat completions endpoint'
    request: {
      representations: [
        {
          contentType: 'application/json'
        }
      ]
    }
  }
}

resource apiPolicy 'Microsoft.ApiManagement/service/apis/policies@2024-05-01' = {
  name: 'policy'
  parent: openaiApi
  properties: {
    format: 'rawxml'
    value: apiPolicyContent
  }
}

output SERVICE_OPENAI_API_URI string = '${apimService.properties.gatewayUrl}/${apiPath}'
output apiResourceId string = openaiApi.id
output apiNameOut string = openaiApi.name
