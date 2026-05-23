import * as cdk from 'aws-cdk-lib';
import { Template, Match } from 'aws-cdk-lib/assertions';
import * as iam from 'aws-cdk-lib/aws-iam';
import { SharedCmekStack } from '../../lib/shared-cmek-stack';

describe('SharedCmekStack', () => {
  let app: cdk.App;
  let switchRoleStack: cdk.Stack;
  let switchRole: iam.Role;

  beforeEach(() => {
    app = new cdk.App();
    switchRoleStack = new cdk.Stack(app, 'SwitchRoleStack', {
      env: { account: '123456789012', region: 'ap-northeast-1' },
    });
    switchRole = new iam.Role(switchRoleStack, 'TestSwitchRole', {
      assumedBy: new iam.AccountPrincipal('123456789012'),
    });
  });

  function createStack(envName?: string): SharedCmekStack {
    return new SharedCmekStack(app, 'TestSharedCmekStack', {
      switchRoles: [switchRole],
      envName,
      env: { account: '123456789012', region: 'ap-northeast-1' },
    });
  }

  describe('KMS Key properties', () => {
    test('creates KMS key with key rotation enabled', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.hasResourceProperties('AWS::KMS::Key', {
        EnableKeyRotation: true,
      });
    });

    test('creates KMS key with Retain deletion policy', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.hasResource('AWS::KMS::Key', {
        DeletionPolicy: 'Retain',
      });
    });

    test('creates key alias with default suffix when envName is not specified', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.hasResourceProperties('AWS::KMS::Alias', {
        AliasName: 'alias/shared-cmek-default',
      });
    });

    test('creates key alias with envName suffix when envName is specified', () => {
      const stack = createStack('prod');
      const template = Template.fromStack(stack);

      template.hasResourceProperties('AWS::KMS::Alias', {
        AliasName: 'alias/shared-cmek-prod',
      });
    });
  });

  describe('Key policies', () => {
    test('grants Bedrock service principal kms:Decrypt and kms:GenerateDataKey', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.hasResourceProperties('AWS::KMS::Key', {
        KeyPolicy: Match.objectLike({
          Statement: Match.arrayWith([
            Match.objectLike({
              Sid: 'AllowBedrockToUseTheKey',
              Effect: 'Allow',
              Principal: Match.objectLike({
                Service: 'bedrock.amazonaws.com',
              }),
              Action: Match.arrayWith(['kms:Decrypt', 'kms:GenerateDataKey']),
            }),
          ]),
        }),
      });
    });

    test('grants AOSS service principal 4 KMS actions', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.hasResourceProperties('AWS::KMS::Key', {
        KeyPolicy: Match.objectLike({
          Statement: Match.arrayWith([
            Match.objectLike({
              Sid: 'AllowOpenSearchServerlessToUseTheKey',
              Effect: 'Allow',
              Principal: Match.objectLike({
                Service: 'aoss.amazonaws.com',
              }),
              Action: Match.arrayWith([
                'kms:Decrypt',
                'kms:GenerateDataKey',
                'kms:CreateGrant',
                'kms:DescribeKey',
              ]),
            }),
          ]),
        }),
      });
    });

    test('grants CloudWatch Logs service principal 6 KMS actions with ArnLike condition', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.hasResourceProperties('AWS::KMS::Key', {
        KeyPolicy: Match.objectLike({
          Statement: Match.arrayWith([
            Match.objectLike({
              Sid: 'AllowCloudWatchLogsToUseTheKey',
              Effect: 'Allow',
              Action: Match.arrayWith([
                'kms:Encrypt',
                'kms:Decrypt',
                'kms:ReEncrypt*',
                'kms:GenerateDataKey*',
                'kms:CreateGrant',
                'kms:DescribeKey',
              ]),
              Condition: Match.objectLike({
                ArnLike: Match.anyValue(),
              }),
            }),
          ]),
        }),
      });
    });
  });

  describe('CfnOutput', () => {
    test('outputs SharedCmekArn with default export name when envName not specified', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.hasOutput('SharedCmekArn', {
        Export: {
          Name: 'SharedCmekArn-default',
        },
      });
    });

    test('outputs SharedCmekArn with envName in export name', () => {
      const stack = createStack('prod');
      const template = Template.fromStack(stack);

      template.hasOutput('SharedCmekArn', {
        Export: {
          Name: 'SharedCmekArn-prod',
        },
      });
    });
  });

  describe('switchRoles permissions', () => {
    test('grants encryptDecrypt permissions to a single switchRole', () => {
      createStack();
      const switchRoleTemplate = Template.fromStack(switchRoleStack);

      switchRoleTemplate.hasResourceProperties('AWS::IAM::Policy', {
        PolicyDocument: Match.objectLike({
          Statement: Match.arrayWith([
            Match.objectLike({
              Action: Match.arrayWith(['kms:Decrypt', 'kms:Encrypt']),
              Effect: 'Allow',
            }),
          ]),
        }),
      });
    });

    test('grants encryptDecrypt permissions to multiple switchRoles', () => {
      const extraRole = new iam.Role(switchRoleStack, 'ExtraSwitchRole', {
        assumedBy: new iam.AccountPrincipal('123456789012'),
      });
      new SharedCmekStack(app, 'TestSharedCmekStackMulti', {
        switchRoles: [switchRole, extraRole],
        env: { account: '123456789012', region: 'ap-northeast-1' },
      });

      const switchRoleTemplate = Template.fromStack(switchRoleStack);
      switchRoleTemplate.resourceCountIs('AWS::IAM::Policy', 2);
    });
  });

  describe('encryptionKey public property', () => {
    test('exposes encryptionKey as a defined public property', () => {
      const stack = createStack();
      expect(stack.encryptionKey).toBeDefined();
    });
  });
});
