'use strict';

// Mock external modules before loading the module under test
jest.mock('@opensearch-project/opensearch');
jest.mock('@opensearch-project/opensearch/aws', () => ({
  AwsSigv4Signer: jest.fn().mockReturnValue({}),
}));
jest.mock('@aws-sdk/credential-provider-node', () => ({
  defaultProvider: jest.fn().mockReturnValue(() => Promise.resolve({})),
}));

const { Client } = require('@opensearch-project/opensearch');
const { handler } = require('../../custom-resources/oss-index');

const BASE_RESOURCE_PROPS = {
  collectionId: 'test-collection-id',
  vectorIndexName: 'test-index',
  metadataField: 'metadata',
  textField: 'text',
  vectorField: 'vector',
  vectorDimension: '1536',
};

const BASE_CFN_EVENT = {
  StackId: 'arn:aws:cloudformation:ap-northeast-1:123456789012:stack/TestStack/abc',
  RequestId: 'test-request-id',
  LogicalResourceId: 'TestResource',
  ResponseURL: 'https://cloudformation-response.example.com/response',
  ResourceProperties: BASE_RESOURCE_PROPS,
};

let mockIndicesCreate;
let mockIndicesDelete;

beforeEach(() => {
  jest.useFakeTimers();

  mockIndicesCreate = jest.fn().mockResolvedValue({});
  mockIndicesDelete = jest.fn().mockResolvedValue({});

  Client.mockImplementation(() => ({
    indices: {
      create: mockIndicesCreate,
      delete: mockIndicesDelete,
    },
  }));

  global.fetch = jest.fn().mockResolvedValue({
    text: jest.fn().mockResolvedValue('OK'),
  });
});

afterEach(() => {
  jest.useRealTimers();
  jest.clearAllMocks();
});

describe('oss-index handler', () => {
  describe('Create event', () => {
    test('calls indices.create with index name and correct body', async () => {
      const event = { ...BASE_CFN_EVENT, RequestType: 'Create' };

      const promise = handler(event, {});
      await jest.runAllTimersAsync();
      await promise;

      expect(mockIndicesCreate).toHaveBeenCalledTimes(1);
      expect(mockIndicesCreate).toHaveBeenCalledWith(
        expect.objectContaining({
          index: 'test-index',
          body: expect.objectContaining({
            mappings: expect.objectContaining({
              properties: expect.objectContaining({
                metadata: expect.objectContaining({ type: 'text', index: false }),
                text: expect.objectContaining({ type: 'text', analyzer: 'custom_kuromoji_analyzer' }),
                vector: expect.objectContaining({ type: 'knn_vector', dimension: 1536 }),
              }),
            }),
            settings: expect.objectContaining({
              index: expect.objectContaining({ knn: true }),
            }),
          }),
        })
      );
    });

    test('sends CFn SUCCESS response after index creation', async () => {
      const event = { ...BASE_CFN_EVENT, RequestType: 'Create' };

      const promise = handler(event, {});
      await jest.runAllTimersAsync();
      await promise;

      expect(global.fetch).toHaveBeenCalledWith(
        BASE_CFN_EVENT.ResponseURL,
        expect.objectContaining({
          method: 'PUT',
          body: expect.stringContaining('"Status":"SUCCESS"'),
        })
      );
    });
  });

  describe('Update event', () => {
    test('does not call indices.create or indices.delete', async () => {
      const event = {
        ...BASE_CFN_EVENT,
        RequestType: 'Update',
        PhysicalResourceId: 'existing-index',
      };

      await handler(event, {});

      expect(mockIndicesCreate).not.toHaveBeenCalled();
      expect(mockIndicesDelete).not.toHaveBeenCalled();
    });

    test('sends CFn SUCCESS response for Update', async () => {
      const event = {
        ...BASE_CFN_EVENT,
        RequestType: 'Update',
        PhysicalResourceId: 'existing-index',
      };

      await handler(event, {});

      expect(global.fetch).toHaveBeenCalledWith(
        BASE_CFN_EVENT.ResponseURL,
        expect.objectContaining({
          method: 'PUT',
          body: expect.stringContaining('"Status":"SUCCESS"'),
        })
      );
    });
  });

  describe('Delete event', () => {
    test('calls indices.delete with the physical resource ID', async () => {
      const event = {
        ...BASE_CFN_EVENT,
        RequestType: 'Delete',
        PhysicalResourceId: 'index-to-delete',
      };

      await handler(event, {});

      expect(mockIndicesDelete).toHaveBeenCalledTimes(1);
      expect(mockIndicesDelete).toHaveBeenCalledWith({ index: 'index-to-delete' });
    });

    test('sends CFn SUCCESS response after index deletion', async () => {
      const event = {
        ...BASE_CFN_EVENT,
        RequestType: 'Delete',
        PhysicalResourceId: 'index-to-delete',
      };

      await handler(event, {});

      expect(global.fetch).toHaveBeenCalledWith(
        BASE_CFN_EVENT.ResponseURL,
        expect.objectContaining({
          method: 'PUT',
          body: expect.stringContaining('"Status":"SUCCESS"'),
        })
      );
    });
  });

  describe('Error handling', () => {
    test('sends CFn FAILED response when indices.create throws', async () => {
      const event = { ...BASE_CFN_EVENT, RequestType: 'Create' };
      const errorMessage = 'Connection refused';
      mockIndicesCreate.mockRejectedValue(new Error(errorMessage));

      const promise = handler(event, {});
      await jest.runAllTimersAsync();
      await promise;

      expect(global.fetch).toHaveBeenCalledWith(
        BASE_CFN_EVENT.ResponseURL,
        expect.objectContaining({
          method: 'PUT',
          body: expect.stringContaining('"Status":"FAILED"'),
        })
      );
      const callBody = JSON.parse(global.fetch.mock.calls[0][1].body);
      expect(callBody.Reason).toBe(errorMessage);
    });

    test('sends CFn FAILED response when indices.delete throws', async () => {
      const event = {
        ...BASE_CFN_EVENT,
        RequestType: 'Delete',
        PhysicalResourceId: 'index-to-delete',
      };
      mockIndicesDelete.mockRejectedValue(new Error('Delete failed'));

      await handler(event, {});

      const callBody = JSON.parse(global.fetch.mock.calls[0][1].body);
      expect(callBody.Status).toBe('FAILED');
      expect(callBody.Reason).toBe('Delete failed');
    });
  });
});
