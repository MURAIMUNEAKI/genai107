/**
 * Unit tests for RagS3VectorsKbStack
 * Verifies S3 Vectors backend Knowledge Base stack resource generation.
 */
import * as cdk from 'aws-cdk-lib';
import { Template } from 'aws-cdk-lib/assertions';
import * as iam from 'aws-cdk-lib/aws-iam';
import { RagS3VectorsKbStack } from '../../lib/rag-s3vectors-kb-stack';

describe('RagS3VectorsKbStack', () => {
  let app: cdk.App;
  let switchRoleStack: cdk.Stack;
  let switchRole: iam.Role;

  beforeEach(() => {
    app = new cdk.App({
      context: {
        embeddingModelId: 'amazon.titan-embed-text-v2:0',
      },
    });
    switchRoleStack = new cdk.Stack(app, 'SwitchRoleStack', {
      env: { account: '123456789012', region: 'ap-northeast-1' },
    });
    switchRole = new iam.Role(switchRoleStack, 'TestSwitchRole', {
      assumedBy: new iam.AccountPrincipal('123456789012'),
    });
  });

  function createStack(): RagS3VectorsKbStack {
    return new RagS3VectorsKbStack(app, 'TestRagS3VectorsKbStack', {
      appName: 'test-app',
      switchRole: switchRole,
      env: { account: '123456789012', region: 'ap-northeast-1' },
    });
  }

  describe('S3 Vector Resources', () => {
    test('creates S3 Vector Bucket', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.resourceCountIs('AWS::S3Vectors::VectorBucket', 1);
    });

    test('creates S3 Vector Index', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.resourceCountIs('AWS::S3Vectors::Index', 1);
    });

    test('creates S3 Vector Index with correct dimension for titan-embed-text-v2:0', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.hasResourceProperties('AWS::S3Vectors::Index', {
        DataType: 'float32',
        Dimension: 1024,
        DistanceMetric: 'cosine',
      });
    });

    test('creates S3 Vector Index with nonFilterableMetadataKeys', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.hasResourceProperties('AWS::S3Vectors::Index', {
        MetadataConfiguration: {
          NonFilterableMetadataKeys: ['AMAZON_BEDROCK_TEXT', 'AMAZON_BEDROCK_METADATA'],
        },
      });
    });
  });

  describe('Bedrock Knowledge Base', () => {
    test('creates Bedrock Knowledge Base with S3_VECTORS storage type', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.resourceCountIs('AWS::Bedrock::KnowledgeBase', 1);
      template.hasResourceProperties('AWS::Bedrock::KnowledgeBase', {
        StorageConfiguration: {
          Type: 'S3_VECTORS',
        },
      });
    });

    test('creates Bedrock Data Source', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.resourceCountIs('AWS::Bedrock::DataSource', 1);
    });

    test('creates Bedrock Data Source with docs/ prefix', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.hasResourceProperties('AWS::Bedrock::DataSource', {
        DataSourceConfiguration: {
          S3Configuration: {
            InclusionPrefixes: ['docs/'],
          },
          Type: 'S3',
        },
      });
    });
  });

  describe('KMS Encryption', () => {
    test('creates individual KMS key when no external key provided', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      const keys = template.findResources('AWS::KMS::Key');
      expect(Object.keys(keys).length).toBeGreaterThanOrEqual(1);
    });

    test('KMS key has rotation enabled', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.hasResourceProperties('AWS::KMS::Key', {
        EnableKeyRotation: true,
      });
    });
  });

  describe('S3 Buckets', () => {
    test('creates S3 buckets for data source and access logs', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      const buckets = template.findResources('AWS::S3::Bucket');
      expect(Object.keys(buckets).length).toBeGreaterThanOrEqual(2);
    });
  });

  describe('IAM Roles', () => {
    test('creates Knowledge Base IAM role', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      const roles = template.findResources('AWS::IAM::Role');
      expect(Object.keys(roles).length).toBeGreaterThanOrEqual(1);
    });
  });

  describe('Public Properties', () => {
    test('exposes knowledgeBaseId', () => {
      const stack = createStack();
      expect(stack.knowledgeBaseId).toBeDefined();
    });

    test('exposes dataSourceBucketName', () => {
      const stack = createStack();
      expect(stack.dataSourceBucketName).toBeDefined();
    });

    test('exposes encryptionKey', () => {
      const stack = createStack();
      expect(stack.encryptionKey).toBeDefined();
    });
  });

  describe('Embedding Model Validation', () => {
    test('throws error for unsupported embedding model', () => {
      const invalidApp = new cdk.App({
        context: {
          embeddingModelId: 'unsupported-model',
        },
      });
      const invalidSwitchRoleStack = new cdk.Stack(invalidApp, 'SwitchRoleStack', {
        env: { account: '123456789012', region: 'ap-northeast-1' },
      });
      const invalidSwitchRole = new iam.Role(invalidSwitchRoleStack, 'TestSwitchRole', {
        assumedBy: new iam.AccountPrincipal('123456789012'),
      });

      expect(() => {
        new RagS3VectorsKbStack(invalidApp, 'TestStack', {
          appName: 'test-app',
          switchRole: invalidSwitchRole,
          env: { account: '123456789012', region: 'ap-northeast-1' },
        });
      }).toThrow(/embeddingModelId が無効な値です/);
    });
  });
});
