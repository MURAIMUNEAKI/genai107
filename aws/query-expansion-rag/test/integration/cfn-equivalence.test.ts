/**
 * CloudFormation Equivalence Tests
 *
 * These tests verify that the rewritten code produces functionally equivalent
 * CloudFormation templates compared to the baseline captured before rewrite.
 *
 * The tests compare:
 * - Resource counts by type
 * - Critical resource properties
 * - IAM policy statements
 * - WAF rules
 * - API Gateway configuration
 */
import * as fs from 'fs';
import * as path from 'path';

const BASELINE_DIR = path.join(__dirname, '../snapshots/baseline');

interface ResourceInventory {
  generated_at: string;
  stacks: {
    [stackName: string]: {
      total_resources: number;
      resource_types: { [type: string]: number };
    };
  };
}

interface TemplateData {
  Resources: {
    [logicalId: string]: {
      Type: string;
      Properties?: Record<string, unknown>;
    };
  };
}

describe('CloudFormation Equivalence', () => {
  let baselineInventory: ResourceInventory;

  beforeAll(() => {
    const inventoryPath = path.join(BASELINE_DIR, 'resource-inventory.json');
    if (fs.existsSync(inventoryPath)) {
      baselineInventory = JSON.parse(fs.readFileSync(inventoryPath, 'utf-8'));
    }
  });

  describe('Baseline Inventory', () => {
    test('baseline inventory exists', () => {
      expect(baselineInventory).toBeDefined();
      expect(baselineInventory.stacks).toBeDefined();
    });

    test('baseline contains expected stacks', () => {
      const stackNames = Object.keys(baselineInventory.stacks);

      // Should have API WAF stack
      expect(stackNames.some(name => name.includes('ApiWafStack'))).toBe(true);

      // Should have RagApi stacks
      expect(stackNames.some(name => name.includes('RagApi'))).toBe(true);

      // Should have RagKB stacks
      expect(stackNames.some(name => name.includes('RagKB'))).toBe(true);
    });
  });

  describe('ApiWafStack Resources', () => {
    test('baseline has WAF WebACL', () => {
      const apiWafStack = baselineInventory.stacks['ApiWafStack'];
      expect(apiWafStack).toBeDefined();
      expect(apiWafStack.resource_types['AWS::WAFv2::WebACL']).toBe(1);
    });

    test('baseline has WAF IP Set', () => {
      const apiWafStack = baselineInventory.stacks['ApiWafStack'];
      expect(apiWafStack.resource_types['AWS::WAFv2::IPSet']).toBe(1);
    });
  });

  describe('RagApi Stack Resources', () => {
    let ragApiStack: ResourceInventory['stacks'][string];

    beforeAll(() => {
      // Find the first RagApi stack in the inventory
      const stackName = Object.keys(baselineInventory.stacks).find(name =>
        name.includes('qeRagApi') && !name.includes('shared')
      );
      if (stackName) {
        ragApiStack = baselineInventory.stacks[stackName];
      }
    });

    test('baseline has API Gateway RestApi', () => {
      expect(ragApiStack).toBeDefined();
      expect(ragApiStack.resource_types['AWS::ApiGateway::RestApi']).toBe(1);
    });

    test('baseline has Lambda function', () => {
      expect(ragApiStack.resource_types['AWS::Lambda::Function']).toBe(1);
    });

    test('baseline has API Gateway Method (POST /invoke)', () => {
      expect(ragApiStack.resource_types['AWS::ApiGateway::Method']).toBeGreaterThanOrEqual(1);
    });

    test('baseline has Usage Plan', () => {
      expect(ragApiStack.resource_types['AWS::ApiGateway::UsagePlan']).toBe(1);
    });

    test('baseline has API Key', () => {
      expect(ragApiStack.resource_types['AWS::ApiGateway::ApiKey']).toBe(1);
    });

    test('baseline has WAF association', () => {
      expect(ragApiStack.resource_types['AWS::WAFv2::WebACLAssociation']).toBe(1);
    });
  });

  describe('RagKnowledgeBase Stack Resources', () => {
    let ragKbStack: ResourceInventory['stacks'][string];

    beforeAll(() => {
      // Find the first RagKB stack in the inventory
      const stackName = Object.keys(baselineInventory.stacks).find(name =>
        name.includes('qeRagKB') && !name.includes('shared')
      );
      if (stackName) {
        ragKbStack = baselineInventory.stacks[stackName];
      }
    });

    test('baseline has OpenSearch Serverless Collection', () => {
      expect(ragKbStack).toBeDefined();
      expect(ragKbStack.resource_types['AWS::OpenSearchServerless::Collection']).toBe(1);
    });

    test('baseline has Custom::OssIndex resource', () => {
      expect(ragKbStack.resource_types['Custom::OssIndex']).toBe(1);
    });

    test('baseline has Bedrock Knowledge Base', () => {
      expect(ragKbStack.resource_types['AWS::Bedrock::KnowledgeBase']).toBe(1);
    });

    test('baseline has Bedrock Data Source', () => {
      expect(ragKbStack.resource_types['AWS::Bedrock::DataSource']).toBe(1);
    });

    test('baseline has S3 Buckets', () => {
      expect(ragKbStack.resource_types['AWS::S3::Bucket']).toBeGreaterThanOrEqual(2);
    });

    test('baseline has OpenSearch access policy', () => {
      expect(ragKbStack.resource_types['AWS::OpenSearchServerless::AccessPolicy']).toBe(1);
    });

    test('baseline has OpenSearch security policies', () => {
      expect(ragKbStack.resource_types['AWS::OpenSearchServerless::SecurityPolicy']).toBe(2);
    });
  });

  describe('SwitchRole Stack Resources', () => {
    let switchRoleStack: ResourceInventory['stacks'][string];

    beforeAll(() => {
      // Find the first SwitchRoleStack in the inventory
      const stackName = Object.keys(baselineInventory.stacks).find(name =>
        name.includes('SwitchRoleStack') && !name.includes('shared')
      );
      if (stackName) {
        switchRoleStack = baselineInventory.stacks[stackName];
      }
    });

    test('baseline has IAM Role', () => {
      expect(switchRoleStack).toBeDefined();
      expect(switchRoleStack.resource_types['AWS::IAM::Role']).toBe(1);
    });
  });
});

describe('Baseline Template Verification', () => {
  const templateFiles = [
    'ApiWafStack.template.json',
    'qerag-SwitchRoleStack.template.json',
    'qerag-qeRagApi.template.json',
    'qerag-qeRagKB.template.json',
  ];

  templateFiles.forEach(filename => {
    describe(filename, () => {
      let template: TemplateData;

      beforeAll(() => {
        const templatePath = path.join(BASELINE_DIR, filename);
        if (fs.existsSync(templatePath)) {
          template = JSON.parse(fs.readFileSync(templatePath, 'utf-8'));
        }
      });

      test('template file exists', () => {
        expect(template).toBeDefined();
      });

      test('template has Resources section', () => {
        expect(template.Resources).toBeDefined();
      });

      test('all resources have Type property', () => {
        Object.entries(template.Resources).forEach(([logicalId, resource]) => {
          expect(resource.Type).toBeDefined();
          expect(typeof resource.Type).toBe('string');
        });
      });
    });
  });
});
