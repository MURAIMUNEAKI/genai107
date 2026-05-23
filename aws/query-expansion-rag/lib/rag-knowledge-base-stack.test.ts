import { App, Stack } from 'aws-cdk-lib';
import { Template } from 'aws-cdk-lib/assertions';
import * as iam from 'aws-cdk-lib/aws-iam';
import { RagKnowledgeBaseStack } from './rag-knowledge-base-stack';

describe('RagKnowledgeBaseStack - OssIndex Race Condition Fix', () => {
  let app: App;
  let stack: RagKnowledgeBaseStack;
  let template: Template;

  beforeEach(() => {
    app = new App({
      context: {
        embeddingModelId: 'amazon.titan-embed-text-v2:0',
        ragKnowledgeBaseStandbyReplicas: false,
        ragKnowledgeBaseAdvancedParsing: false,
      },
    });

    // SwitchRoleを作成（RagKnowledgeBaseStackの依存）
    const switchRoleStack = new Stack(app, 'TestSwitchRoleStack');
    const switchRole = new iam.Role(switchRoleStack, 'TestSwitchRole', {
      assumedBy: new iam.AccountPrincipal('123456789012'),
    });

    stack = new RagKnowledgeBaseStack(app, 'TestRagKnowledgeBaseStack', {
      switchRole,
      appName: 'test-app',
    });

    template = Template.fromStack(stack);
  });

  describe('CustomResource serviceTimeout', () => {
    test('should have serviceTimeout set to 300 seconds', () => {
      // CustomResourceのLogicalIDを特定
      const resources = template.toJSON().Resources;
      const customResourceLogicalId = Object.keys(resources).find(
        (key) => resources[key].Type === 'Custom::OssIndex'
      );

      expect(customResourceLogicalId).toBeDefined();

      // ServiceTimeoutプロパティの確認（CloudFormationテンプレートでは文字列として扱われる）
      const customResource = resources[customResourceLogicalId!];
      expect(customResource.Properties.ServiceTimeout).toBe('300');
    });
  });

  describe('CustomResource dependencies', () => {
    test('should depend on Lambda DefaultPolicy', () => {
      const resources = template.toJSON().Resources;

      // CustomResourceのLogicalIDを特定
      const customResourceLogicalId = Object.keys(resources).find(
        (key) => resources[key].Type === 'Custom::OssIndex'
      );
      expect(customResourceLogicalId).toBeDefined();

      // OpenSearchServerlessIndex Lambda関数のDefaultPolicyを特定
      // (ServiceRoleDefaultPolicyという名前を含むもの)
      const defaultPolicyLogicalId = Object.keys(resources).find(
        (key) =>
          resources[key].Type === 'AWS::IAM::Policy' &&
          key.includes('OpenSearchServerlessIndex') &&
          key.includes('DefaultPolicy')
      );
      expect(defaultPolicyLogicalId).toBeDefined();

      // CustomResourceがDefaultPolicyに依存していることを確認
      const customResource = resources[customResourceLogicalId!];
      expect(customResource.DependsOn).toBeDefined();
      expect(customResource.DependsOn).toContain(defaultPolicyLogicalId);
    });

    test('should preserve existing dependencies', () => {
      const resources = template.toJSON().Resources;

      // Collectionを特定
      const collectionLogicalId = Object.keys(resources).find(
        (key) =>
          resources[key].Type ===
          'AWS::OpenSearchServerless::Collection'
      );
      expect(collectionLogicalId).toBeDefined();

      const collection = resources[collectionLogicalId!];

      // Collectionが AccessPolicy, NetworkPolicy, EncryptionPolicy に依存していることを確認
      expect(collection.DependsOn).toBeDefined();
      expect(collection.DependsOn.length).toBeGreaterThanOrEqual(3);

      // KnowledgeBaseを特定
      const knowledgeBaseLogicalId = Object.keys(resources).find(
        (key) => resources[key].Type === 'AWS::Bedrock::KnowledgeBase'
      );
      expect(knowledgeBaseLogicalId).toBeDefined();

      const knowledgeBase = resources[knowledgeBaseLogicalId!];

      // KnowledgeBaseがCollectionとCustomResourceに依存していることを確認
      expect(knowledgeBase.DependsOn).toBeDefined();
      expect(knowledgeBase.DependsOn).toContain(collectionLogicalId);

      const customResourceLogicalId = Object.keys(resources).find(
        (key) => resources[key].Type === 'Custom::OssIndex'
      );
      expect(knowledgeBase.DependsOn).toContain(customResourceLogicalId);
    });
  });
});
