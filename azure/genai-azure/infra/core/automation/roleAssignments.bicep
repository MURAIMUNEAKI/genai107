@description('Assign Contributor role on the target VMSS to the Automation Account managed identity (replicates manual script).')
param automationPrincipalId string
param vmssName string

// Existing VMSS in same resource group
resource vmssExisting 'Microsoft.Compute/virtualMachineScaleSets@2024-03-01' existing = {
  name: vmssName
}

var contributorRoleId = subscriptionResourceId('Microsoft.Authorization/roleDefinitions', 'b24988ac-6180-42a0-ab88-20f7382dd24c')
// Include principalId in GUID seed so that if Automation Account MI changes, a new role assignment is created
// (avoids RoleAssignmentUpdateNotPermitted when principalId changes)
var roleAssignmentName = guid(resourceGroup().id, vmssName, automationPrincipalId, contributorRoleId)

resource vmssContributor 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  name: roleAssignmentName
  scope: vmssExisting
  properties: {
    roleDefinitionId: contributorRoleId
    principalId: automationPrincipalId
    principalType: 'ServicePrincipal'
  }
}

output vmssRoleAssignmentId string = vmssContributor.id
