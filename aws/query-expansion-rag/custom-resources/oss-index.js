/**
 * OpenSearch Serverless Index Custom Resource Handler
 *
 * Creates, updates, and deletes OpenSearch Serverless vector indexes
 * with Japanese text search (kuromoji) and k-NN vector search support.
 */
const { defaultProvider } = require('@aws-sdk/credential-provider-node');
const { Client } = require('@opensearch-project/opensearch');
const { AwsSigv4Signer } = require('@opensearch-project/opensearch/aws');

// Index creation propagation delay (60 seconds)
const INDEX_PROPAGATION_DELAY_MS = 60 * 1000;

/**
 * Kuromoji analyzer configuration for Japanese text search
 */
const KUROMOJI_ANALYZER_CONFIG = {
  type: 'custom',
  tokenizer: 'kuromoji_tokenizer',
  filter: [
    'kuromoji_baseform',
    'kuromoji_part_of_speech',
    'kuromoji_stemmer',
    'lowercase',
    'ja_stop',
  ],
  char_filter: [
    'kuromoji_iteration_mark',
    'icu_normalizer',
    'html_strip',
  ],
};

/**
 * Wait for specified duration
 */
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Send response to CloudFormation
 */
async function sendCfnResponse(event, status, reason, resourceId) {
  const responseBody = JSON.stringify({
    Status: status,
    Reason: reason,
    PhysicalResourceId: resourceId,
    StackId: event.StackId,
    RequestId: event.RequestId,
    LogicalResourceId: event.LogicalResourceId,
    NoEcho: false,
    Data: {},
  });

  const response = await fetch(event.ResponseURL, {
    method: 'PUT',
    body: responseBody,
    headers: {
      'Content-Type': '',
      'Content-Length': responseBody.length.toString(),
    },
  });

  console.log(response);
  console.log(await response.text());
}

/**
 * Build OpenSearch client for Serverless collection
 */
function createOpenSearchClient(collectionId) {
  const awsRegion = process.env.AWS_DEFAULT_REGION;
  const endpoint = `https://${collectionId}.${awsRegion}.aoss.amazonaws.com`;

  return new Client({
    ...AwsSigv4Signer({
      region: awsRegion,
      service: 'aoss',
      getCredentials: () => defaultProvider()(),
    }),
    node: endpoint,
  });
}

/**
 * Build index mapping configuration
 */
function buildIndexMapping(resourceProps) {
  const { metadataField, textField, vectorField, vectorDimension } = resourceProps;

  return {
    properties: {
      [metadataField]: {
        type: 'text',
        index: false,
      },
      [textField]: {
        type: 'text',
        analyzer: 'custom_kuromoji_analyzer',
      },
      [vectorField]: {
        type: 'knn_vector',
        dimension: Number(vectorDimension),
        method: {
          engine: 'faiss',
          space_type: 'l2',
          name: 'hnsw',
          parameters: {},
        },
      },
    },
  };
}

/**
 * Build index settings configuration
 */
function buildIndexSettings() {
  return {
    index: {
      knn: true,
      analysis: {
        analyzer: {
          custom_kuromoji_analyzer: KUROMOJI_ANALYZER_CONFIG,
        },
      },
    },
  };
}

/**
 * Handle Create request
 */
async function handleCreate(osClient, resourceProps) {
  const indexName = resourceProps.vectorIndexName;

  await osClient.indices.create({
    index: indexName,
    body: {
      mappings: buildIndexMapping(resourceProps),
      settings: buildIndexSettings(),
    },
  });

  // Wait for index to propagate
  await delay(INDEX_PROPAGATION_DELAY_MS);

  return { status: 'SUCCESS', reason: 'Successfully created', resourceId: indexName };
}

/**
 * Handle Update request (not supported)
 */
async function handleUpdate(resourceProps) {
  return {
    status: 'SUCCESS',
    reason: 'Update operation is not supported',
    resourceId: resourceProps.vectorIndexName,
  };
}

/**
 * Handle Delete request
 */
async function handleDelete(osClient, physicalResourceId) {
  await osClient.indices.delete({ index: physicalResourceId });
  return { status: 'SUCCESS', reason: 'Successfully deleted', resourceId: physicalResourceId };
}

/**
 * Lambda handler for CloudFormation custom resource
 */
exports.handler = async (event, context) => {
  console.log(event);

  const resourceProps = event.ResourceProperties;
  const osClient = createOpenSearchClient(resourceProps.collectionId);

  try {
    let result;

    switch (event.RequestType) {
      case 'Create':
        result = await handleCreate(osClient, resourceProps);
        break;
      case 'Update':
        result = await handleUpdate(resourceProps);
        break;
      case 'Delete':
        result = await handleDelete(osClient, event.PhysicalResourceId);
        break;
    }

    await sendCfnResponse(event, result.status, result.reason, result.resourceId);
  } catch (error) {
    console.log('---- Error');
    console.log(error);

    const resourceId = resourceProps.vectorIndexName || event.PhysicalResourceId;
    await sendCfnResponse(event, 'FAILED', error.message, resourceId);
  }
};
