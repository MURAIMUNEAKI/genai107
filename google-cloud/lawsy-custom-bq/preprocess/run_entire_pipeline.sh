#!/bin/bash

# This script automates the entire data pipeline for the law search system.
# It first loads data from XML files into a BigQuery source table,
# and then runs the main pipeline to update the DWH and App layers.

# Exit immediately if a command exits with a non-zero status.
set -e

# --- Configuration ---
if [ "$#" -ne 8 ]; then
    echo "Usage: $0 <project_id> <dataset_id> <source_directory> <gcs_bucket_name> <gcs_blob_name> <region> <connection_name> <date_tag>" >&2
    echo "Example: $0 my-project-id my_dataset_id ./xml_files my_gcs_bucket data.jsonl asia-northeast1 vertex-ai-connection 20250818" >&2
    exit 1
fi

PROJECT_ID=$1
DATASET_ID=$2
SOURCE_DIR=$3
GCS_BUCKET=$4
GCS_BLOB=$5
REGION=$6
CONNECTION_NAME=$7
DATE_TAG=$8

# --- Get the directory of this script ---
SCRIPT_DIR=$(dirname "$0")

# --- Clean up previous run logs to ensure all files are processed for debugging ---
echo "--- Removing previous .processed_files.log to ensure a full run ---"
rm -f "${SCRIPT_DIR}/.processed_files.log"

# --- Step 1: Load data from XML to a new source table in BigQuery ---
echo "--- Running Step 1: load_to_bq.py ---"

# Execute the loading script and capture its standard output, which is the full table ID.
# Informational logs are printed to stderr.
SOURCE_TABLE_ID=$(python3 "${SCRIPT_DIR}/load_to_bq.py" \
    "$PROJECT_ID" \
    "$DATASET_ID" \
    "$SOURCE_DIR" \
    "$GCS_BUCKET" \
    "$GCS_BLOB" \
    "$REGION" \
    "$DATE_TAG")

# Check if the SOURCE_TABLE_ID was captured. If it's empty, it means no files were processed.
if [ -z "$SOURCE_TABLE_ID" ]; then
  echo "Error: No new files found in ${SOURCE_DIR} to process, and no source table was created. Aborting." >&2
  exit 1
fi

echo "Successfully created source table: ${SOURCE_TABLE_ID}"

# --- Step 2: Run the main BigQuery pipeline ---
echo "--- Running Step 2: run_bq_pipeline.py ---"

python3 "${SCRIPT_DIR}/run_bq_pipeline.py" \
    "$PROJECT_ID" \
    "$DATASET_ID" \
    "$SOURCE_TABLE_ID" \
    "$REGION" \
    "$CONNECTION_NAME"

echo "--- Pipeline execution completed successfully! ---"
echo "Please use the 'verification_guide.md' to perform an end-to-end search test."
