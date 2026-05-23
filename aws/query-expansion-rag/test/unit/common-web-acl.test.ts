/**
 * Unit tests for CommonWebAcl construct
 * These tests verify the baseline behavior before license migration rewrite.
 */
import * as cdk from 'aws-cdk-lib';
import { Template, Match } from 'aws-cdk-lib/assertions';
import { CommonWebAcl } from '../../lib/constructs/common-web-acl';

describe('CommonWebAcl', () => {
  let app: cdk.App;
  let stack: cdk.Stack;

  beforeEach(() => {
    app = new cdk.App();
    stack = new cdk.Stack(app, 'TestStack');
  });

  describe('with IPv4 address ranges only', () => {
    test('creates WebACL with IPv4 IP set rule', () => {
      new CommonWebAcl(stack, 'TestWebAcl', {
        scope: 'REGIONAL',
        allowedIpV4AddressRanges: ['10.0.0.0/8', '192.168.0.0/16'],
        allowedIpV6AddressRanges: null,
        allowedCountryCodes: null,
      });

      const template = Template.fromStack(stack);

      // Verify WebACL is created with block default action
      template.hasResourceProperties('AWS::WAFv2::WebACL', {
        DefaultAction: { Block: {} },
        Scope: 'REGIONAL',
      });

      // Verify IPv4 IP set is created
      template.hasResourceProperties('AWS::WAFv2::IPSet', {
        IPAddressVersion: 'IPV4',
        Scope: 'REGIONAL',
        Addresses: ['10.0.0.0/8', '192.168.0.0/16'],
      });
    });
  });

  describe('with IPv6 address ranges only', () => {
    test('creates WebACL with IPv6 IP set rule', () => {
      new CommonWebAcl(stack, 'TestWebAcl', {
        scope: 'REGIONAL',
        allowedIpV4AddressRanges: null,
        allowedIpV6AddressRanges: ['2001:db8::/32'],
        allowedCountryCodes: null,
      });

      const template = Template.fromStack(stack);

      template.hasResourceProperties('AWS::WAFv2::IPSet', {
        IPAddressVersion: 'IPV6',
        Scope: 'REGIONAL',
        Addresses: ['2001:db8::/32'],
      });
    });
  });

  describe('with country codes only', () => {
    test('creates WebACL with geo match rule', () => {
      new CommonWebAcl(stack, 'TestWebAcl', {
        scope: 'REGIONAL',
        allowedIpV4AddressRanges: null,
        allowedIpV6AddressRanges: null,
        allowedCountryCodes: ['JP', 'US'],
      });

      const template = Template.fromStack(stack);

      // Verify geo match rule is in the WebACL rules
      template.hasResourceProperties('AWS::WAFv2::WebACL', {
        Rules: Match.arrayWith([
          Match.objectLike({
            Statement: {
              GeoMatchStatement: {
                CountryCodes: ['JP', 'US'],
              },
            },
          }),
        ]),
      });
    });
  });

  describe('with IPv4 and country codes', () => {
    test('creates WebACL with AND statement combining IP set and geo match', () => {
      new CommonWebAcl(stack, 'TestWebAcl', {
        scope: 'REGIONAL',
        allowedIpV4AddressRanges: ['10.0.0.0/8'],
        allowedIpV6AddressRanges: null,
        allowedCountryCodes: ['JP'],
      });

      const template = Template.fromStack(stack);

      // Verify AND statement is used - the CFn output uses IPSetReferenceStatement (capital IP)
      template.hasResourceProperties('AWS::WAFv2::WebACL', {
        Rules: Match.arrayWith([
          Match.objectLike({
            Statement: {
              AndStatement: Match.objectLike({
                Statements: Match.arrayWith([
                  Match.objectLike({ IPSetReferenceStatement: Match.anyValue() }),
                  Match.objectLike({
                    GeoMatchStatement: { CountryCodes: ['JP'] },
                  }),
                ]),
              }),
            },
          }),
        ]),
      });
    });
  });

  describe('with all null props', () => {
    test('creates WebACL with no rules (block all)', () => {
      new CommonWebAcl(stack, 'TestWebAcl', {
        scope: 'REGIONAL',
        allowedIpV4AddressRanges: null,
        allowedIpV6AddressRanges: null,
        allowedCountryCodes: null,
      });

      const template = Template.fromStack(stack);

      template.hasResourceProperties('AWS::WAFv2::WebACL', {
        DefaultAction: { Block: {} },
        Rules: [],
      });

      // No IP sets should be created
      template.resourceCountIs('AWS::WAFv2::IPSet', 0);
    });
  });

  describe('with IPv6 and country codes', () => {
    test('creates WebACL with AND statement combining IPv6 IP set and geo match', () => {
      new CommonWebAcl(stack, 'TestWebAcl', {
        scope: 'REGIONAL',
        allowedIpV4AddressRanges: null,
        allowedIpV6AddressRanges: ['2001:db8::/32'],
        allowedCountryCodes: ['JP'],
      });

      const template = Template.fromStack(stack);

      template.hasResourceProperties('AWS::WAFv2::IPSet', {
        IPAddressVersion: 'IPV6',
      });

      template.hasResourceProperties('AWS::WAFv2::WebACL', {
        Rules: Match.arrayWith([
          Match.objectLike({
            Name: Match.stringLikeRegexp('IpV6SetAndGeoMatch.*'),
          }),
        ]),
      });
    });
  });

  describe('CLOUDFRONT scope', () => {
    test('creates WebACL with CLOUDFRONT scope', () => {
      new CommonWebAcl(stack, 'TestWebAcl', {
        scope: 'CLOUDFRONT',
        allowedIpV4AddressRanges: ['10.0.0.0/8'],
        allowedIpV6AddressRanges: null,
        allowedCountryCodes: null,
      });

      const template = Template.fromStack(stack);

      template.hasResourceProperties('AWS::WAFv2::WebACL', {
        Scope: 'CLOUDFRONT',
      });

      template.hasResourceProperties('AWS::WAFv2::IPSet', {
        Scope: 'CLOUDFRONT',
      });
    });
  });

  describe('webAclArn property', () => {
    test('exposes webAclArn', () => {
      const webAcl = new CommonWebAcl(stack, 'TestWebAcl', {
        scope: 'REGIONAL',
        allowedIpV4AddressRanges: null,
        allowedIpV6AddressRanges: null,
        allowedCountryCodes: null,
      });

      expect(webAcl.webAclArn).toBeDefined();
    });
  });
});
