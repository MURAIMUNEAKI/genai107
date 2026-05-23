/**
 * Unit tests for RagLambdaApiStack
 * These tests verify the baseline behavior before license migration rewrite.
 */
import * as cdk from 'aws-cdk-lib';
import { Template, Match } from 'aws-cdk-lib/assertions';
import * as iam from 'aws-cdk-lib/aws-iam';
import * as kms from 'aws-cdk-lib/aws-kms';
import { RagLambdaApiStack } from '../../lib/rag-lambda-api-stack';

describe('RagLambdaApiStack', () => {
  let app: cdk.App;
  let switchRoleStack: cdk.Stack;
  let switchRole: iam.Role;
  let encryptionKey: kms.Key;

  beforeEach(() => {
    app = new cdk.App();
    switchRoleStack = new cdk.Stack(app, 'SwitchRoleStack', {
      env: { account: '123456789012', region: 'ap-northeast-1' },
    });
    switchRole = new iam.Role(switchRoleStack, 'TestSwitchRole', {
      assumedBy: new iam.AccountPrincipal('123456789012'),
    });
    encryptionKey = new kms.Key(switchRoleStack, 'TestKey');
  });

  function createStack(): cdk.Stack {
    return new RagLambdaApiStack(app, 'TestRagLambdaApiStack', {
      appName: 'test-app',
      webAclArn: 'arn:aws:wafv2:ap-northeast-1:123456789012:regional/webacl/test/abc123',
      knowledgeBaseId: 'kb-test-123',
      switchRole: switchRole,
      logLevel: 'INFO',
      appParamFile: 'test.toml',
      encryptionKey: encryptionKey,
      apiLambdaIntegrationTimeout: 29,
      env: { account: '123456789012', region: 'ap-northeast-1' },
    });
  }

  describe('API Gateway', () => {
    test('creates REST API with REGIONAL endpoint', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.hasResourceProperties('AWS::ApiGateway::RestApi', {
        EndpointConfiguration: {
          Types: ['REGIONAL'],
        },
      });
    });

    test('creates usage plan with throttle settings', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.hasResourceProperties('AWS::ApiGateway::UsagePlan', {
        Throttle: {
          BurstLimit: 100,
          RateLimit: 1000,
        },
        Quota: {
          Limit: 1000,
          Period: 'DAY',
        },
      });
    });

    test('creates API key', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.resourceCountIs('AWS::ApiGateway::ApiKey', 1);
    });

    test('creates gateway responses for 4XX and 5XX', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.resourceCountIs('AWS::ApiGateway::GatewayResponse', 2);
    });

    test('creates POST /invoke endpoint', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.hasResourceProperties('AWS::ApiGateway::Method', {
        HttpMethod: 'POST',
        ApiKeyRequired: true,
      });

      template.hasResourceProperties('AWS::ApiGateway::Resource', {
        PathPart: 'invoke',
      });
    });
  });

  describe('WAF Association', () => {
    test('associates WAF WebACL with API Gateway', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.hasResourceProperties('AWS::WAFv2::WebACLAssociation', {
        WebACLArn: 'arn:aws:wafv2:ap-northeast-1:123456789012:regional/webacl/test/abc123',
      });
    });
  });

  describe('Lambda Integration', () => {
    test('creates Lambda function', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.resourceCountIs('AWS::Lambda::Function', 1);
    });
  });

  describe('CloudWatch Logging', () => {
    test('creates log group for API Gateway', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      // At least one log group for API Gateway
      // At least one log group should exist
      const resources = template.findResources('AWS::Logs::LogGroup');
      expect(Object.keys(resources).length).toBeGreaterThanOrEqual(1);
    });
  });

  describe('SwitchRole Permissions', () => {
    test('grants apigateway:GET on the API key to switch role', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.hasResourceProperties('AWS::IAM::Policy', {
        PolicyDocument: {
          Statement: Match.arrayWith([
            Match.objectLike({
              Action: 'apigateway:GET',
              Effect: 'Allow',
            }),
          ]),
        },
      });
    });
  });

  describe('Stack Outputs', () => {
    test('outputs API endpoint', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.hasOutput('ApiEndpoint', {});
    });

    test('outputs API key ID', () => {
      const stack = createStack();
      const template = Template.fromStack(stack);

      template.hasOutput('ApiKeyId', {});
    });
  });
});
