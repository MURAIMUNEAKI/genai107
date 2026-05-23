/**
 * Unit tests for RagKnowledgeBaseStack
 * These tests verify the baseline behavior before license migration rewrite.
 */
import * as cdk from 'aws-cdk-lib';
import { Template } from 'aws-cdk-lib/assertions';
import * as iam from 'aws-cdk-lib/aws-iam';
import * as kms from 'aws-cdk-lib/aws-kms';
import { RagKnowledgeBaseStack } from '../../lib/rag-knowledge-base-stack';

describe('RagKnowledgeBaseStack', () => {
  let app: cdk.App;
  let switchRoleStack: cdk.Stack;
  let switchRole: iam.Role;
  let encryptionKey: kms.Key;

  beforeEach(() => {
    app = new cdk.App({
      context: {
        embeddingModelId: 'amazon.titan-embed-text-v2:0',
        kbRagKnowledgeBaseAdvancedParsing: false,
        kbRagKnowledgeBaseAdvancedParsingModelId: null,
        kbDataSourceChunkingMaxTokens: 300,
        kbDataSourceChunkingOverlapPercentage: 20,
        standbyReplicas: 'ENABLED',
      },
    });
    switchRoleStack = new cdk.Stack(app, 'SwitchRoleStack', {
      env: { account: '123456789012', region: 'ap-northeast-1' },
    });
    switchRole = new iam.Role(switchRoleStack, 'TestSwitchRole', {
      assumedBy: new iam.AccountPrincipal('123456789012'),
    });
    encryptionKey = new kms.Key(switchRoleStack, 'TestKey');
  });

  function createStack(): RagKnowledgeBaseStack {
    return new RagKnowledgeBaseStack(app, 'TestRagKnowledgeBaseStack', {
      appName: 'test-app',
      switchRole: switchRole,
      vectorIndexName: 'test-index',
      metadataField: 'AMAZON_BEDROCK_METADATA',
      textField: 'AMAZON_BEDROCK_TEXT_CHUNK',
      vectorField: 'bedrock-knowledge-base-default-vector',
      encryptionKey: encryptionKey,
      env: { account: '123456789012', region: 'ap-northeast-1' },
    });
  }

  describe('OpenSearch Serverless', () => {
    test('creates OpenSearch Serverless collection', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.resourceCountIs('AWS::OpenSearchServerless::Collection', 1);
      template.hasResourceProperties('AWS::OpenSearchServerless::Collection', {
        Type: 'VECTORSEARCH',
      });
    });

    test('creates security policies for encryption and network', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      // Should have 2 security policies: encryption and network
      template.resourceCountIs('AWS::OpenSearchServerless::SecurityPolicy', 2);
    });

    test('creates access policy', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.resourceCountIs('AWS::OpenSearchServerless::AccessPolicy', 1);
    });

    test('creates custom resource for index creation', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.resourceCountIs('Custom::OssIndex', 1);
    });
  });

  describe('S3 Buckets', () => {
    test('creates S3 bucket for document uploads', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      // Should have at least 2 buckets (upload and kb resources)
      const buckets = template.findResources('AWS::S3::Bucket');
      expect(Object.keys(buckets).length).toBeGreaterThanOrEqual(2);
    });

    test('creates bucket policies', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      const policies = template.findResources('AWS::S3::BucketPolicy');
      expect(Object.keys(policies).length).toBeGreaterThanOrEqual(2);
    });
  });

  describe('Bedrock Knowledge Base', () => {
    test('creates Bedrock Knowledge Base', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.resourceCountIs('AWS::Bedrock::KnowledgeBase', 1);
    });

    test('creates Bedrock Data Source', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.resourceCountIs('AWS::Bedrock::DataSource', 1);
    });
  });

  describe('IAM Roles', () => {
    test('creates required IAM roles', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      // Should have multiple IAM roles for various resources
      const roles = template.findResources('AWS::IAM::Role');
      expect(Object.keys(roles).length).toBeGreaterThanOrEqual(1);
    });
  });

  describe('Lambda Functions', () => {
    test('creates Lambda functions for custom resources', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      // Should have Lambda functions for custom resources
      const functions = template.findResources('AWS::Lambda::Function');
      expect(Object.keys(functions).length).toBeGreaterThanOrEqual(1);
    });
  });

  describe('Public Properties', () => {
    test('exposes knowledgeBaseId', () => {
      const stack = createStack();
      expect(stack.knowledgeBaseId).toBeDefined();
    });
  });

  describe('Input validation', () => {
    test('throws when embeddingModelId is not provided', () => {
      const appNoModel = new cdk.App({
        context: {
          kbRagKnowledgeBaseAdvancedParsing: false,
          kbRagKnowledgeBaseAdvancedParsingModelId: null,
          kbDataSourceChunkingMaxTokens: 300,
          kbDataSourceChunkingOverlapPercentage: 20,
          standbyReplicas: 'ENABLED',
        },
      });
      const noModelSwitchRoleStack = new cdk.Stack(appNoModel, 'SwitchRoleStack', {
        env: { account: '123456789012', region: 'ap-northeast-1' },
      });
      const noModelSwitchRole = new iam.Role(noModelSwitchRoleStack, 'TestSwitchRole', {
        assumedBy: new iam.AccountPrincipal('123456789012'),
      });
      const noModelKey = new kms.Key(noModelSwitchRoleStack, 'TestKey');

      expect(() => {
        new RagKnowledgeBaseStack(appNoModel, 'TestStack', {
          appName: 'test-app',
          switchRole: noModelSwitchRole,
          vectorIndexName: 'test-index',
          metadataField: 'AMAZON_BEDROCK_METADATA',
          textField: 'AMAZON_BEDROCK_TEXT_CHUNK',
          vectorField: 'bedrock-knowledge-base-default-vector',
          encryptionKey: noModelKey,
          env: { account: '123456789012', region: 'ap-northeast-1' },
        });
      }).toThrow('embeddingModelId が指定されていません');
    });

    test('throws when embeddingModelId is invalid', () => {
      const appInvalidModel = new cdk.App({
        context: {
          embeddingModelId: 'invalid-model-id',
          kbRagKnowledgeBaseAdvancedParsing: false,
          kbRagKnowledgeBaseAdvancedParsingModelId: null,
          kbDataSourceChunkingMaxTokens: 300,
          kbDataSourceChunkingOverlapPercentage: 20,
          standbyReplicas: 'ENABLED',
        },
      });
      const invalidSwitchRoleStack = new cdk.Stack(appInvalidModel, 'SwitchRoleStack', {
        env: { account: '123456789012', region: 'ap-northeast-1' },
      });
      const invalidSwitchRole = new iam.Role(invalidSwitchRoleStack, 'TestSwitchRole', {
        assumedBy: new iam.AccountPrincipal('123456789012'),
      });
      const invalidKey = new kms.Key(invalidSwitchRoleStack, 'TestKey');

      expect(() => {
        new RagKnowledgeBaseStack(appInvalidModel, 'TestStack', {
          appName: 'test-app',
          switchRole: invalidSwitchRole,
          vectorIndexName: 'test-index',
          metadataField: 'AMAZON_BEDROCK_METADATA',
          textField: 'AMAZON_BEDROCK_TEXT_CHUNK',
          vectorField: 'bedrock-knowledge-base-default-vector',
          encryptionKey: invalidKey,
          env: { account: '123456789012', region: 'ap-northeast-1' },
        });
      }).toThrow('embeddingModelId が無効な値です');
    });

    test('throws when advancedParsing is enabled without a modelId', () => {
      const appAdvanced = new cdk.App({
        context: {
          embeddingModelId: 'amazon.titan-embed-text-v2:0',
          ragKnowledgeBaseAdvancedParsing: true,
          ragKnowledgeBaseAdvancedParsingModelId: null,
          kbDataSourceChunkingMaxTokens: 300,
          kbDataSourceChunkingOverlapPercentage: 20,
          standbyReplicas: 'ENABLED',
        },
      });
      const advancedSwitchRoleStack = new cdk.Stack(appAdvanced, 'SwitchRoleStack', {
        env: { account: '123456789012', region: 'ap-northeast-1' },
      });
      const advancedSwitchRole = new iam.Role(advancedSwitchRoleStack, 'TestSwitchRole', {
        assumedBy: new iam.AccountPrincipal('123456789012'),
      });
      const advancedKey = new kms.Key(advancedSwitchRoleStack, 'TestKey');

      expect(() => {
        new RagKnowledgeBaseStack(appAdvanced, 'TestStack', {
          appName: 'test-app',
          switchRole: advancedSwitchRole,
          vectorIndexName: 'test-index',
          metadataField: 'AMAZON_BEDROCK_METADATA',
          textField: 'AMAZON_BEDROCK_TEXT_CHUNK',
          vectorField: 'bedrock-knowledge-base-default-vector',
          encryptionKey: advancedKey,
          env: { account: '123456789012', region: 'ap-northeast-1' },
        });
      }).toThrow('Advanced Parsing が有効ですが');
    });
  });
});
