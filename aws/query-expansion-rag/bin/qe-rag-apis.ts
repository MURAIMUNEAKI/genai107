#!/usr/bin/env node
import 'source-map-support/register';
import * as cdk from 'aws-cdk-lib';
import { AwsSolutionsChecks } from 'cdk-nag'
import { getParams, validateAppNames } from '../parameter';
import { RagLambdaApiStack } from '../lib/rag-lambda-api-stack';
import { ApiWafStack } from '../lib/api-waf-stack';
import { RagKnowledgeBaseStack } from '../lib/rag-knowledge-base-stack';
import { RagS3VectorsKbStack } from '../lib/rag-s3vectors-kb-stack';
import { SwitchRoleForBedrockFlowsDeveloperStack } from '../lib/switch-role-stack';
import { SharedCmekStack } from '../lib/shared-cmek-stack';
import { mergeAppParams } from '../parameter'

const app = new cdk.App();

// パラメータの取得
const params = getParams(app);

// アプリ名の重複チェック
validateAppNames(params);

if(!params.idcUserNames || params.idcUserNames.length === 0 || !params.switchRoleName || params.switchRoleName === ""){
  throw new Error("idcUserNames and switchRoleName must be set in cdk.json");
}

const env = {
  account: process.env.CDK_DEFAULT_ACCOUNT,
  region: "ap-northeast-1",
};

// CDK-NAGの有効化
cdk.Aspects.of(app).add(new AwsSolutionsChecks({ verbose: true }));

const waf = new ApiWafStack(app, `ApiWafStack`, {
  env: env,
  allowedIpV4AddressRanges: params.allowedIpV4AddressRanges,
  allowedIpV6AddressRanges: params.allowedIpV6AddressRanges,
  allowedCountryCodes: params.allowedCountryCodes
});

// ragAppNamesに定義されたAppName（プレフィックス）ごとにRole、KnowledgeBase、RAG APIを作成（個別CMEK）
for (const appCfg of params.qeRagAppNames) {
  const mergedParams = mergeAppParams(params, appCfg);
  const swtichRoleStack = new SwitchRoleForBedrockFlowsDeveloperStack(app, `${mergedParams.appName}-SwitchRoleStack`, {
    env: env,
    idcUserNames: mergedParams.idcUserNames,
    switchRoleName: params.switchRoleName,
    appName: mergedParams.appName
  });
  const kb = new RagKnowledgeBaseStack(app, `${mergedParams.appName}-qeRagKB`, {
    env: env,
    switchRole: swtichRoleStack.switchRole,
    appName: mergedParams.appName,
    collectionName: `${mergedParams.appName}-qerag-collection`,
    vectorIndexName: `${mergedParams.appName}-qerag-index`
  })
  new RagLambdaApiStack(app, `${mergedParams.appName}-qeRagApi`, {
    env: env,
    appName: mergedParams.appName,
    webAclArn: waf.webAclArn,
    knowledgeBaseId: kb.knowledgeBaseId,
    switchRole: swtichRoleStack.switchRole,
    logLevel: params.logLevel,
    appParamFile: mergedParams.appParamFile,
    encryptionKey: kb.encryptionKey,
    apiLambdaIntegrationTimeout: params.apiLambdaIntegrationTimeout,
    bedrockRegions: params.bedrockRegions,
  });
}

// qeRagAppNamesWithSharedCmekに定義されたAppName（プレフィックス）ごとにRole、KnowledgeBase、RAG APIを作成（共通CMEK）
if (params.qeRagAppNamesWithSharedCmek.length > 0) {
  // まずすべてのSwitchRoleを作成してから、SharedCmekStackに渡す
  const sharedCmekSwitchRoles = params.qeRagAppNamesWithSharedCmek.map((appCfg) => {
    const mergedParams = mergeAppParams(params, appCfg);
    const swtichRoleStack = new SwitchRoleForBedrockFlowsDeveloperStack(
      app,
      `${mergedParams.appName}-SwitchRoleStack`,
      {
        env: env,
        idcUserNames: mergedParams.idcUserNames,
        switchRoleName: params.switchRoleName,
        appName: mergedParams.appName,
      }
    );
    return { switchRole: swtichRoleStack.switchRole, appCfg, mergedParams };
  });

  // 共通CMEK Stackを作成
  const sharedCmekStack = new SharedCmekStack(app, `SharedCmekStack${params.env}`, {
    env: env,
    switchRoles: sharedCmekSwitchRoles.map((item) => item.switchRole),
    envName: params.env,
  });

  // 各APIのKnowledgeBaseとRAG APIを作成（共通CMEKを使用）
  for (const { switchRole, mergedParams } of sharedCmekSwitchRoles) {
    const kb = new RagKnowledgeBaseStack(app, `${mergedParams.appName}-qeRagKB`, {
      env: env,
      switchRole: switchRole,
      appName: mergedParams.appName,
      collectionName: `${mergedParams.appName}-qerag-collection`,
      vectorIndexName: `${mergedParams.appName}-qerag-index`,
      encryptionKey: sharedCmekStack.encryptionKey,
    });
    new RagLambdaApiStack(app, `${mergedParams.appName}-qeRagApi`, {
      env: env,
      appName: mergedParams.appName,
      webAclArn: waf.webAclArn,
      knowledgeBaseId: kb.knowledgeBaseId,
      switchRole: switchRole,
      logLevel: params.logLevel,
      appParamFile: mergedParams.appParamFile,
      encryptionKey: kb.encryptionKey,
      apiLambdaIntegrationTimeout: params.apiLambdaIntegrationTimeout,
      bedrockRegions: params.bedrockRegions,
    });
  }
}

// qeRagAppNamesWithS3Vectorsに定義されたAppName（プレフィックス）ごとにRole、KnowledgeBase、RAG APIを作成（S3 Vectorsバックエンド、個別CMEK）
for (const appCfg of params.qeRagAppNamesWithS3Vectors) {
  const mergedParams = mergeAppParams(params, appCfg);
  const swtichRoleStack = new SwitchRoleForBedrockFlowsDeveloperStack(app, `${mergedParams.appName}-SwitchRoleStack`, {
    env: env,
    idcUserNames: mergedParams.idcUserNames,
    switchRoleName: params.switchRoleName,
    appName: mergedParams.appName
  });
  const kb = new RagS3VectorsKbStack(app, `${mergedParams.appName}-qeRagKB`, {
    env: env,
    switchRole: swtichRoleStack.switchRole,
    appName: mergedParams.appName,
  });
  new RagLambdaApiStack(app, `${mergedParams.appName}-qeRagApi`, {
    env: env,
    appName: mergedParams.appName,
    webAclArn: waf.webAclArn,
    knowledgeBaseId: kb.knowledgeBaseId,
    switchRole: swtichRoleStack.switchRole,
    logLevel: params.logLevel,
    appParamFile: mergedParams.appParamFile,
    encryptionKey: kb.encryptionKey,
    apiLambdaIntegrationTimeout: params.apiLambdaIntegrationTimeout,
    bedrockRegions: params.bedrockRegions,
  });
}
