/**
 * Unit tests for ApiWafStack
 * These tests verify the baseline behavior before license migration rewrite.
 */
import * as cdk from 'aws-cdk-lib';
import { Template } from 'aws-cdk-lib/assertions';
import { ApiWafStack } from '../../lib/api-waf-stack';

describe('ApiWafStack', () => {
  let app: cdk.App;

  beforeEach(() => {
    app = new cdk.App();
  });

  describe('with IPv4 address ranges', () => {
    test('creates WAF WebACL with IP set', () => {
      const stack = new ApiWafStack(app, 'TestApiWafStack', {
        allowedIpV4AddressRanges: ['10.0.0.0/8', '192.168.0.0/16'],
        allowedIpV6AddressRanges: null,
        allowedCountryCodes: null,
      });

      const template = Template.fromStack(stack);

      // Verify WebACL is created
      template.resourceCountIs('AWS::WAFv2::WebACL', 1);

      // Verify IP set is created for IPv4
      template.resourceCountIs('AWS::WAFv2::IPSet', 1);
      template.hasResourceProperties('AWS::WAFv2::IPSet', {
        IPAddressVersion: 'IPV4',
        Scope: 'REGIONAL',
      });
    });

    test('exposes webAclArn property', () => {
      const stack = new ApiWafStack(app, 'TestApiWafStack', {
        allowedIpV4AddressRanges: ['10.0.0.0/8'],
        allowedIpV6AddressRanges: null,
        allowedCountryCodes: null,
      });

      expect(stack.webAclArn).toBeDefined();
    });
  });

  describe('with country codes', () => {
    test('creates WAF WebACL with geo match rule', () => {
      const stack = new ApiWafStack(app, 'TestApiWafStack', {
        allowedIpV4AddressRanges: null,
        allowedIpV6AddressRanges: null,
        allowedCountryCodes: ['JP', 'US'],
      });

      const template = Template.fromStack(stack);

      template.resourceCountIs('AWS::WAFv2::WebACL', 1);
      template.hasResourceProperties('AWS::WAFv2::WebACL', {
        Scope: 'REGIONAL',
      });
    });
  });

  describe('with all null props', () => {
    test('creates WAF WebACL without restrictions', () => {
      const stack = new ApiWafStack(app, 'TestApiWafStack', {
        allowedIpV4AddressRanges: null,
        allowedIpV6AddressRanges: null,
        allowedCountryCodes: null,
      });

      const template = Template.fromStack(stack);

      template.resourceCountIs('AWS::WAFv2::WebACL', 1);
    });
  });
});
