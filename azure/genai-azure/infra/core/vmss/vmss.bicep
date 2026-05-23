@description('Deploy VM Scale Set and join to Application Gateway backend pool via backend subnet. Cloud-init installs simple Python HTTP server.')
param location string
param vmssName string
param adminUsername string
@secure()
@description('SSH public key (must be provided; password auth disabled)')
param adminPublicKey string
param subnetId string
param appGatewayBackendPoolId string
param instanceCount int
param vmSku string
param upgradePolicyMode string
@description('Overprovision VMs for faster scaling (true default)')
param overprovision bool
@description('Single placement group (true for <=100 instances)')
param singlePlacementGroup bool
@description('Platform fault domain count (optional, leave -1 to omit)')
param platformFaultDomainCount int
param linuxImagePublisher string
param linuxImageOffer string
param linuxImageSku string
param linuxImageVersion string
@description('OS disk size in GB (set 0 to omit)')
param osDiskSizeGB int
@description('OS disk storage account type')
param osDiskStorageAccountType string
@description('Enable Trusted Launch security profile (secure boot + vTPM)')
param enableTrustedLaunch bool
@description('Provision VM Agent')
param provisionVMAgent bool
@description('Optional tags to apply to the VM Scale Set')
param tags object
@description('vLLM model name to deploy (e.g. pfnet/plamo-2-translate)')
param vllmModelName string = 'pfnet/plamo-2-translate'
@description('vLLM max model length for context window')
param vllmMaxModelLen int = 4096
@description('vLLM max number of batched tokens')
param vllmMaxNumBatchedTokens int = 4096
@description('vLLM container image (e.g. vllm/vllm-openai:latest)')
param vllmContainerImage string = 'vllm/vllm-openai:latest'

@description('Enable password authentication for VMSS VMs (in addition to SSH key)')
param enablePasswordAuth bool = false
@secure()
@description('Admin password for VMSS VMs (required if enablePasswordAuth is true)')
param adminPassword string = ''

// Externalized cloud-init user data (keep lightweight here)
var cloudInitRaw = loadTextContent('cloudinit/cloud-init.yaml')
var requirementsTxt = loadTextContent('cloudinit/requirements.txt')
var requirementsTxtBase64 = base64(requirementsTxt)
var cloudInitStep1 = replace(cloudInitRaw, '{{VLLM_MODEL_NAME}}', vllmModelName)
var cloudInitStep2 = replace(cloudInitStep1, '{{VLLM_MAX_MODEL_LEN}}', string(vllmMaxModelLen))
var cloudInitStep3 = replace(cloudInitStep2, '{{VLLM_MAX_NUM_BATCHED_TOKENS}}', string(vllmMaxNumBatchedTokens))
var cloudInitStep4 = replace(cloudInitStep3, '{{VLLM_CONTAINER_IMAGE}}', vllmContainerImage)
var cloudInit = replace(cloudInitStep4, '{{VLLM_REQUIREMENTS_BASE64}}', requirementsTxtBase64)

@description('Availability zones to spread VM instances across (e.g. ["1","2","3"])')
param vmZones array = [
  '1'
  '2'
  '3'
]

@description('If true, provision VMSS instances as Spot (eviction possible).')
param useSpot bool = false
@description('Max price for Spot instances as JSON number literal or "-1" for on-demand price (e.g. "0.5").')
param spotMaxPrice string = '-1'

resource vmss 'Microsoft.Compute/virtualMachineScaleSets@2024-11-01' = {
  name: vmssName
  location: location
  zones: vmZones
  tags: tags
  sku: {
    name: vmSku
    capacity: instanceCount
    tier: 'Standard'
  }
  properties: {
    upgradePolicy: {
      mode: upgradePolicyMode
    }
    virtualMachineProfile: {
      // Configure Spot priority and billing when requested
      priority: useSpot ? 'Spot' : 'Regular'
      billingProfile: useSpot ? {
        maxPrice: json(spotMaxPrice)
      } : null

      osProfile: {
        computerNamePrefix: take(replace(vmssName, '-', ''), 9)
        adminUsername: adminUsername
        adminPassword: enablePasswordAuth ? adminPassword : null
        customData: base64(cloudInit)
        linuxConfiguration: {
          disablePasswordAuthentication: !enablePasswordAuth
          ssh: {
            publicKeys: [
              {
                path: '/home/${adminUsername}/.ssh/authorized_keys'
                keyData: adminPublicKey
              }
            ]
          }
          provisionVMAgent: provisionVMAgent
        }
        allowExtensionOperations: true
      }
      storageProfile: {
        imageReference: {
          publisher: linuxImagePublisher
          offer: linuxImageOffer
          sku: linuxImageSku
          version: linuxImageVersion
        }
        osDisk: {
          createOption: 'FromImage'
          caching: 'ReadWrite'
          managedDisk: {
            storageAccountType: osDiskStorageAccountType
          }
          osType: 'Linux'
          diskSizeGB: osDiskSizeGB == 0 ? null : osDiskSizeGB
        }
        diskControllerType: 'SCSI'
      }
      networkProfile: {
        networkInterfaceConfigurations: [
          {
            name: '${vmssName}-nic'
            properties: {
              primary: true
              ipConfigurations: [
                {
                  name: '${vmssName}-ipconfig'
                  properties: {
                    subnet: {
                      id: subnetId
                    }
                    applicationGatewayBackendAddressPools: [
                      {
                        id: appGatewayBackendPoolId
                      }
                    ]
                  }
                }
              ]
            }
          }
        ]
      }
      securityProfile: enableTrustedLaunch ? {
        securityType: 'TrustedLaunch'
        uefiSettings: {
          secureBootEnabled: true
          vTpmEnabled: true
        }
      } : null
    }
    orchestrationMode: 'Uniform'
    overprovision: overprovision
    singlePlacementGroup: singlePlacementGroup
    doNotRunExtensionsOnOverprovisionedVMs: false
    platformFaultDomainCount: platformFaultDomainCount == -1 ? null : platformFaultDomainCount
  }
}

output vmssId string = vmss.id
output vmssNameOut string = vmss.name
