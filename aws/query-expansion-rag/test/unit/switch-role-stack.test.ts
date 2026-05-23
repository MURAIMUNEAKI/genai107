/**
 * Unit tests for SwitchRoleForBedrockFlowsDeveloperStack
 * These tests verify the baseline behavior before license migration rewrite.
 */
import * as cdk from 'aws-cdk-lib';
import { Template, Match } from 'aws-cdk-lib/assertions';
import { SwitchRoleForBedrockFlowsDeveloperStack } from '../../lib/switch-role-stack';

describe('SwitchRoleForBedrockFlowsDeveloperStack', () => {
  let app: cdk.App;

  beforeEach(() => {
    app = new cdk.App();
  });

  describe('IAM Role creation', () => {
    test('creates IAM role with SSO assume role policy', () => {
      const stack = new SwitchRoleForBedrockFlowsDeveloperStack(
        app,
        'TestSwitchRoleStack',
        {
          idcUserNames: ['user1', 'user2'],
          switchRoleName: 'AWSReservedSSO_AdminRole_abc123',
          appName: 'test-app',
          env: { account: '123456789012', region: 'ap-northeast-1' },
        }
      );

      const template = Template.fromStack(stack);

      // Verify IAM role is created
      template.resourceCountIs('AWS::IAM::Role', 1);

      // Verify assume role policy has SSO condition
      template.hasResourceProperties('AWS::IAM::Role', {
        AssumeRolePolicyDocument: Match.objectLike({
          Statement: Match.arrayWith([
            Match.objectLike({
              Effect: 'Allow',
              Principal: Match.objectLike({
                AWS: Match.anyValue(),
              }),
              Condition: Match.objectLike({
                StringLike: Match.objectLike({
                  'aws:userid': ['*:user1', '*:user2'],
                }),
              }),
            }),
          ]),
        }),
      });
    });

    test('attaches CloudWatchLogsReadOnlyAccess managed policy', () => {
      const stack = new SwitchRoleForBedrockFlowsDeveloperStack(
        app,
        'TestSwitchRoleStack',
        {
          idcUserNames: ['user1'],
          switchRoleName: 'AWSReservedSSO_AdminRole_abc123',
          appName: 'test-app',
          env: { account: '123456789012', region: 'ap-northeast-1' },
        }
      );

      const template = Template.fromStack(stack);

      template.hasResourceProperties('AWS::IAM::Role', {
        ManagedPolicyArns: Match.arrayWith([
          Match.objectLike({
            'Fn::Join': Match.arrayWith([
              Match.arrayWith([
                Match.stringLikeRegexp('.*CloudWatchLogsReadOnlyAccess.*'),
              ]),
            ]),
          }),
        ]),
      });
    });
  });

  describe('Bedrock permissions', () => {
    test('includes Bedrock base policy with foundation model access', () => {
      const stack = new SwitchRoleForBedrockFlowsDeveloperStack(
        app,
        'TestSwitchRoleStack',
        {
          idcUserNames: ['user1'],
          switchRoleName: 'AWSReservedSSO_AdminRole_abc123',
          appName: 'test-app',
          env: { account: '123456789012', region: 'ap-northeast-1' },
        }
      );

      const template = Template.fromStack(stack);

      // Verify Bedrock actions are allowed
      template.hasResourceProperties('AWS::IAM::Role', {
        Policies: Match.arrayWith([
          Match.objectLike({
            PolicyName: 'BedrockBasePolicy',
            PolicyDocument: Match.objectLike({
              Statement: Match.arrayWith([
                Match.objectLike({
                  Action: Match.arrayWith([
                    'bedrock:GetFoundationModel',
                    'bedrock:InvokeModel',
                  ]),
                }),
              ]),
            }),
          }),
        ]),
      });
    });

    test('includes List* actions for Bedrock', () => {
      const stack = new SwitchRoleForBedrockFlowsDeveloperStack(
        app,
        'TestSwitchRoleStack',
        {
          idcUserNames: ['user1'],
          switchRoleName: 'AWSReservedSSO_AdminRole_abc123',
          appName: 'test-app',
          env: { account: '123456789012', region: 'ap-northeast-1' },
        }
      );

      const template = Template.fromStack(stack);

      template.hasResourceProperties('AWS::IAM::Role', {
        Policies: Match.arrayWith([
          Match.objectLike({
            PolicyDocument: Match.objectLike({
              Statement: Match.arrayWith([
                Match.objectLike({
                  Action: Match.arrayWith(['bedrock:ListFoundationModels']),
                  Resource: '*',
                }),
              ]),
            }),
          }),
        ]),
      });
    });
  });

  describe('Stack outputs', () => {
    test('outputs switch role name', () => {
      const stack = new SwitchRoleForBedrockFlowsDeveloperStack(
        app,
        'TestSwitchRoleStack',
        {
          idcUserNames: ['user1'],
          switchRoleName: 'AWSReservedSSO_AdminRole_abc123',
          appName: 'test-app',
          env: { account: '123456789012', region: 'ap-northeast-1' },
        }
      );

      const template = Template.fromStack(stack);

      template.hasOutput('SwitchRoleName', {});
    });
  });

  describe('Public properties', () => {
    test('exposes switchRole property', () => {
      const stack = new SwitchRoleForBedrockFlowsDeveloperStack(
        app,
        'TestSwitchRoleStack',
        {
          idcUserNames: ['user1'],
          switchRoleName: 'AWSReservedSSO_AdminRole_abc123',
          appName: 'test-app',
          env: { account: '123456789012', region: 'ap-northeast-1' },
        }
      );

      expect(stack.switchRole).toBeDefined();
    });
  });
});
