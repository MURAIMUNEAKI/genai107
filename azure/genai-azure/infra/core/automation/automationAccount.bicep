@description('Module to create an Automation Account with system-assigned identity and assign Contributor at resource group scope')
param name string
param location string = resourceGroup().location

resource automationAccount 'Microsoft.Automation/automationAccounts@2023-11-01' = {
  name: name
  location: location
  identity: {
    type: 'SystemAssigned'
  }
  properties: {
    sku: {
      name: 'Free'
    }
  }
}

// Role assignment: give the Automation Account's managed identity Contributor on the resource group
resource rgRoleAssignment 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  name: guid(resourceGroup().id, automationAccount.name, 'aa-rg-contributor')
  scope: resourceGroup()
  properties: {
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', 'b24988ac-6180-42a0-ab88-20f7382dd24c') // Contributor
    principalId: automationAccount.identity.principalId
    principalType: 'ServicePrincipal'
  }
}

// Output the automation account id and principal id
output automationAccountId string = automationAccount.id
output automationAccountPrincipalId string = automationAccount.identity.principalId
