@description('APIM service name')
param name string

@description('Product ID (英数とハイフンのみ)')
@minLength(1)
param productId string = 'genai-product'

@description('Product display name')
@minLength(1)
param productDisplayName string = 'GenAI Product'

@description('Product description')
param productDescription string = 'GenAIの製品です'

@description('API names to add into this product')
@minLength(1)
param apiNames array

// 既存の APIM インスタンス
resource apimService 'Microsoft.ApiManagement/service@2024-05-01' existing = {
  name: name
}

// 製品の作成
resource product 'Microsoft.ApiManagement/service/products@2024-05-01' = {
  name: productId
  parent: apimService
  properties: {
    displayName: productDisplayName
    description: productDescription
    subscriptionRequired: true  // サブスクリプション必須
    approvalRequired: false     // 承認フローが必要なら true に変更
    state: 'published'
  }
}

// 製品に複数のAPIを紐付け
resource productApis 'Microsoft.ApiManagement/service/products/apis@2024-05-01' = [for apiName in apiNames: {
  name: apiName
  parent: product
}]
