"""
AWS client singletons for the query-expansion-rag Lambda function.

Clients are initialized once at module import (Lambda cold start).
botocore automatically refreshes IAM credentials before each API call,
so these singletons are safe for long-lived Lambda containers.
"""

import boto3

bedrock_runtime = boto3.client("bedrock-runtime")
bedrock_agent_runtime = boto3.client("bedrock-agent-runtime")
