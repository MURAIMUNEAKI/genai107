import { z } from 'zod';

// Common Validator
export const stackInputSchema = z.object({
  account: z.string().default(process.env.CDK_DEFAULT_ACCOUNT ?? ''),
  region: z.string().default(process.env.CDK_DEFAULT_REGION ?? 'ap-northeast-1'),
  env: z.string().default(''),
  anonymousUsageTracking: z.coerce.boolean().default(true),

  // RAG KB
  embeddingModelId: z.string().default('amazon.titan-embed-text-v2:0'),
  ragKnowledgeBaseStandbyReplicas: z.coerce.boolean().default(false),
  ragKnowledgeBaseAdvancedParsing: z.coerce.boolean().default(false),
  ragKnowledgeBaseAdvancedParsingModelId: z
    .string()
    .default('anthropic.claude-3-haiku-20240307-v1:0'),

  // Flows
  qeRagAppNames: z.array(
    z.object({
      appName: z.string(),
      appParamFile: z.string(),
    })
  ),
  // Flows with Shared CMEK
  qeRagAppNamesWithSharedCmek: z
    .array(
      z.object({
        appName: z.string(),
        appParamFile: z.string(),
      })
    )
    .default([]),
  // Flows with S3 Vectors backend (individual CMEK per app)
  qeRagAppNamesWithS3Vectors: z
    .array(
      z.object({
        appName: z.string(),
        appParamFile: z.string(),
      })
    )
    .default([]),

  // WAF
  allowedIpV4AddressRanges: z.array(z.string()).nullable(),
  allowedIpV6AddressRanges: z.array(z.string()).nullable(),
  allowedCountryCodes: z.array(z.string()).nullable(),

  // SwitchRole
  idcUserNames: z.array(z.string()),
  switchRoleName: z.string().default(''),

  // Bedrock regions allowed for model invocation (defaults to deploy region if not specified)
  bedrockRegions: z.array(z.string()).optional(),

  // Log level
  logLevel: z.string().default('INFO'),

  // API Lambda Integration Timeout (seconds)
  apiLambdaIntegrationTimeout: z
    .coerce.number()
    .min(29, 'API Gatewayの最小タイムアウトは29秒です')
    .max(300, 'API Gatewayの最大タイムアウトは300秒です')
    .default(29),
});

export type StackInput = z.infer<typeof stackInputSchema>;
