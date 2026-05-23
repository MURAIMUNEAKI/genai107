import { CfnIPSet, CfnWebACL } from 'aws-cdk-lib/aws-wafv2';
import { Construct } from 'constructs';

/** WAF scope type */
type WafScope = 'REGIONAL' | 'CLOUDFRONT';

/**
 * Properties for CommonWebAcl construct
 */
export interface CommonWebAclProps {
  /** WAF scope: REGIONAL for API Gateway/ALB, CLOUDFRONT for CloudFront */
  readonly scope: WafScope;
  /** IPv4 CIDR ranges to allow (null = no restriction) */
  readonly allowedIpV4AddressRanges: string[] | null;
  /** IPv6 CIDR ranges to allow (null = no restriction) */
  readonly allowedIpV6AddressRanges: string[] | null;
  /** ISO country codes to allow (null = no restriction) */
  readonly allowedCountryCodes: string[] | null;
}

/**
 * WAF WebACL construct with IP and geo-based access control
 *
 * Creates a WebACL that blocks all traffic by default and allows
 * requests matching the specified IP ranges and/or country codes.
 */
export class CommonWebAcl extends Construct {
  /** ARN of the created WebACL */
  public readonly webAclArn: string;

  constructor(scope: Construct, id: string, props: CommonWebAclProps) {
    super(scope, id);

    const aclRules: CfnWebACL.RuleProperty[] = [];
    const ipV4Enabled = this.hasValues(props.allowedIpV4AddressRanges);
    const ipV6Enabled = this.hasValues(props.allowedIpV6AddressRanges);
    const geoEnabled = this.hasValues(props.allowedCountryCodes);

    // Build IPv4 rule (priority 1)
    if (ipV4Enabled) {
      const ipV4Set = this.createIpSet(
        `IPv4Set${id}`,
        'IPV4',
        props.scope,
        props.allowedIpV4AddressRanges!
      );
      aclRules.push(
        this.buildRule(1, `IpV4Set${geoEnabled ? 'AndGeoMatch' : ''}Rule${id}`, ipV4Set.attrArn, geoEnabled ? props.allowedCountryCodes! : null)
      );
    }

    // Build IPv6 rule (priority 2)
    if (ipV6Enabled) {
      const ipV6Set = this.createIpSet(
        `IPv6Set${id}`,
        'IPV6',
        props.scope,
        props.allowedIpV6AddressRanges!
      );
      aclRules.push(
        this.buildRule(2, `IpV6Set${geoEnabled ? 'AndGeoMatch' : ''}Rule${id}`, ipV6Set.attrArn, geoEnabled ? props.allowedCountryCodes! : null)
      );
    }

    // Geo-only rule when no IP restrictions (priority 3)
    if (!ipV4Enabled && !ipV6Enabled && geoEnabled) {
      aclRules.push(this.buildGeoOnlyRule(3, `GeoMatchRule${id}`, props.allowedCountryCodes!));
    }

    // Create the WebACL
    const acl = new CfnWebACL(this, `WebAcl${id}`, {
      defaultAction: { block: {} },
      name: `WebAcl${id}`,
      scope: props.scope,
      visibilityConfig: {
        cloudWatchMetricsEnabled: true,
        sampledRequestsEnabled: true,
        metricName: `WebAcl${id}`,
      },
      rules: aclRules,
    });

    this.webAclArn = acl.attrArn;
  }

  private hasValues(arr: string[] | null): boolean {
    return arr !== null && arr.length > 0;
  }

  private createIpSet(
    logicalId: string,
    version: 'IPV4' | 'IPV6',
    wafScope: WafScope,
    addresses: string[]
  ): CfnIPSet {
    return new CfnIPSet(this, logicalId, {
      ipAddressVersion: version,
      scope: wafScope,
      addresses: addresses,
    });
  }

  private buildRule(
    priority: number,
    ruleName: string,
    ipSetArn: string,
    countryCodes: string[] | null
  ): CfnWebACL.RuleProperty {
    const baseConfig = this.ruleBaseConfig(ruleName);

    if (countryCodes !== null) {
      // Combined IP + Geo rule with AND statement
      return {
        priority,
        ...baseConfig,
        statement: {
          andStatement: {
            statements: [
              { ipSetReferenceStatement: { arn: ipSetArn } },
              { geoMatchStatement: { countryCodes } },
            ],
          },
        },
      };
    }

    // IP-only rule
    return {
      priority,
      ...baseConfig,
      statement: {
        ipSetReferenceStatement: { arn: ipSetArn },
      },
    };
  }

  private buildGeoOnlyRule(
    priority: number,
    ruleName: string,
    countryCodes: string[]
  ): CfnWebACL.RuleProperty {
    return {
      priority,
      ...this.ruleBaseConfig(ruleName),
      statement: {
        geoMatchStatement: { countryCodes },
      },
    };
  }

  private ruleBaseConfig(name: string) {
    return {
      name,
      action: { allow: {} },
      visibilityConfig: {
        sampledRequestsEnabled: true,
        cloudWatchMetricsEnabled: true,
        metricName: name,
      },
    };
  }
}
